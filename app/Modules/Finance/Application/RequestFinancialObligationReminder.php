<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class RequestFinancialObligationReminder
{
    public function __construct(
        private FinanceAuthorization $authorization,
        private ReconcileFinancialObligation $reconciliation,
        private RecordScenarioEvent $scenarioEvents,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, FinancialObligation $obligation, ?string $requestIdempotencyKey = null): ScenarioEvent
    {
        $organization = $this->authorization->authorizeManage($actor);
        $this->authorization->assertOwned($obligation);
        $requestIdempotencyKey = $this->requestIdempotencyKey($requestIdempotencyKey);

        return DB::transaction(function () use ($actor, $organization, $obligation, $requestIdempotencyKey): ScenarioEvent {
            $locked = FinancialObligation::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($obligation->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $idempotencyKey = 'finance.obligation.reminder_requested:'.$organization->getKey().':'.$locked->getKey().':'.$requestIdempotencyKey;
            $existing = ScenarioEvent::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof ScenarioEvent) {
                return $existing;
            }

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

            $event = $this->scenarioEvents->financialDebtReminderRequested(
                $locked,
                $reconciliation,
                CarbonImmutable::now(),
                $requestIdempotencyKey,
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

    private function requestIdempotencyKey(?string $key): string
    {
        $key = $key === null ? Str::uuid()->toString() : trim($key);

        if ($key === '' || mb_strlen($key) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Ключ запроса указан неверно.',
            ]);
        }

        return $key;
    }
}
