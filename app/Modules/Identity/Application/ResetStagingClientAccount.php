<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ResetStagingClientAccount
{
    private const PROTECTED_REFERENCES = [
        ['ai_runs', 'client_id', 'операции AI'],
        ['b2b_leads', 'client_id', 'B2B-лиды'],
        ['b2b_sales_calls', 'client_id', 'B2B-созвоны'],
        ['booking_events', 'actor_client_id', 'история действий по записи'],
        ['booking_idempotency_keys', 'actor_client_id', 'операции записи'],
        ['bookings', 'client_id', 'записи'],
        ['client_attributions', 'client_id', 'источник клиента'],
        ['feedback_submissions', 'client_id', 'обратная связь'],
        ['financial_obligations', 'client_id', 'финансовые данные'],
        ['medical_attachments', 'client_id', 'медицинские файлы'],
        ['medical_profiles', 'client_id', 'медицинский профиль'],
        ['medical_sessions', 'client_id', 'медицинские сеансы'],
        ['pre_auth_attributions', 'consumed_client_id', 'история источника'],
        ['referral_campaign_links', 'partner_client_id', 'реферальные кампании'],
        ['referral_commercial_evidence', 'referred_client_id', 'реферальная коммерция'],
        ['referral_partner_profiles', 'client_id', 'партнёрский профиль'],
        ['referral_payout_requests', 'beneficiary_client_id', 'реферальные выплаты'],
        ['referral_relationships', 'referrer_client_id', 'реферальные связи'],
        ['referral_relationships', 'referred_client_id', 'реферальные связи'],
        ['referral_reward_ledger_entries', 'beneficiary_client_id', 'реферальный расчёт'],
        ['referral_reward_ledger_entries', 'referred_client_id', 'реферальный расчёт'],
        ['scenario_actions', 'client_id', 'запланированные действия'],
        ['tracker_check_ins', 'client_id', 'данные трекера'],
        ['tracker_entitlements', 'client_id', 'доступ к трекеру'],
        ['tracker_task_entries', 'client_id', 'данные трекера'],
        ['tracker_tasks', 'client_id', 'задачи трекера'],
    ];

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client): void
    {
        if (! app()->environment(['local', 'staging', 'testing'])) {
            throw new AuthorizationException('The staging account reset is unavailable in this environment.');
        }

        $organization = $this->context->organization();

        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        DB::transaction(function () use ($actor, $client, $organization): void {
            $lockedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureResettable($organization->getKey(), $lockedClient->getKey());
            $deletedEphemeralRecordCount = $this->deleteEphemeralRecords(
                $organization->getKey(),
                $lockedClient->getKey(),
            );

            if (! $lockedClient->delete()) {
                throw new RuntimeException('The staging client account could not be deleted.');
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'client.staging_account.reset',
                targetType: Client::class,
                targetId: (string) $lockedClient->getKey(),
                metadata: ['deleted_ephemeral_record_count' => $deletedEphemeralRecordCount],
            );
        });
    }

    private function ensureResettable(int $organizationId, int $clientId): void
    {
        $blockedReasons = [];

        foreach (self::PROTECTED_REFERENCES as [$table, $column, $label]) {
            if ($this->clientReferenceQuery($table, $column, $organizationId, $clientId)->exists()) {
                $blockedReasons[] = $label;
            }
        }

        if (DB::table('survey_attempts')
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->where('status', '<>', 'in_progress')
            ->exists()
            || DB::table('survey_reports')
                ->where('organization_id', $organizationId)
                ->where('client_id', $clientId)
                ->exists()
            || DB::table('survey_comparisons')
                ->where('organization_id', $organizationId)
                ->where('client_id', $clientId)
                ->exists()) {
            $blockedReasons[] = 'завершённые результаты диагностики';
        }

        if (DB::table('broadcast_recipients')
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->where(function (Builder $query): void {
                $query->whereNull('kind')->orWhere('kind', '<>', 'test');
            })
            ->exists()) {
            $blockedReasons[] = 'рабочие рассылки';
        }

        if ($blockedReasons !== []) {
            throw ValidationException::withMessages([
                'client' => 'Сброс остановлен: у клиента есть рабочие данные ('.implode(', ', array_values(array_unique($blockedReasons))).'). Кнопка доступна только для тестового профиля без таких данных.',
            ]);
        }
    }

    private function deleteEphemeralRecords(int $organizationId, int $clientId): int
    {
        $deletedCount = 0;
        $testRecipientIds = DB::table('broadcast_recipients')
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->where('kind', 'test')
            ->pluck('id')
            ->all();

        if ($testRecipientIds !== []) {
            $deletedCount += DB::table('broadcast_delivery_attempts')
                ->where('organization_id', $organizationId)
                ->whereIn('recipient_id', $testRecipientIds)
                ->delete();
            $deletedCount += DB::table('broadcast_recipients')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $testRecipientIds)
                ->delete();
        }

        $deletedCount += DB::table('survey_attempts')
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->where('status', 'in_progress')
            ->delete();
        $deletedCount += DB::table('client_acquisition_registrations')
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->delete();
        $deletedCount += DB::table('client_telegram_authentication_requests')
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->delete();
        $deletedCount += DB::table('client_referral_identities')
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->delete();

        return $deletedCount;
    }

    private function clientReferenceQuery(
        string $table,
        string $column,
        int $organizationId,
        int $clientId,
    ): Builder {
        return DB::table($table)
            ->where('organization_id', $organizationId)
            ->where($column, $clientId);
    }
}
