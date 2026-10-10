<?php

use App\Modules\AI\Application\Actions\TestProviderConnection;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\B2B\Application\GetB2bZoomConfiguration;
use App\Modules\B2B\Application\GetB2bZoomProviderAffinity;
use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function requireStagingProofTarget(): void
{
    if (! app()->environment('staging')) {
        throw new RuntimeException('Proof mutations require the explicitly authorized staging environment.');
    }
}

function providerInventoryCheck(int $userId, int $clientId): void
{
    bootstrapApplication(false);
    [$organization, $actor, $client] = smokeIdentity($userId, $clientId);
    $zoom = app(GetB2bZoomConfiguration::class)->handle();
    $lava = OrganizationCredential::query()
        ->where('organization_id', $organization->getKey())
        ->where('provider', 'lava')
        ->where('credential_name', (string) config('payments.lava.credential_name', 'default'))
        ->first();
    $lavaValues = $lava?->credentials ?? [];
    $mailer = (string) config('mail.auth_mailer', config('mail.default'));
    $providers = AiProviderConfiguration::query()
        ->where('organization_id', $organization->getKey())
        ->limit(20)
        ->get(['id', 'provider_name', 'is_enabled', 'health_status', 'credential_id'])
        ->map(static fn ($provider): array => [
            'id' => $provider->getKey(),
            'provider' => $provider->provider_name,
            'enabled' => $provider->is_enabled,
            'recorded_health' => $provider->health_status->value,
            'has_credential_binding' => $provider->credential_id !== null,
        ])->all();
    $identities = $client->channelIdentities()
        ->where('verification_status', 'verified')
        ->select('channel')
        ->get()
        ->pluck('channel')
        ->unique()
        ->values()
        ->all();
    $inventory = [
        'organization_id' => $organization->getKey(),
        'acceptance_user_id' => $actor->getKey(),
        'acceptance_client_id' => $client->getKey(),
        'acceptance_verified_channels' => $identities,
        'acceptance_staff_telegram_count' => OrganizationChannelIdentity::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $actor->getKey())
            ->where('channel', 'telegram')
            ->where('verification_status', 'verified')
            ->count(),
        'synthetic_telegram_client_candidates' => Client::query()
            ->where('organization_id', $organization->getKey())
            ->where(static fn ($query) => $query
                ->whereRaw('LOWER(full_name) LIKE ?', ['%test%'])
                ->orWhereRaw('LOWER(full_name) LIKE ?', ['%staging%'])
                ->orWhereRaw('LOWER(full_name) LIKE ?', ['%synthetic%'])
                ->orWhereRaw('LOWER(full_name) LIKE ?', ['%тест%'])
                ->orWhere('email', 'like', '%@example.test'))
            ->whereHas('channelIdentities', static fn ($query) => $query
                ->where('channel', 'telegram')
                ->where('verification_status', 'verified'))
            ->limit(10)
            ->pluck('id')
            ->all(),
        'acceptance_has_email' => filter_var($client->email, FILTER_VALIDATE_EMAIL) !== false,
        'mail' => ['mailer' => $mailer, 'transport' => config('mail.mailers.'.$mailer.'.transport')],
        'zoom' => array_intersect_key($zoom, array_flip(['exists', 'enabled', 'configured', 'hasClientSecret', 'status'])),
        'payment' => [
            'selected_gateway' => config('payments.gateway'),
            'fake_enabled' => config('payments.fake_enabled'),
            'lava_credential_exists' => $lava !== null,
            'lava_credential_active' => $lava?->status?->value === 'active',
            'lava_has_api_key' => is_string($lavaValues['api_key'] ?? null) && trim($lavaValues['api_key']) !== '',
            'lava_has_webhook_key' => is_string($lavaValues['webhook_api_key'] ?? null) && trim($lavaValues['webhook_api_key']) !== '',
            'mode' => 'NO SANDBOX SWITCH IN CURRENT ADAPTER; DO NOT CHARGE',
        ],
        'ai_providers' => $providers,
        'storage' => ['private_driver' => config('filesystems.disks.private.driver')],
        'queue_connection' => config('queue.default'),
        'database_driver' => DB::connection()->getDriverName(),
        'timezone' => config('app.timezone'),
    ];
    echo 'PROVIDER_INVENTORY='.json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    ok('PROVIDER INVENTORY', 'read only; no secrets, provider calls, charges or sends');
}

function aiProviderProbesCheck(int $userId, int $clientId): void
{
    bootstrapApplication(false);
    requireStagingProofTarget();
    [$organization, $actor] = smokeIdentity($userId, $clientId);
    $providers = AiProviderConfiguration::query()
        ->where('organization_id', $organization->getKey())
        ->where('is_enabled', true)
        ->limit(5)
        ->get(['id', 'provider_name']);
    $failures = 0;
    foreach ($providers as $provider) {
        $result = app(TestProviderConnection::class)->handle($actor, $provider->getKey());
        echo 'AI_PROVIDER_PROBE='.json_encode([
            'provider_id' => $provider->getKey(),
            'provider' => $provider->provider_name,
            'success' => $result['success'],
            'safe_message' => $result['message'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
        $failures += $result['success'] ? 0 : 1;
    }
    if ($providers->isEmpty() || $failures > 0) {
        fail('AI PROVIDER PROBES', 'failed '.$failures.' of '.$providers->count().'; no inference claim');
    }
    ok('AI PROVIDER PROBES', 'authenticated low-impact provider reads; not inference evidence');
}

function telegramAcceptanceCheck(int $userId, int $clientId, bool $staffTarget = false): void
{
    bootstrapApplication(false);
    requireStagingProofTarget();
    [$organization, $actor, $client] = smokeIdentity($userId, $clientId);
    $identity = $staffTarget
        ? OrganizationChannelIdentity::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $actor->getKey())
            ->where('channel', 'telegram')
            ->where('verification_status', 'verified')
            ->first()
        : $client->channelIdentities()
            ->where('channel', 'telegram')
            ->where('verification_status', 'verified')
            ->first();
    if ($identity === null) {
        fail('TELEGRAM ACCEPTANCE', 'configured acceptance client has no verified Telegram identity');
    }
    $channel = app(NotificationChannelRegistry::class)->get('telegram');
    if ($channel === null) {
        fail('TELEGRAM ACCEPTANCE', 'Telegram notification adapter is not wired');
    }
    $result = $channel->send(new NotificationMessage(
        recipientExternalId: $identity->external_id,
        body: 'Проверка связи тестового окружения. Ответ не требуется.',
        subject: null,
        locale: 'ru',
        idempotencyKey: 'system-proof:telegram:20261009:'.($staffTarget ? 'user:'.$actor->getKey() : 'client:'.$client->getKey()),
        requireKnownExternalOutcome: true,
        webAppUrl: app(ResolveTelegramMiniAppEntry::class)->launchUrl('portal'),
        webAppButtonText: 'Открыть приложение',
        organizationId: $organization->getKey(),
    ));
    echo 'TELEGRAM_ACCEPTANCE='.json_encode([
        'client_id' => $client->getKey(),
        'staff_target' => $staffTarget,
        'acceptance_user_id' => $actor->getKey(),
        'outcome' => $result->outcome->value,
        'has_provider_reference' => $result->providerReference !== null,
        'error_code' => $result->errorCode,
        'send_count' => 1,
    ], JSON_THROW_ON_ERROR)."\n";
    if ($result->outcome !== NotificationDeliveryOutcome::Delivered) {
        fail('TELEGRAM ACCEPTANCE', 'not delivered; do not blindly retry');
    }
    ok('TELEGRAM ACCEPTANCE', 'one bounded real send with Mini App button; no receive/click claim');
}

function zoomAcceptanceCheck(int $userId, int $clientId): void
{
    bootstrapApplication(false);
    requireStagingProofTarget();
    [$organization] = smokeIdentity($userId, $clientId);
    $provider = app(VideoMeetingProvider::class);
    $deadline = ProviderOperationDeadline::fromNow(90);
    $request = new VideoMeetingRequest(
        externalKey: (string) Str::uuid(),
        startsAt: CarbonImmutable::now('UTC')->addDays(2)->startOfMinute(),
        durationMinutes: 15,
        timezone: 'UTC',
        topic: 'Synthetic staging acceptance meeting',
        providerAccountAffinity: app(GetB2bZoomProviderAffinity::class)->handle(),
    );
    echo 'ZOOM_PROOF_CORRELATION='.$request->correlationMarker()."\n";
    $created = null;
    try {
        $created = $provider->createMeeting($organization, $request, $deadline);
        echo 'ZOOM_SYNTHETIC_MEETING_ID='.$created->identity->meetingId."\n";
        $observed = $provider->getMeeting($organization, $created->identity, $request, $deadline);
        if ($observed === null || ! $observed->matchesIdentityAndCorrelation($created->identity, $request) || ! $observed->matchesRequest($request)) {
            fail('ZOOM ACCEPTANCE', 'created meeting did not match the authoritative request');
        }
        $updatedRequest = new VideoMeetingRequest(
            externalKey: $request->externalKey,
            startsAt: $request->startsAt->addHour(),
            durationMinutes: 20,
            timezone: 'UTC',
            topic: $request->topic,
            providerAccountAffinity: $request->providerAccountAffinity,
        );
        $provider->updateMeeting($organization, $created->identity, $updatedRequest, $deadline);
        $updated = $provider->getMeeting($organization, $created->identity, $updatedRequest, $deadline);
        if ($updated === null || ! $updated->matchesRequest($updatedRequest)) {
            fail('ZOOM ACCEPTANCE', 'updated meeting did not converge');
        }
        ok('ZOOM CREATE/READ/UPDATE', 'identity, correlation, start, duration and timezone matched');
    } finally {
        if ($created !== null) {
            $cleanupDeadline = ProviderOperationDeadline::fromNow(30);
            $provider->cancelMeeting($organization, $created->identity, $request, $cleanupDeadline);
            if ($provider->getMeeting($organization, $created->identity, $request, $cleanupDeadline) !== null) {
                fail('ZOOM CLEANUP', 'synthetic meeting still exists');
            }
            ok('ZOOM CLEANUP', 'only the newly created synthetic meeting was cancelled and verified absent');
        }
    }
}
