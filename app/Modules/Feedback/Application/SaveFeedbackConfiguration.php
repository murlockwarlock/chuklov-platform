<?php

namespace App\Modules\Feedback\Application;

use App\Models\User;
use App\Modules\Feedback\Domain\Models\FeedbackConfiguration;
use App\Modules\Feedback\Domain\Models\FeedbackReviewDestination;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveFeedbackConfiguration
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<array-key, mixed> $reviewDestinations */
    public function handle(
        User $actor,
        bool $enabled,
        int $positiveThreshold,
        bool $lowScoreFeedbackRequired,
        ?string $reviewUrlRu,
        ?string $reviewUrlEn,
        array $reviewDestinations = [],
    ): FeedbackConfiguration {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);

        if ($positiveThreshold < 1 || $positiveThreshold > 10) {
            throw ValidationException::withMessages(['positive_threshold' => 'Порог должен быть от 1 до 10.']);
        }

        $reviewUrlRu = $this->url($reviewUrlRu, 'review_url_ru');
        $reviewUrlEn = $this->url($reviewUrlEn, 'review_url_en');
        $reviewDestinations = $this->destinations($reviewDestinations);

        return DB::transaction(function () use ($organization, $actor, $enabled, $positiveThreshold, $lowScoreFeedbackRequired, $reviewUrlRu, $reviewUrlEn, $reviewDestinations): FeedbackConfiguration {
            $configuration = FeedbackConfiguration::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->first();

            if (! $configuration instanceof FeedbackConfiguration) {
                $configuration = new FeedbackConfiguration;
                $configuration->forceFill(['organization_id' => $organization->getKey()]);
            }

            $configuration->forceFill([
                'enabled' => $enabled,
                'positive_threshold' => $positiveThreshold,
                'low_score_feedback_required' => $lowScoreFeedbackRequired,
                'review_url_ru' => $reviewUrlRu,
                'review_url_en' => $reviewUrlEn,
            ])->save();
            FeedbackReviewDestination::query()
                ->where('organization_id', $organization->getKey())
                ->delete();
            foreach ($reviewDestinations as $destination) {
                $reviewDestination = new FeedbackReviewDestination;
                $reviewDestination->forceFill([
                    'organization_id' => $organization->getKey(),
                    'label' => $destination['label'],
                    'url' => $destination['url'],
                    'is_active' => $destination['is_active'],
                    'sort_order' => $destination['sort_order'],
                ])->save();
            }
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'feedback.configuration.updated',
                targetType: FeedbackConfiguration::class,
                targetId: (string) $configuration->getKey(),
                metadata: [
                    'enabled' => $enabled,
                    'positive_threshold' => $positiveThreshold,
                    'low_score_feedback_required' => $lowScoreFeedbackRequired,
                    'review_url_ru_set' => $reviewUrlRu !== null,
                    'review_url_en_set' => $reviewUrlEn !== null,
                    'review_destinations_count' => count($reviewDestinations),
                ],
            );

            return $configuration->refresh();
        });
    }

    private function url(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 2048) {
            throw ValidationException::withMessages([$field => 'Ссылка слишком длинная.']);
        }

        $parts = parse_url($value);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw ValidationException::withMessages([$field => 'Укажите обычную HTTPS-ссылку.']);
        }

        return $value;
    }

    /** @param array<array-key, mixed> $values
     * @return list<array{label: string, url: string, is_active: bool, sort_order: int}>
     */
    private function destinations(array $values): array
    {
        $destinations = [];
        foreach (array_values($values) as $index => $value) {
            if (! is_array($value)) {
                throw ValidationException::withMessages(['review_destinations' => 'Проверьте площадки для отзывов.']);
            }
            $label = trim((string) ($value['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 160) {
                throw ValidationException::withMessages(['review_destinations' => 'Укажите название площадки.']);
            }
            $url = $this->url(is_string($value['url'] ?? null) ? $value['url'] : null, 'review_destinations');
            if ($url === null) {
                throw ValidationException::withMessages(['review_destinations' => 'Для площадки нужна HTTPS-ссылка.']);
            }
            $sortOrder = filter_var($value['sort_order'] ?? $value['sortOrder'] ?? $index, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($sortOrder === false) {
                throw ValidationException::withMessages(['review_destinations' => 'Порядок площадки указан неверно.']);
            }
            $destinations[] = [
                'label' => $label,
                'url' => $url,
                'is_active' => (bool) ($value['is_active'] ?? $value['isActive'] ?? true),
                'sort_order' => (int) $sortOrder,
            ];
        }

        return $destinations;
    }
}
