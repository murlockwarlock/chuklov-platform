<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Tracker\Application\ResolveTrackerAccess;
use App\Modules\Tracker\Application\SubmitTrackerCheckIn;
use App\Modules\Tracker\Domain\Models\TrackerCheckIn;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TrackerController extends Controller
{
    public function index(ClientPortalContext $context, ResolveTrackerAccess $access): Response
    {
        $client = $context->client();
        $state = $access->handle($client);
        /** @var list<array{name: string, price: string|null, description: string|null, durationDays: int}> $plans */
        $plans = TrackerPlan::query()
            ->where('organization_id', $client->organization_id)
            ->where('is_active', true)
            ->where('is_visible', true)
            ->with('currentVersion')
            ->orderBy('current_version_id')
            ->get()
            ->filter(fn (TrackerPlan $plan): bool => $plan->currentVersion instanceof TrackerPlanVersion && $plan->currentVersion->included_access)
            ->sortBy(fn (TrackerPlan $plan): int => (int) $plan->currentVersion?->display_order)
            ->values()
            ->map(fn (TrackerPlan $plan): array => [
                'name' => $plan->name,
                'price' => $this->price($plan->currentVersion),
                'description' => $plan->currentVersion?->description,
                'durationDays' => (int) $plan->currentVersion?->duration_days,
            ])
            ->all();
        $history = TrackerCheckIn::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->latest('occurred_at')
            ->limit(30)
            ->get()
            ->map(fn (TrackerCheckIn $entry): array => [
                'occurredAt' => CarbonImmutable::parse((string) $entry->getRawOriginal('occurred_at'))->toIso8601String(),
                'note' => (string) $entry->note,
            ])
            ->all();

        return Inertia::render('Portal/Tracker', [
            'tracker' => [
                'access' => $state->toArray(),
                'plans' => $plans,
                'history' => $state->allowed() ? $history : [],
            ],
            'urls' => [
                'checkIn' => route('portal.tracker.check-in'),
                'specialist' => route('portal.bookings.create', ['format' => VisitFormat::Online->value]),
            ],
        ]);
    }

    public function checkIn(Request $request, ClientPortalContext $context, SubmitTrackerCheckIn $submit): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        $submit->handle($context->client(), (string) $data['note']);

        return back()->with('tracker_check_in_saved', true);
    }

    private function price(mixed $version): ?string
    {
        if (! $version instanceof TrackerPlanVersion) {
            return null;
        }

        return Money::ofMinor($version->price_minor, $version->currencyCode())->toDecimalString().' '.$version->currencyCode()->value;
    }
}
