<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RequestFinancialObligationReminder
{
    public function __construct(
        private FinanceAuthorization $authorization,
        private ReconcileFinancialObligation $reconciliation,
        private RecordScenarioEvent $scenarioEvents,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, FinancialObligation $obligation): ScenarioEvent
    {
        $organization = $this->authorization->authorizeManage($actor);
        $this->authorization->assertOwned($obligation);

        return DB::transaction(function () use ($actor, $organization, $obligation): ScenarioEvent {
            $locked = FinancialObligation::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($obligation->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $reconciliation = $this->reconciliation->handle(
                (int) $organization->getKey(),
                (int) $locked->getKey(),
                lock: true,
            );

            if ($reconciliation->displayOutstanding->isZero()) {
                throw ValidationException::withMessages([
                    'obligation' => 'У задолженности нет непогашенного остатка.',
                ]);
            }

            $idempotencyKey = 'finance.obligation.reminder_requested:'.$organization->getKey().':'.$locked->getKey().':'.$reconciliation->displayOutstanding->minorUnits();
            $existing = ScenarioEvent::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof ScenarioEvent) {
                return $existing;
            }

            $event = $this->scenarioEvents->financialDebtReminderRequested(
                $locked,
                $reconciliation,
                CarbonImmutable::now(),
            );
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'finance.obligation.reminder_requested',
                targetType: FinancialObligation::class,
                targetId: (string) $locked->getKey(),
                metadata: [
                    'client_id' => (int) $locked->client_id,
                    'amount_minor' => $reconciliation->displayOutstanding->minorUnits(),
                    'currency' => $reconciliation->displayOutstanding->currency()->value,
                ],
            );

            return $event;
        });
    }
}
