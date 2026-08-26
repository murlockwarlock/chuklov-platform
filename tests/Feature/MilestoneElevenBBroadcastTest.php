<?php

namespace Tests\Feature;

use App\Filament\Resources\BroadcastCampaigns\BroadcastCampaignResource;
use App\Models\User;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Broadcasts\Application\BroadcastSegmentQuery;
use App\Modules\Broadcasts\Application\CreateBroadcastCampaign;
use App\Modules\Broadcasts\Application\PreviewBroadcastCampaign;
use App\Modules\Broadcasts\Application\ScheduleBroadcastWork;
use App\Modules\Broadcasts\Application\StartBroadcastCampaign;
use App\Modules\Broadcasts\Application\TestBroadcastCampaign;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientTag;
use App\Modules\Broadcasts\Domain\Models\BroadcastRecipient;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class MilestoneElevenBBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private RecordingNotificationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$this->channel]));
    }

    public function test_preview_is_deterministic_and_suppresses_missing_consent_or_channel(): void
    {
        [$organization, $actor] = $this->fixture();
        $eligible = $this->client($organization, consent: true, verified: true, language: 'ru');
        $this->client($organization, consent: false, verified: true, language: 'ru');
        $this->client($organization, consent: true, verified: false, language: 'ru');
        $campaign = $this->campaign($actor, [['key' => 'language', 'operator' => 'equals', 'value' => 'ru']]);

        $preview = app(PreviewBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertSame(3, $preview['matched']);
        self::assertSame(1, $preview['eligible']);
        self::assertSame(2, $preview['suppressed']);
        self::assertSame($eligible->getKey(), app(BroadcastSegmentQuery::class)->build($organization->getKey(), [['key' => 'language', 'operator' => 'equals', 'value' => 'ru'], ['key' => 'verified_channel', 'operator' => 'equals', 'value' => 'telegram']])->whereKey($eligible->getKey())->value('id'));
    }

    public function test_immediate_send_materializes_once_batches_and_replay_does_not_redeliver(): void
    {
        [$organization, $actor] = $this->fixture();
        foreach (range(1, 205) as $index) {
            $this->client($organization, consent: true, verified: true, language: 'ru');
        }
        $campaign = $this->campaign($actor, []);

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertSame(BroadcastCampaignState::Completed, $campaign->refresh()->state);
        self::assertSame(205, BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->where('kind', 'production')->count());
        self::assertSame(3, DB::table('broadcast_batches')->where('campaign_id', $campaign->getKey())->count());
        self::assertCount(205, $this->channel->messages);
        app(ScheduleBroadcastWork::class)->handle();
        self::assertCount(205, $this->channel->messages);
        self::assertSame(205, DB::table('broadcast_delivery_attempts')->count());
    }

    public function test_snapshot_remains_unchanged_after_client_and_campaign_source_data_changes(): void
    {
        [$organization, $actor] = $this->fixture();
        $client = $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, [['key' => 'language', 'operator' => 'equals', 'value' => 'ru']], 'scheduled', now()->addHour());

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);
        $recipient = BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->sole();
        $client->forceFill(['full_name' => 'Новое имя', 'language' => 'en'])->save();

        self::assertSame('ru', $recipient->refresh()->language);
        self::assertNotSame('Новое имя', $recipient->render_context['client']['full_name']);
        self::assertSame(1, $campaign->refresh()->audience_count);
    }

    public function test_test_send_targets_only_explicit_recipient_and_is_distinguishable(): void
    {
        [$organization, $actor] = $this->fixture();
        $target = $this->client($organization, consent: true, verified: true, language: 'ru');
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);

        $recipient = app(TestBroadcastCampaign::class)->handle($actor, $campaign, $target->getKey());

        self::assertSame('test', $recipient->kind);
        self::assertSame(BroadcastRecipientState::Delivered, $recipient->state);
        self::assertSame($target->getKey(), $recipient->client_id);
        self::assertCount(1, $this->channel->messages);
        self::assertSame(0, BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->where('kind', 'production')->count());
    }

    public function test_language_referral_source_booking_last_visit_no_rebooking_tags_and_survey_completion_queries_are_scoped(): void
    {
        [$organization] = $this->fixture();
        $foreign = Organization::factory()->create();
        $client = $this->client($organization, consent: true, verified: true, language: 'en');
        $referrer = Client::factory()->forOrganization($organization)->create();
        BroadcastClientTag::query()->create(['organization_id' => $organization->getKey(), 'client_id' => $client->getKey(), 'tag' => 'vip']);
        ReferralRelationship::query()->forceCreate(['organization_id' => $organization->getKey(), 'referrer_client_id' => $referrer->getKey(), 'referred_client_id' => $client->getKey(), 'establishment_method' => 'manual_crm', 'registered_at' => now()]);
        ClientAttribution::query()->forceCreate(['organization_id' => $organization->getKey(), 'client_id' => $client->getKey(), 'source_type' => 'utm', 'utm_source' => 'newsletter', 'capture_channel' => 'portal', 'captured_at' => now(), 'accepted_at' => now()]);
        $this->completedBooking($organization, $client, now()->subMonth());
        $this->surveyCompletion($organization, $client);
        $foreignClient = $this->client($foreign, consent: true, verified: true, language: 'en');
        BroadcastClientTag::query()->create(['organization_id' => $foreign->getKey(), 'client_id' => $foreignClient->getKey(), 'tag' => 'vip']);
        $filters = [['key' => 'tag', 'operator' => 'equals', 'value' => 'vip'], ['key' => 'language', 'operator' => 'equals', 'value' => 'en'], ['key' => 'referral_relationship', 'operator' => 'equals', 'value' => true], ['key' => 'attribution_source', 'operator' => 'equals', 'value' => 'newsletter'], ['key' => 'visit_count', 'operator' => 'gte', 'value' => 1], ['key' => 'last_visit', 'operator' => 'before', 'value' => now()->subWeek()->toIso8601String()], ['key' => 'no_future_booking', 'operator' => 'equals', 'value' => true], ['key' => 'survey_completed', 'operator' => 'equals', 'value' => true]];

        self::assertSame([$client->getKey()], app(BroadcastSegmentQuery::class)->build($organization->getKey(), $filters)->pluck('id')->all());
    }

    public function test_cross_organization_and_staff_mutations_are_denied(): void
    {
        [$organization, $owner] = $this->fixture();
        $campaign = $this->campaign($owner, []);
        $other = Organization::factory()->create();
        $otherOwner = User::factory()->forOrganization($other)->create();
        app(OrganizationContext::class)->set($other);

        try {
            app(PreviewBroadcastCampaign::class)->handle($otherOwner, $campaign);
            self::fail('Cross-organization campaign access must fail.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('audit_events')->where('organization_id', $other->getKey())->where('action', 'broadcast.campaign.previewed')->count());
        }

        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        app(OrganizationContext::class)->set($organization);
        $this->expectException(AuthorizationException::class);
        app(CreateBroadcastCampaign::class)->handle($staff, $this->campaignData([]));
    }

    public function test_explicit_withdrawal_and_unverified_channel_never_dispatch(): void
    {
        [$organization, $actor] = $this->fixture();
        $client = $this->client($organization, consent: true, verified: true, language: 'ru');
        ClientConsent::factory()->forClient($client)->create(['subject' => ConsentSubject::Marketing->value, 'is_required' => false, 'granted' => false, 'recorded_at' => now()->addSecond()]);
        $campaign = $this->campaign($actor, []);

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertCount(0, $this->channel->messages);
        self::assertSame(BroadcastRecipientState::Suppressed, BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->sole()->state);
    }

    public function test_sensitive_or_unknown_filter_and_non_marketing_template_are_rejected(): void
    {
        [$organization, $actor] = $this->fixture();
        $template = NotificationTemplate::factory()->forOrganization($organization)->create(['purpose' => ScenarioRulePurpose::Service->value, 'locale' => 'ru']);
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create();
        $data = $this->campaignData([['key' => 'medical.diagnosis', 'operator' => 'equals', 'value' => 'x']]);
        $data['template_version_ru_id'] = $version->getKey();

        $this->expectException(ValidationException::class);
        app(CreateBroadcastCampaign::class)->handle($actor, $data);
    }

    public function test_crm_pages_are_authorized_and_cross_organization_records_are_hidden(): void
    {
        [$organization, $owner] = $this->fixture();
        $campaign = $this->campaign($owner, []);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(BroadcastCampaignResource::getUrl('index'))->assertOk();
        $this->get(BroadcastCampaignResource::getUrl('create'))->assertOk();

        $other = Organization::factory()->create();
        $otherOwner = User::factory()->forOrganization($other)->create();
        config()->set('tenancy.default_organization_id', $other->getKey());
        app(OrganizationContext::class)->set($other);
        $this->actingAs($otherOwner);
        $this->get(BroadcastCampaignResource::getUrl('view', ['record' => $campaign]))->assertNotFound();
    }

    public function test_delivery_errors_are_sanitized_and_horizon_consumes_the_bounded_queue(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $this->channel = new RecordingNotificationChannel('telegram', NotificationDeliveryResult::permanentFailure('Bearer secret token'));
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$this->channel]));
        $campaign = $this->campaign($actor, []);

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);

        $recipient = BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->sole();
        self::assertSame(BroadcastRecipientState::Failed, $recipient->state);
        self::assertSame('provider_error', $recipient->last_error_code);
        self::assertNotContains('Bearer secret token', DB::table('audit_events')->pluck('metadata')->all());
        self::assertContains('broadcasts', config('horizon.defaults.supervisor-1.queue'));
    }

    /** @return array{Organization, User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $actor = User::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $actor];
    }

    /** @param list<array{key: string, operator: string, value: mixed}> $filters */
    private function campaign(User $actor, array $filters, string $mode = 'immediate', mixed $scheduledAt = null): BroadcastCampaign
    {
        $data = $this->campaignData($filters);
        $data['send_mode'] = $mode;
        $data['scheduled_at'] = $scheduledAt;

        return app(CreateBroadcastCampaign::class)->handle($actor, $data);
    }

    /**
     * @param  list<array{key: string, operator: string, value: mixed}>  $filters
     * @return array<string, mixed>
     */
    private function campaignData(array $filters): array
    {
        $organization = app(OrganizationContext::class)->organization();
        $template = NotificationTemplate::factory()->forOrganization($organization)->create(['purpose' => ScenarioRulePurpose::Marketing->value, 'locale' => 'ru']);
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create(['body' => 'Здравствуйте, {{ client.full_name }}!', 'variables' => ['client.full_name']]);

        return ['name' => 'Проверочная рассылка', 'send_mode' => 'immediate', 'channel_priority' => ['telegram'], 'segment_definition' => $filters, 'template_version_ru_id' => $version->getKey(), 'template_version_en_id' => null, 'scheduled_at' => null];
    }

    private function client(Organization $organization, bool $consent, bool $verified, string $language): Client
    {
        $client = Client::factory()->forOrganization($organization)->create(['language' => $language]);
        ClientConsent::factory()->forClient($client)->create(['subject' => ConsentSubject::Marketing->value, 'is_required' => false, 'granted' => $consent]);
        ClientChannelIdentity::factory()->forClient($client)->create(['channel' => 'telegram', 'external_id' => 'chat-'.$client->getKey(), 'verification_status' => $verified ? ChannelIdentityStatus::Verified->value : ChannelIdentityStatus::Unverified->value, 'verification_method' => $verified ? 'test' : null, 'verified_at' => $verified ? now() : null]);

        return $client;
    }

    private function completedBooking(Organization $organization, Client $client, mixed $startsAt): void
    {
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $serviceRecord = Service::factory()->forOrganization($organization)->create();
        $service = Service::query()->whereKey($serviceRecord->getKey())->firstOrFail();
        Booking::factory()->forOrganization($organization)->forClient($client)->forSpecialist($specialist)->forService($service)->create(['status' => BookingStatus::Completed->value, 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(), 'blocking_ends_at' => $startsAt->copy()->addHour()]);
    }

    private function surveyCompletion(Organization $organization, Client $client): void
    {
        $definitionId = DB::table('survey_definitions')->insertGetId(['organization_id' => $organization->getKey(), 'definition_key' => 'generic-'.$client->getKey(), 'title' => 'Generic', 'is_available' => true, 'created_at' => now(), 'updated_at' => now()]);
        $versionId = DB::table('survey_versions')->insertGetId(['organization_id' => $organization->getKey(), 'survey_definition_id' => $definitionId, 'version' => 1, 'status' => 'published', 'title' => 'Generic', 'definition' => '{}', 'scoring' => '{}', 'published_at' => now(), 'created_at' => now()]);
        DB::table('survey_attempts')->insert(['organization_id' => $organization->getKey(), 'client_id' => $client->getKey(), 'survey_definition_id' => $definitionId, 'survey_version_id' => $versionId, 'status' => 'completed', 'definition_snapshot' => 'x', 'scoring_snapshot' => 'x', 'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
