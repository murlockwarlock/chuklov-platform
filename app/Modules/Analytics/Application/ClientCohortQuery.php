<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\AnalyticsAggregateDimension;
use App\Modules\Analytics\Application\Data\AnalyticsAggregatePredicate;
use App\Modules\Analytics\Application\Data\ClientCohortFilters;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Analytics\Domain\Enums\PainScoreBasis;
use App\Modules\Analytics\Domain\Models\AnalyticsClientGroup;
use App\Modules\Attribution\Application\AttributionSourceCatalog;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

final readonly class ClientCohortQuery
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private ClientSegmentQuery $segments,
        private AttributionSourceCatalog $sources,
        private PurchaseGroupCatalog $purchaseGroups,
        private ClientAgeRangeCatalog $ageRanges,
    ) {}

    /** @return Builder<Client> */
    public function for(
        User $actor,
        ClientCohortFilters $filters,
        ?DashboardPeriod $period = null,
    ): Builder {
        $this->authorizer->authorize(
            $actor,
            $this->context->organization(),
            OrganizationPermission::ViewClients,
        );

        return $this->query($filters, $period);
    }

    /** @return Builder<Client> */
    public function query(
        ClientCohortFilters $filters,
        ?DashboardPeriod $period = null,
    ): Builder {
        $organizationId = $this->context->id();
        $query = Client::query()->where('clients.organization_id', $organizationId);

        if ($filters->clientIds !== []) {
            $query->whereIn('clients.id', $filters->clientIds);
        }
        if ($filters->excludeClientIds !== []) {
            $query->whereNotIn('clients.id', $filters->excludeClientIds);
        }
        if ($filters->archived !== null) {
            $filters->archived
                ? $query->whereNotNull('clients.archived_at')
                : $query->whereNull('clients.archived_at');
        }
        if ($filters->blacklist !== null) {
            $blacklist = fn (Builder $restriction): Builder => $restriction
                ->whereColumn('client_booking_restrictions.organization_id', 'clients.organization_id')
                ->where('client_booking_restrictions.restriction_type', 'blacklist')
                ->whereNull('client_booking_restrictions.unblocked_at');
            $filters->blacklist ? $query->whereHas('bookingRestrictions', $blacklist) : $query->whereDoesntHave('bookingRestrictions', $blacklist);
        }
        if ($filters->segments !== []) {
            $query->where(function (Builder $branch) use ($filters): void {
                foreach ($filters->segments as $index => $segment) {
                    $apply = function (Builder $segmentQuery) use ($segment): void {
                        $this->segments->apply(
                            $segmentQuery,
                            ClientSegment::tryFrom($segment) ?? throw new \InvalidArgumentException('The client segment is invalid.'),
                        );
                    };

                    if ($index === 0) {
                        $branch->where($apply);
                    } else {
                        $branch->orWhere($apply);
                    }
                }
            });
        }
        if ($filters->savedGroupIds !== []) {
            $groups = AnalyticsClientGroup::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $filters->savedGroupIds)
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->get();
            if ($groups->count() !== count($filters->savedGroupIds)) {
                throw new \InvalidArgumentException('Одна из выбранных групп клиентов больше не активна. Выберите группы заново.');
            }
            $query->where(function (Builder $branch) use ($groups, $organizationId, $period): void {
                foreach ($groups as $group) {
                    if ($group->group_type === 'dynamic') {
                        $branch->orWhereIn(
                            'clients.id',
                            $this->query(ClientCohortFilters::fromArray((array) $group->rule_definition), $period)->select('clients.id'),
                        );

                        continue;
                    }
                    $branch->orWhereExists(function ($subquery) use ($organizationId, $group): void {
                        $subquery->selectRaw('1')
                            ->from('analytics_client_group_members as group_members')
                            ->whereColumn('group_members.client_id', 'clients.id')
                            ->where('group_members.organization_id', $organizationId)
                            ->where('group_members.analytics_client_group_id', $group->getKey());
                    });
                }
            });
        }
        if ($filters->genders !== []) {
            $query->whereIn('clients.gender', $filters->genders);
        }
        if ($filters->countries !== []) {
            $query->whereIn('clients.country', $filters->countries);
        }
        if ($filters->children !== null) {
            $this->applyAgeRange($query, $filters->children ? null : 18, $filters->children ? 17 : null, $period);
        } elseif ($filters->ageMin !== null || $filters->ageMax !== null) {
            $this->applyAgeRange($query, $filters->ageMin, $filters->ageMax, $period);
        }
        if ($filters->sourceKeys !== []) {
            $this->applySources($query, $filters->sourceKeys, $organizationId);
        }
        if ($filters->problemTagIds !== []) {
            $this->applyProblemTags($query, $filters->problemTagIds, $filters->problemTagMode, $organizationId, $period);
        }
        if ($filters->excludeProblemTagIds !== []) {
            $this->applyProblemTags($query, $filters->excludeProblemTagIds, 'exclude', $organizationId, $period);
        }
        if ($filters->feedbackThemeIds !== []) {
            $query->whereExists(function ($subquery) use ($organizationId, $filters, $period): void {
                $subquery->selectRaw('1')
                    ->from('feedback_submissions as submissions')
                    ->join('feedback_submission_themes as links', function (JoinClause $join): void {
                        $join->on('links.organization_id', '=', 'submissions.organization_id')
                            ->on('links.feedback_submission_id', '=', 'submissions.id');
                    })
                    ->whereColumn('submissions.client_id', 'clients.id')
                    ->where('submissions.organization_id', $organizationId)
                    ->whereIn('links.feedback_theme_id', $filters->feedbackThemeIds);
                $this->applyPeriod($subquery, 'submissions.submitted_at', $period);
            });
        }
        if ($filters->goalTagIds !== []) {
            $query->whereExists(function ($subquery) use ($organizationId, $filters, $period): void {
                $subquery->selectRaw('1')
                    ->from('tracker_tasks as tasks')
                    ->join('tracker_task_goal_tags as links', function (JoinClause $join): void {
                        $join->on('links.organization_id', '=', 'tasks.organization_id')
                            ->on('links.tracker_task_id', '=', 'tasks.id');
                    })
                    ->whereColumn('tasks.client_id', 'clients.id')
                    ->where('tasks.organization_id', $organizationId)
                    ->whereIn('links.tracker_goal_tag_id', $filters->goalTagIds);
                if ($period !== null) {
                    $subquery
                        ->where('tasks.starts_on', '>=', $period->startDate)
                        ->where('tasks.starts_on', '<=', $period->endDate);
                }
            });
        }
        if ($filters->serviceIds !== [] || $filters->serviceCategories !== [] || $filters->specialistIds !== [] || $filters->bookingStatuses !== []) {
            $query->whereHas('bookings', function (Builder $booking) use ($filters, $period): void {
                $booking->whereColumn('bookings.organization_id', 'clients.organization_id')
                    ->when($filters->serviceIds !== [], fn (Builder $query): Builder => $query->whereIn('bookings.service_id', $filters->serviceIds))
                    ->when($filters->serviceCategories !== [], fn (Builder $query): Builder => $query->whereHas('service', fn (Builder $service): Builder => $service->whereIn('services.category', $filters->serviceCategories)))
                    ->when($filters->specialistIds !== [], fn (Builder $query): Builder => $query->whereIn('bookings.specialist_id', $filters->specialistIds))
                    ->when($filters->bookingStatuses !== [], fn (Builder $query): Builder => $query->whereIn('bookings.status', $filters->bookingStatuses));
                $this->applyPeriod($booking, 'bookings.starts_at', $period);
            });
        }
        if ($filters->bookingEventTypes !== []) {
            $query->whereExists(function ($event) use ($filters, $period): void {
                $event->selectRaw('1')
                    ->from('booking_events as cohort_events')
                    ->join('bookings as cohort_bookings', function (JoinClause $join): void {
                        $join->on('cohort_bookings.organization_id', '=', 'cohort_events.organization_id')
                            ->on('cohort_bookings.id', '=', 'cohort_events.booking_id');
                    })
                    ->whereColumn('cohort_bookings.client_id', 'clients.id')
                    ->whereColumn('cohort_events.organization_id', 'clients.organization_id')
                    ->whereIn('cohort_events.event_type', $filters->bookingEventTypes);
                $this->applyPeriod($event, 'cohort_events.occurred_at', $period);
            });
        }
        if ($filters->completedVisitsMin !== null || $filters->completedVisitsMax !== null) {
            $completed = fn (Builder $booking): Builder => $booking
                ->whereColumn('bookings.organization_id', 'clients.organization_id')
                ->where('bookings.status', BookingStatus::Completed->value);
            if ($period !== null) {
                $completed = fn (Builder $booking): Builder => $completed($booking)
                    ->where('bookings.starts_at', '>=', $period->startUtc)
                    ->where('bookings.starts_at', '<', $period->endUtc);
            }
            if ($filters->completedVisitsMin !== null) {
                $query->whereHas('bookings', $completed, '>=', $filters->completedVisitsMin);
            }
            if ($filters->completedVisitsMax !== null) {
                $query->whereHas('bookings', $completed, '<=', $filters->completedVisitsMax);
            }
        }
        if ($filters->lastVisitFrom !== null || $filters->lastVisitTo !== null) {
            $timezone = $this->context->organization()->defaultTimezone();
            if ($filters->lastVisitFrom !== null) {
                $fromDate = CarbonImmutable::createFromFormat('!Y-m-d', $filters->lastVisitFrom, $timezone);
                if (! $fromDate instanceof CarbonImmutable) {
                    throw new \InvalidArgumentException('Дата последнего визита указана неверно.');
                }
                $this->applyLastVisitBoundary($query, $fromDate->startOfDay()->utc(), '>=', $organizationId, $period);
            }
            if ($filters->lastVisitTo !== null) {
                $toDate = CarbonImmutable::createFromFormat('!Y-m-d', $filters->lastVisitTo, $timezone);
                if (! $toDate instanceof CarbonImmutable) {
                    throw new \InvalidArgumentException('Дата последнего визита указана неверно.');
                }
                $this->applyLastVisitBoundary($query, $toDate->addDay()->startOfDay()->utc(), '<', $organizationId, $period);
            }
        }
        if ($filters->purchaseTypes !== [] || $filters->purchaseStatuses !== []) {
            if ($filters->purchaseTypes !== []) {
                $activePurchaseTypes = array_keys($this->purchaseGroups->options($organizationId));
                if (array_diff($filters->purchaseTypes, $activePurchaseTypes) !== []) {
                    throw new \InvalidArgumentException('Одна из выбранных групп покупок больше не активна. Выберите группы заново.');
                }
            }
            $purchaseCatalogTypes = $this->purchaseGroups->catalogTypesFor($organizationId, $filters->purchaseTypes);
            $query->whereHas('purchases', function (Builder $purchase) use ($filters, $period, $purchaseCatalogTypes): void {
                $purchase->whereColumn('commerce_purchases.organization_id', 'clients.organization_id')
                    ->when($filters->purchaseStatuses !== [], fn (Builder $query): Builder => $query->whereIn('commerce_purchases.status', $filters->purchaseStatuses));
                $this->applyPeriod($purchase, 'commerce_purchases.created_at', $period);
                if ($filters->purchaseTypes !== []) {
                    $purchase->whereHas('items', function (Builder $item) use ($purchaseCatalogTypes): void {
                        $item->where(function (Builder $query) use ($purchaseCatalogTypes): void {
                            foreach ($purchaseCatalogTypes as $type) {
                                $query->orWhereJsonContains('commerce_purchase_items.product_snapshot->catalog_type', $type);
                            }
                        });
                    });
                }
            });
        }
        if ($filters->hasPainScore !== null || $filters->painScoreMin !== null || $filters->painScoreMax !== null) {
            $this->applyPainScore($query, $filters, $organizationId, $period);
        }
        if ($filters->satisfactionMin !== null || $filters->satisfactionMax !== null) {
            $query->whereHas('feedbackSubmissions', function (Builder $feedback) use ($filters, $period): void {
                $feedback->whereColumn('feedback_submissions.organization_id', 'clients.organization_id')
                    ->when($filters->satisfactionMin !== null, fn (Builder $query): Builder => $query->where('feedback_submissions.score', '>=', $filters->satisfactionMin))
                    ->when($filters->satisfactionMax !== null, fn (Builder $query): Builder => $query->where('feedback_submissions.score', '<=', $filters->satisfactionMax));
                $this->applyPeriod($feedback, 'feedback_submissions.submitted_at', $period);
            });
        }

        return $query;
    }

    public function previewCount(User $actor, ClientCohortFilters $filters, ?DashboardPeriod $period = null): int
    {
        return $this->for($actor, $filters, $period)->count('clients.id');
    }

    public function constrain(
        QueryBuilder $query,
        string $clientColumn,
        ?ClientCohortFilters $filters,
        ?DashboardPeriod $period = null,
    ): void {
        if ($filters === null) {
            return;
        }

        $query->whereIn(
            $clientColumn,
            $this->query($filters, $period)->select('clients.id')->toBase(),
        );
    }

    /** @param Builder<Client> $query */
    public function constrainAggregate(
        Builder $query,
        AnalyticsAggregatePredicate $predicate,
        ?DashboardPeriod $period = null,
    ): void {
        $organizationId = $this->context->id();
        $value = $predicate->value;

        switch ($predicate->dimension) {
            case AnalyticsAggregateDimension::ProblemTag:
                if (! is_int($value) || $value < 1) {
                    throw new \InvalidArgumentException('Категория проблемы недоступна для детализации.');
                }
                $this->applyProblemTags($query, [$value], 'all', $organizationId, $period);

                return;
            case AnalyticsAggregateDimension::FeedbackTheme:
                if (! is_int($value) || $value < 1) {
                    throw new \InvalidArgumentException('Тема обратной связи недоступна для детализации.');
                }
                $query->whereExists(function (QueryBuilder $subquery) use ($organizationId, $period, $value): void {
                    $subquery->selectRaw('1')
                        ->from('feedback_submissions as aggregate_submissions')
                        ->join('feedback_submission_themes as aggregate_links', function (JoinClause $join): void {
                            $join->on('aggregate_links.organization_id', '=', 'aggregate_submissions.organization_id')
                                ->on('aggregate_links.feedback_submission_id', '=', 'aggregate_submissions.id');
                        })
                        ->whereColumn('aggregate_submissions.client_id', 'clients.id')
                        ->where('aggregate_submissions.organization_id', $organizationId)
                        ->where('aggregate_links.feedback_theme_id', $value);
                    $this->applyPeriod($subquery, 'aggregate_submissions.submitted_at', $period);
                });

                return;
            case AnalyticsAggregateDimension::GoalTag:
                if (! is_int($value) || $value < 1) {
                    throw new \InvalidArgumentException('Категория цели недоступна для детализации.');
                }
                $query->whereExists(function (QueryBuilder $subquery) use ($organizationId, $period, $value): void {
                    $subquery->selectRaw('1')
                        ->from('tracker_tasks as aggregate_tasks')
                        ->join('tracker_task_goal_tags as aggregate_links', function (JoinClause $join): void {
                            $join->on('aggregate_links.organization_id', '=', 'aggregate_tasks.organization_id')
                                ->on('aggregate_links.tracker_task_id', '=', 'aggregate_tasks.id');
                        })
                        ->whereColumn('aggregate_tasks.client_id', 'clients.id')
                        ->where('aggregate_tasks.organization_id', $organizationId)
                        ->where('aggregate_links.tracker_goal_tag_id', $value);
                    if ($period !== null) {
                        $subquery
                            ->where('aggregate_tasks.starts_on', '>=', $period->startDate)
                            ->where('aggregate_tasks.starts_on', '<=', $period->endDate);
                    }
                });

                return;
            case AnalyticsAggregateDimension::Service:
                if (! is_int($value) || $value < 1) {
                    throw new \InvalidArgumentException('Услуга недоступна для детализации.');
                }
                $query->whereHas('bookings', function (Builder $booking) use ($organizationId, $period, $value): void {
                    $booking->whereColumn('bookings.organization_id', 'clients.organization_id')
                        ->where('bookings.organization_id', $organizationId)
                        ->where('bookings.service_id', $value)
                        ->where('bookings.status', BookingStatus::Completed->value);
                    $this->applyPeriod($booking, 'bookings.starts_at', $period);
                });

                return;
            case AnalyticsAggregateDimension::Specialist:
                if (! is_int($value) || $value < 1) {
                    throw new \InvalidArgumentException('Специалист недоступен для детализации.');
                }
                $query->whereHas('bookings', function (Builder $booking) use ($organizationId, $period, $value): void {
                    $booking->whereColumn('bookings.organization_id', 'clients.organization_id')
                        ->where('bookings.organization_id', $organizationId)
                        ->where('bookings.specialist_id', $value)
                        ->where('bookings.status', BookingStatus::Completed->value);
                    $this->applyPeriod($booking, 'bookings.starts_at', $period);
                });

                return;
            case AnalyticsAggregateDimension::AcquisitionSource:
                if (! is_string($value) || trim($value) === '') {
                    throw new \InvalidArgumentException('Источник привлечения недоступен для детализации.');
                }
                if ($period !== null) {
                    $query->where('clients.created_at', '>=', $period->startUtc)
                        ->where('clients.created_at', '<', $period->endUtc);
                }
                $this->applySources($query, [$value], $organizationId);

                return;
            case AnalyticsAggregateDimension::AcquisitionUnknown:
                if ($period !== null) {
                    $query->where('clients.created_at', '>=', $period->startUtc)
                        ->where('clients.created_at', '<', $period->endUtc);
                }
                $this->applyUnknownSource($query, $organizationId);

                return;
            case AnalyticsAggregateDimension::PurchaseGroup:
                if (! is_string($value) || trim($value) === '') {
                    throw new \InvalidArgumentException('Группа покупок недоступна для детализации.');
                }
                $purchaseCatalogTypes = $this->purchaseGroups->catalogTypesFor($organizationId, [$value], false);
                $query->whereHas('purchases', function (Builder $purchase) use ($organizationId, $period, $purchaseCatalogTypes): void {
                    $purchase->whereColumn('commerce_purchases.organization_id', 'clients.organization_id')
                        ->where('commerce_purchases.organization_id', $organizationId);
                    $this->applyPeriod($purchase, 'commerce_purchases.created_at', $period);
                    $purchase->whereHas('items', function (Builder $item) use ($purchaseCatalogTypes): void {
                        $item->where(function (Builder $types) use ($purchaseCatalogTypes): void {
                            foreach ($purchaseCatalogTypes as $type) {
                                $types->orWhereJsonContains('commerce_purchase_items.product_snapshot->catalog_type', $type);
                            }
                        });
                    });
                });

                return;
            case AnalyticsAggregateDimension::BookingEvent:
                if (! is_string($value) || trim($value) === '') {
                    throw new \InvalidArgumentException('Событие записи недоступно для детализации.');
                }
                $query->whereExists(function (QueryBuilder $subquery) use ($organizationId, $period, $value): void {
                    $subquery->selectRaw('1')
                        ->from('booking_events as aggregate_events')
                        ->join('bookings as aggregate_bookings', function (JoinClause $join): void {
                            $join->on('aggregate_bookings.organization_id', '=', 'aggregate_events.organization_id')
                                ->on('aggregate_bookings.id', '=', 'aggregate_events.booking_id');
                        })
                        ->whereColumn('aggregate_bookings.client_id', 'clients.id')
                        ->where('aggregate_events.organization_id', $organizationId)
                        ->where('aggregate_events.event_type', $value);
                    $this->applyPeriod($subquery, 'aggregate_events.occurred_at', $period);
                });

                return;
            case AnalyticsAggregateDimension::Segment:
                if (! is_string($value) || ! ClientSegment::tryFrom($value) instanceof ClientSegment) {
                    throw new \InvalidArgumentException('Категория клиентов недоступна для детализации.');
                }
                $this->segments->apply($query, ClientSegment::from($value));

                return;
            case AnalyticsAggregateDimension::AgeRange:
                if (! is_int($predicate->minimum) || $predicate->minimum < 0 || ($predicate->maximum !== null && $predicate->maximum < $predicate->minimum)) {
                    throw new \InvalidArgumentException('Возрастной диапазон недоступен для детализации.');
                }
                $this->applyAgeRange($query, $predicate->minimum, $predicate->maximum, $period);

                return;
            case AnalyticsAggregateDimension::AgeUnknown:
                $this->applyUnknownAge($query, $organizationId, $period);

                return;
            case AnalyticsAggregateDimension::Sex:
                if (! is_string($value) || ! in_array($value, ['MALE', 'FEMALE'], true)) {
                    throw new \InvalidArgumentException('Пол недоступен для детализации.');
                }
                $query->where('clients.gender', $value);

                return;
            case AnalyticsAggregateDimension::SexUnknown:
                $query->where(function (Builder $unknown): void {
                    $unknown->whereNull('clients.gender')->orWhereNotIn('clients.gender', ['MALE', 'FEMALE']);
                });

                return;
            case AnalyticsAggregateDimension::Children:
                if (! is_string($value)) {
                    throw new \InvalidArgumentException('Возрастная категория недоступна для детализации.');
                }
                if ($value === 'children') {
                    $this->applyAgeRange($query, 0, 17, $period);

                    return;
                }
                if ($value === 'adult') {
                    $this->applyAgeRange($query, 18, null, $period);

                    return;
                }
                throw new \InvalidArgumentException('Возрастная категория недоступна для детализации.');
            case AnalyticsAggregateDimension::ChildrenUnknown:
                $query->whereNull('clients.birth_date');

                return;
            case AnalyticsAggregateDimension::Country:
                if (! is_string($value) || trim($value) === '') {
                    throw new \InvalidArgumentException('Страна недоступна для детализации.');
                }
                $query->whereRaw("TRIM(COALESCE(clients.country, '')) = ?", [trim($value)]);

                return;
            case AnalyticsAggregateDimension::CountryUnknown:
                $query->where(function (Builder $unknown): void {
                    $unknown->whereNull('clients.country')->orWhereRaw("TRIM(clients.country) = ''");
                });

                return;
        }
    }

    /** @param Builder<Client> $query */
    private function applyAgeRange(Builder $query, ?int $minimum, ?int $maximum, ?DashboardPeriod $period): void
    {
        $this->applyAgeRangeConditions($query, $minimum, $maximum, $this->ageReference($period));
    }

    /** @param Builder<Client> $query */
    private function applyAgeRangeConditions(Builder $query, ?int $minimum, ?int $maximum, CarbonImmutable $reference): void
    {
        if ($minimum !== null) {
            $query->whereDate('clients.birth_date', '<=', $reference->subYears($minimum)->toDateString());
        }
        if ($maximum !== null) {
            $query->whereDate('clients.birth_date', '>', $reference->subYears($maximum + 1)->toDateString());
        }
        $query->whereNotNull('clients.birth_date');
    }

    private function ageReference(?DashboardPeriod $period): CarbonImmutable
    {
        return $period === null
            ? CarbonImmutable::now($this->context->organization()->defaultTimezone())->startOfDay()
            : $period->endUtc->setTimezone($period->timezone)->subDay()->startOfDay();
    }

    /** @param Builder<Client> $query */
    private function applyUnknownAge(Builder $query, int $organizationId, ?DashboardPeriod $period): void
    {
        $ranges = $this->ageRanges->rows($organizationId, true);
        $reference = $this->ageReference($period);
        $query->where(function (Builder $unknown) use ($ranges, $reference): void {
            $unknown->whereNull('clients.birth_date');
            if ($ranges === []) {
                return;
            }

            $unknown->orWhere(function (Builder $outside) use ($ranges, $reference): void {
                $outside->whereNotNull('clients.birth_date');
                foreach ($ranges as $range) {
                    $outside->whereNot(function (Builder $knownRange) use ($range, $reference): void {
                        $this->applyAgeRangeConditions($knownRange, $range['min'], $range['max'], $reference);
                    });
                }
            });
        });
    }

    /** @param Builder<Client> $query */
    private function applyUnknownSource(Builder $query, int $organizationId): void
    {
        $query->where(function (Builder $unknown) use ($organizationId): void {
            $unknown->whereDoesntHave('attribution', function (Builder $attribution) use ($organizationId): void {
                $attribution->where('client_attributions.organization_id', $organizationId);
            })->orWhereHas('attribution', function (Builder $attribution) use ($organizationId): void {
                $attribution->where('client_attributions.organization_id', $organizationId)
                    ->where(function (Builder $kind): void {
                        $kind->whereNull('client_attributions.source_type')
                            ->orWhere(function (Builder $direct): void {
                                $direct->whereIn('client_attributions.source_type', ['legacy', 'source', 'manual'])
                                    ->whereRaw("NULLIF(TRIM(client_attributions.source), '') IS NULL");
                            })
                            ->orWhere(function (Builder $utm): void {
                                $utm->where('client_attributions.source_type', 'utm')
                                    ->whereRaw("NULLIF(TRIM(client_attributions.utm_source), '') IS NULL");
                            })
                            ->orWhereNotIn('client_attributions.source_type', ['legacy', 'source', 'manual', 'referral', 'utm']);
                    });
            });
        });
    }

    /** @param Builder<Client> $query
     * @param  list<string>  $sources
     */
    private function applySources(Builder $query, array $sources, int $organizationId): void
    {
        $values = [];
        foreach ($this->sources->rows($organizationId) as $mapping) {
            $mappingValues = [
                $mapping['source_key'],
                $mapping['label'],
                ...$mapping['aliases'],
            ];
            foreach ($sources as $source) {
                if (in_array(mb_strtolower(trim($source)), array_map(static fn (string $value): string => mb_strtolower(trim($value)), $mappingValues), true)) {
                    $values = [...$values, ...$mappingValues];
                }
            }
        }
        $sources = array_values(array_unique(array_map(
            static fn (string $source): string => mb_strtolower(trim($source)),
            $values === [] ? $sources : $values,
        )));
        $query->where(function (Builder $branch) use ($sources, $organizationId): void {
            foreach ($sources as $source) {
                $branch->orWhereRaw('LOWER(COALESCE(clients.lead_source, \'\')) = ?', [$source])
                    ->orWhereHas('attribution', function (Builder $attribution) use ($source, $organizationId): void {
                        $attribution->where(function (Builder $query) use ($source): void {
                            $query->whereRaw('LOWER(COALESCE(client_attributions.source, \'\')) = ?', [$source])
                                ->orWhereRaw('LOWER(COALESCE(client_attributions.utm_source, \'\')) = ?', [$source]);
                        })->where('client_attributions.organization_id', $organizationId);
                    });
            }
        });
    }

    /** @param Builder<Client> $query
     * @param  list<int>  $tagIds
     */
    private function applyProblemTags(Builder $query, array $tagIds, string $mode, int $organizationId, ?DashboardPeriod $period): void
    {
        $matches = function (Builder $branch, int $tagId, bool $or = false) use ($organizationId, $period): void {
            $constraint = function (Builder $exists) use ($tagId, $organizationId, $period): void {
                $exists->whereExists(function ($subquery) use ($tagId, $organizationId): void {
                    $subquery->selectRaw('1')
                        ->from('medical_profile_pains as pains')
                        ->join('medical_profile_pain_tags as tags', 'tags.medical_profile_pain_id', '=', 'pains.id')
                        ->whereColumn('pains.client_id', 'clients.id')
                        ->where('pains.organization_id', $organizationId)
                        ->where('tags.organization_id', $organizationId)
                        ->where('tags.medical_problem_tag_id', $tagId);
                })->orWhereExists(function ($subquery) use ($tagId, $organizationId, $period): void {
                    $subquery->selectRaw('1')
                        ->from('medical_session_pains as pains')
                        ->join('medical_session_pain_tags as tags', 'tags.medical_session_pain_id', '=', 'pains.id')
                        ->whereColumn('pains.client_id', 'clients.id')
                        ->where('pains.organization_id', $organizationId)
                        ->where('tags.organization_id', $organizationId)
                        ->where('tags.medical_problem_tag_id', $tagId);
                    if ($period !== null) {
                        $subquery->whereExists(function ($session) use ($organizationId, $period): void {
                            $session->selectRaw('1')
                                ->from('medical_sessions')
                                ->whereColumn('medical_sessions.id', 'pains.medical_session_id')
                                ->where('medical_sessions.organization_id', $organizationId)
                                ->where('medical_sessions.occurred_at', '>=', $period->startUtc)
                                ->where('medical_sessions.occurred_at', '<', $period->endUtc);
                        });
                    }
                });
            };

            if ($or) {
                $branch->orWhere($constraint);

                return;
            }

            $branch->where($constraint);
        };

        if ($mode === 'exclude') {
            foreach ($tagIds as $tagId) {
                $query->whereNotExists(function ($subquery) use ($tagId, $organizationId): void {
                    $subquery->selectRaw('1')
                        ->from('medical_profile_pain_tags as tags')
                        ->join('medical_profile_pains as pains', 'pains.id', '=', 'tags.medical_profile_pain_id')
                        ->whereColumn('pains.client_id', 'clients.id')
                        ->where('pains.organization_id', $organizationId)
                        ->where('tags.organization_id', $organizationId)
                        ->where('tags.medical_problem_tag_id', $tagId);
                })->whereNotExists(function ($subquery) use ($tagId, $organizationId, $period): void {
                    $subquery->selectRaw('1')
                        ->from('medical_session_pain_tags as tags')
                        ->join('medical_session_pains as pains', 'pains.id', '=', 'tags.medical_session_pain_id')
                        ->whereColumn('pains.client_id', 'clients.id')
                        ->where('pains.organization_id', $organizationId)
                        ->where('tags.organization_id', $organizationId)
                        ->where('tags.medical_problem_tag_id', $tagId);
                    if ($period !== null) {
                        $subquery->whereExists(function ($session) use ($organizationId, $period): void {
                            $session->selectRaw('1')
                                ->from('medical_sessions')
                                ->whereColumn('medical_sessions.id', 'pains.medical_session_id')
                                ->where('medical_sessions.organization_id', $organizationId)
                                ->where('medical_sessions.occurred_at', '>=', $period->startUtc)
                                ->where('medical_sessions.occurred_at', '<', $period->endUtc);
                        });
                    }
                });
            }

            return;
        }

        if ($mode === 'all') {
            foreach ($tagIds as $tagId) {
                $matches($query, $tagId);
            }

            return;
        }

        $query->where(function (Builder $branch) use ($tagIds, $matches): void {
            foreach ($tagIds as $index => $tagId) {
                $matches($branch, $tagId, $index > 0);
            }
        });
    }

    /** @param Builder<Client> $query */
    private function applyPainScore(Builder $query, ClientCohortFilters $filters, int $organizationId, ?DashboardPeriod $period): void
    {
        $sources = $this->painScoreSources($filters->painScoreBasis);
        if ($filters->hasPainScore === true) {
            $query->where(function (Builder $branch) use ($organizationId, $period, $sources): void {
                foreach ($sources as [$table, $column]) {
                    $this->applyPainScoreExists($branch, $table, $column, $organizationId, $period);
                }
            });
        } elseif ($filters->hasPainScore === false) {
            foreach ($sources as [$table, $column]) {
                $this->applyPainScoreNotExists($query, $table, $column, $organizationId, $period);
            }
        }

        if ($filters->painScoreMin === null && $filters->painScoreMax === null) {
            return;
        }

        $query->where(function (Builder $branch) use ($filters, $organizationId, $period, $sources): void {
            foreach ($sources as [$table, $column]) {
                $this->applyPainScoreExists($branch, $table, $column, $organizationId, $period, $filters->painScoreMin, $filters->painScoreMax);
            }
        });
    }

    /** @return list<array{0: string, 1: string}> */
    private function painScoreSources(PainScoreBasis $basis): array
    {
        return match ($basis) {
            PainScoreBasis::Any => [
                ['medical_profile_pains', 'score'],
                ['medical_session_pains', 'before_score'],
                ['medical_session_pains', 'after_score'],
            ],
            PainScoreBasis::Profile => [['medical_profile_pains', 'score']],
            PainScoreBasis::SessionBefore => [['medical_session_pains', 'before_score']],
            PainScoreBasis::SessionAfter => [['medical_session_pains', 'after_score']],
        };
    }

    /** @param Builder<Client> $query */
    private function applyPainScoreExists(
        Builder $query,
        string $table,
        string $column,
        int $organizationId,
        ?DashboardPeriod $period,
        ?int $minimum = null,
        ?int $maximum = null,
    ): void {
        $query->orWhereExists(function ($subquery) use ($table, $column, $organizationId, $period, $minimum, $maximum): void {
            $subquery->selectRaw('1')
                ->from($table.' as pains')
                ->whereColumn('pains.client_id', 'clients.id')
                ->where('pains.organization_id', $organizationId)
                ->whereNotNull('pains.'.$column)
                ->when($minimum !== null, fn ($query) => $query->where('pains.'.$column, '>=', $minimum))
                ->when($maximum !== null, fn ($query) => $query->where('pains.'.$column, '<=', $maximum));
            if ($table === 'medical_session_pains') {
                $this->applySessionPeriod($subquery, $organizationId, $period, 'pains.medical_session_id');
            }
        });
    }

    /** @param Builder<Client> $query */
    private function applyPainScoreNotExists(Builder $query, string $table, string $column, int $organizationId, ?DashboardPeriod $period = null): void
    {
        $query->whereNotExists(function ($subquery) use ($table, $column, $organizationId, $period): void {
            $subquery->selectRaw('1')
                ->from($table.' as pains')
                ->whereColumn('pains.client_id', 'clients.id')
                ->where('pains.organization_id', $organizationId)
                ->whereNotNull('pains.'.$column);
            if ($table === 'medical_session_pains') {
                $this->applySessionPeriod($subquery, $organizationId, $period, 'pains.medical_session_id');
            }
        });
    }

    private function applySessionPeriod(QueryBuilder $query, int $organizationId, ?DashboardPeriod $period, string $sessionColumn): void
    {
        if ($period === null) {
            return;
        }

        $query->whereExists(function ($session) use ($organizationId, $period, $sessionColumn): void {
            $session->selectRaw('1')
                ->from('medical_sessions')
                ->whereColumn('medical_sessions.id', $sessionColumn)
                ->where('medical_sessions.organization_id', $organizationId)
                ->where('medical_sessions.occurred_at', '>=', $period->startUtc)
                ->where('medical_sessions.occurred_at', '<', $period->endUtc);
        });
    }

    /** @param Builder<Model>|QueryBuilder $query */
    private function applyPeriod(Builder|QueryBuilder $query, string $column, ?DashboardPeriod $period): void
    {
        if ($period === null) {
            return;
        }

        $query->where($column, '>=', $period->startUtc)
            ->where($column, '<', $period->endUtc);
    }

    /** @param Builder<Client> $query */
    private function applyLastVisitBoundary(Builder $query, CarbonImmutable $boundary, string $operator, int $organizationId, ?DashboardPeriod $period): void
    {
        $query->whereExists(function (QueryBuilder $lastVisit) use ($boundary, $operator, $organizationId, $period): void {
            $lastVisit->selectRaw('1')
                ->from('bookings as last_bookings')
                ->whereColumn('last_bookings.client_id', 'clients.id')
                ->whereColumn('last_bookings.organization_id', 'clients.organization_id')
                ->where('last_bookings.organization_id', $organizationId)
                ->where('last_bookings.status', BookingStatus::Completed->value);
            if ($period !== null) {
                $lastVisit->where('last_bookings.starts_at', '>=', $period->startUtc)
                    ->where('last_bookings.starts_at', '<', $period->endUtc);
            }
            $lastVisit->groupBy('last_bookings.client_id');
            if ($operator === '>=') {
                $lastVisit->havingRaw('MAX(last_bookings.starts_at) >= ?', [$boundary]);

                return;
            }
            $lastVisit->havingRaw('MAX(last_bookings.starts_at) < ?', [$boundary]);
        });
    }
}
