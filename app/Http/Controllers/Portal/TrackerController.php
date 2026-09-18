<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\PortalPaymentErrorMessages;
use App\Modules\Commerce\Application\StartPurchaseCheckout;
use App\Modules\Finance\Domain\Exceptions\PaymentGatewayInitiationFailure;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Surveys\Application\ListClientSurveys;
use App\Modules\Tracker\Application\ListClientTrackerOverview;
use App\Modules\Tracker\Application\RecordTrackerTaskEntry;
use App\Modules\Tracker\Application\SubmitTrackerCheckIn;
use App\Modules\Tracker\Domain\Enums\TrackerTaskEntryStatus;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TrackerController extends Controller
{
    public function index(ClientPortalContext $context, ListClientTrackerOverview $overview, ListClientSurveys $surveys): Response
    {
        $client = $context->client();
        /** @var list<array{versionId: int, name: string, price: string|null, description: string|null, durationDays: int, purchaseUrl: string}> $plans */
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
                'versionId' => (int) $plan->currentVersion?->getKey(),
                'name' => $plan->name,
                'price' => $this->price($plan->currentVersion),
                'description' => $plan->currentVersion?->description,
                'durationDays' => (int) $plan->currentVersion?->duration_days,
                'purchaseUrl' => route('portal.tracker.purchase', $plan->currentVersion?->getKey()),
            ])
            ->all();
        $tracker = $overview->handle();
        $tracker['plans'] = $plans;

        return Inertia::render('Portal/Tracker', [
            'tracker' => $tracker,
            'surveys' => $surveys->handle($client),
            'urls' => [
                'checkIn' => route('portal.tracker.check-in'),
                'taskEntry' => route('portal.tracker.task-entry', ['taskId' => '__id__']),
                'specialist' => route('portal.bookings.create', ['format' => VisitFormat::Online->value]),
                'surveys' => route('portal.surveys.index'),
            ],
        ]);
    }

    public function purchase(
        Request $request,
        ClientPortalContext $clientContext,
        OrganizationContext $organizationContext,
        StartPurchaseCheckout $checkout,
        PortalPaymentErrorMessages $paymentErrors,
        int $versionId,
    ): RedirectResponse {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:180', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        $organization = $organizationContext->organization();
        $version = TrackerPlanVersion::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($versionId)
            ->where('included_access', true)
            ->whereHas('plan', function (Builder $query) use ($organization, $versionId): void {
                $query
                    ->where('organization_id', $organization->getKey())
                    ->where('is_active', true)
                    ->where('is_visible', true)
                    ->where('current_version_id', $versionId);
            })
            ->with('plan')
            ->first();

        if (! $version instanceof TrackerPlanVersion) {
            throw (new ModelNotFoundException)->setModel(TrackerPlanVersion::class, [$versionId]);
        }

        try {
            $result = $checkout->trackerPlan(
                organization: $organization,
                client: $clientContext->client(),
                version: $version,
                gateway: 'lava',
                idempotencyKey: (string) $data['idempotency_key'],
                buyerEmail: (string) $clientContext->client()->email,
                successfulReturnUrl: route('portal.finance.index'),
                failureReturnUrl: route('portal.finance.index'),
                cancelReturnUrl: route('portal.finance.index'),
            );
        } catch (PaymentGatewayInitiationFailure $exception) {
            return back()->withErrors(['payment' => $paymentErrors->gateway($exception)]);
        } catch (ValidationException $exception) {
            return back()->withErrors(['payment' => $paymentErrors->validation($exception)]);
        }

        if (! is_string($result->transaction->checkout_url) || $result->transaction->checkout_url === '') {
            return back()->withErrors(['payment' => $paymentErrors->message('payment_checking')]);
        }

        return redirect()->away($result->transaction->checkout_url);
    }

    public function checkIn(Request $request, ClientPortalContext $context, SubmitTrackerCheckIn $submit): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        $submit->handle($context->client(), (string) $data['note']);

        return back()->with('tracker_check_in_saved', true);
    }

    public function taskEntry(
        Request $request,
        ClientPortalContext $context,
        RecordTrackerTaskEntry $record,
        int $taskId,
    ): RedirectResponse {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:completed,not_completed'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $record->handle(
            client: $context->client(),
            taskId: $taskId,
            status: TrackerTaskEntryStatus::from((string) $data['status']),
            comment: isset($data['comment']) ? (string) $data['comment'] : null,
        );

        return back()->with('tracker_task_saved', true);
    }

    private function price(mixed $version): ?string
    {
        if (! $version instanceof TrackerPlanVersion) {
            return null;
        }

        return Money::ofMinor($version->price_minor, $version->currencyCode())->toDecimalString().' '.$version->currencyCode()->value;
    }
}
