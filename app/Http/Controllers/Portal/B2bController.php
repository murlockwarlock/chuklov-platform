<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitB2bLeadRequest;
use App\Modules\B2B\Application\SubmitB2bLead;
use App\Modules\B2B\Domain\Enums\B2bLeadSource;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientProfile;
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
    ): Response {
        try {
            $client = $clientContext->client();
        } catch (LogicException) {
            $client = null;
        }
        $locale = $this->locale($request, $client?->language);
        $profile = $client === null
            ? null
            : BroadcastClientProfile::query()
                ->where('organization_id', $organizationContext->id())
                ->where('client_id', $client->getKey())
                ->first();
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
            'b2bSpecialistAnswer' => $client === null
                ? null
                : $profile?->getRawOriginal('b2b_specialist_answer'),
            'content' => $content,
            'specialists' => $client === null
                ? []
                : Specialist::query()
                    ->where('organization_id', $organizationContext->id())
                    ->where('is_active', true)
                    ->orderBy('display_name')
                    ->limit(100)
                    ->get(['id', 'display_name'])
                    ->map(static fn (Specialist $specialist): array => [
                        'id' => $specialist->getKey(),
                        'displayName' => $specialist->display_name,
                    ])
                    ->values()
                    ->all(),
            'urls' => [
                'answer' => route('portal.profile.b2b-answer'),
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
            $startsAt = CarbonImmutable::createFromFormat(
                '!Y-m-d\\TH:i',
                (string) $validated['starts_at'],
                (string) $client->timezone,
            );
            $errors = CarbonImmutable::getLastErrors();
            if (! $startsAt instanceof CarbonImmutable
                || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new \InvalidArgumentException('Invalid date.');
            }
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

        return to_route('portal.b2b')->with('b2b_lead_submitted', true);
    }

    private function locale(Request $request, ?string $language): string
    {
        $language ??= $request->session()->get('portal.locale');

        return str_starts_with(strtolower((string) $language), 'ru') ? 'ru' : 'en';
    }
}
