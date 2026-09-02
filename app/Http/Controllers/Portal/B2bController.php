<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitB2bLeadRequest;
use App\Modules\B2B\Application\GetPortalB2bRequest;
use App\Modules\B2B\Application\ListB2bSalesCallAvailability;
use App\Modules\B2B\Application\SubmitB2bLead;
use App\Modules\B2B\Domain\Enums\B2bLeadSource;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Content\Application\ContentImageUrlResolver;
use App\Modules\Content\Application\ListPublishedContentSections;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Throwable;

final class B2bController extends Controller
{
    public function __invoke(
        Request $request,
        ClientPortalContext $clientContext,
        OrganizationContext $organizationContext,
        ListPublishedContentSections $sections,
        ContentImageUrlResolver $imageResolver,
        ListB2bSalesCallAvailability $availability,
        GetPortalB2bRequest $currentRequest,
    ): Response {
        try {
            $client = $clientContext->client();
        } catch (LogicException) {
            $client = null;
        }
        $locale = $this->locale($request, $client?->language);
        [$dateFrom, $dateTo] = $this->monthRange(
            $request->query('date_from'),
            $client?->timezone,
        );
        $specialistId = $this->nullablePositiveInteger($request->query('specialist_id'));
        $projection = $client === null
            ? null
            : $availability->handle(
                client: $client,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                specialistId: $specialistId,
                displayTimezone: (string) $client->timezone,
            );
        $content = $sections->handle('b2b')
            ->filter(fn (ContentSection $section): bool => $section->locale === $locale)
            ->map(fn (ContentSection $section): array => [
                'title' => $section->title,
                'body' => $section->body,
                'media' => $imageResolver->resolve($section),
            ])
            ->values()
            ->all();

        return Inertia::render('Portal/B2b', [
            'authenticated' => $client !== null,
            'b2bSpecialistAnswer' => $projection['specialistAnswer'] ?? null,
            'content' => $content,
            'specialists' => $projection['specialists'] ?? [],
            'selectedSpecialistId' => $projection['selectedSpecialistId'] ?? null,
            'availability' => $projection['availability'] ?? null,
            'availabilityRange' => $client === null ? null : [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ],
            'configurationReady' => $projection['configurationReady'] ?? false,
            'configurationIssue' => $projection['configurationIssue'] ?? null,
            'currentRequest' => $client === null ? null : $currentRequest->handle($client),
            'urls' => [
                'answer' => route('portal.profile.b2b-answer'),
                'page' => route('portal.b2b'),
                'submit' => route('portal.b2b.submit'),
                'login' => route('portal.home'),
            ],
        ]);
    }

    public function submit(
        SubmitB2bLeadRequest $request,
        ClientPortalContext $clientContext,
        SubmitB2bLead $submitLead,
    ): RedirectResponse {
        $client = $clientContext->client();
        $validated = $request->validated();

        try {
            $startsAtValue = (string) $validated['starts_at'];
            $startsAtValue = str_ends_with($startsAtValue, 'Z')
                ? substr($startsAtValue, 0, -1).'+00:00'
                : $startsAtValue;
            $startsAt = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i:sP', $startsAtValue);
            $errors = CarbonImmutable::getLastErrors();
            if (! $startsAt instanceof CarbonImmutable
                || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $startsAt->second !== 0
                || $startsAt->microsecond !== 0) {
                throw new \InvalidArgumentException('Invalid date.');
            }
            $startsAt = $startsAt->utc();
        } catch (Throwable) {
            throw ValidationException::withMessages(['starts_at' => 'Choose a valid date and time.']);
        }

        $submitLead->handle(
            actor: $client,
            client: $client,
            specialist: Specialist::query()
                ->where('organization_id', $client->organization_id)
                ->findOrFail((int) $validated['specialist_id']),
            startsAt: $startsAt,
            requestedTimezone: (string) $client->timezone,
            idempotencyKey: (string) $validated['submission_key'],
            source: B2bLeadSource::Portal,
        );

        return to_route('portal.b2b');
    }

    private function locale(Request $request, ?string $language): string
    {
        $language ??= $request->session()->get('portal.locale');

        return str_starts_with(strtolower((string) $language), 'ru') ? 'ru' : 'en';
    }

    /** @return array{0: string, 1: string} */
    private function monthRange(mixed $date, ?string $timezone): array
    {
        try {
            $date = is_string($date) ? CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC') : false;
            $errors = CarbonImmutable::getLastErrors();
            if (! $date instanceof CarbonImmutable
                || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new \InvalidArgumentException('Invalid date.');
            }
        } catch (Throwable) {
            $date = CarbonImmutable::now($timezone ?: 'UTC');
        }

        return [$date->startOfMonth()->toDateString(), $date->endOfMonth()->toDateString()];
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) && (int) $value > 0 && (string) (int) $value === (string) $value
            ? (int) $value
            : null;
    }
}
