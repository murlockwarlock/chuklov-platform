<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Feedback\Application\FeedbackRequestFingerprint;
use App\Modules\Feedback\Application\ListFeedbackSubmissionsForCrm;
use App\Modules\Feedback\Application\SaveFeedbackConfiguration;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Identity\Application\RegisterClientAcquisition;
use App\Modules\Identity\Application\UpdateClientProfileFromPortal;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Application\EnsureReferralIdentity;
use App\Modules\Referrals\Application\EstablishManualReferralRelationship;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use App\Modules\Referrals\Application\ListReferralRelationshipsForCrm;
use App\Modules\Referrals\Domain\Enums\ReferralEstablishmentMethod;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class M11AAttributionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_automatic_attribution_is_captured_and_first_touch_is_immutable(): void
    {
        $organization = $this->organizationWithClientRecords();
        $this->get(route('portal.home', ['utm_source' => 'Newsletter', 'utm_campaign' => 'Spring']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('Portal/Entry'));

        $sessionId = session()->getId();
        $client = Client::factory()->forOrganization($organization)->create(['lead_source' => null]);
        app(RegisterClientAcquisition::class)->handle($organization, $client, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($client, $sessionId);

        $attribution = ClientAttribution::query()->where('client_id', $client->id)->sole();
        self::assertSame('utm', $attribution->source_type);
        self::assertSame('Newsletter', $attribution->utm_source);

        app(CapturePreAuthAttribution::class)->handle($sessionId, [
            'utm_source' => 'later-source',
            'utm_campaign' => 'later-campaign',
        ]);
        self::assertSame('Newsletter', $attribution->fresh()->utm_source);
    }

    public function test_manual_source_is_offered_only_when_automatic_attribution_is_absent(): void
    {
        $organization = $this->organizationWithClientRecords();
        $automaticClient = Client::factory()->forOrganization($organization)->create();
        ClientAttribution::query()->forceCreate([
            'organization_id' => $organization->id,
            'client_id' => $automaticClient->id,
            'source_type' => 'utm',
            'utm_source' => 'newsletter',
            'capture_channel' => 'portal',
            'captured_at' => now(),
            'accepted_at' => now(),
        ]);
        $this->withSession(['client_portal.client_id' => $automaticClient->id]);
        $this->get(route('portal.home'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('attribution.needsManualSource', false));

        $manualClient = Client::factory()->forOrganization($organization)->create(['lead_source' => null]);
        $this->withSession(['client_portal.client_id' => $manualClient->id]);
        $this->get(route('portal.home'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('attribution.needsManualSource', true));
        $this->post(route('portal.attribution.update'), ['source' => 'social'])
            ->assertRedirect(route('portal.home'));

        self::assertSame('manual', ClientAttribution::query()->where('client_id', $manualClient->id)->value('source_type'));
        $this->post(route('portal.attribution.update'), ['source' => 'search'])
            ->assertRedirect(route('portal.home'));
        self::assertSame('social', ClientAttribution::query()->where('client_id', $manualClient->id)->value('source'));
    }

    public function test_acquisition_retry_after_client_commit_finalizes_the_original_intent_once(): void
    {
        $organization = $this->organizationWithClientRecords();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $identity = app(EnsureReferralIdentity::class)->handle($referrer);
        $sessionId = 'm11a-crash-retry-session';
        app(CapturePreAuthAttribution::class)->handle(
            sessionId: $sessionId,
            input: ['referral_code' => $identity->public_code],
            captureChannel: 'portal',
            captureContext: 'referral_route',
        );
        $referred = Client::factory()->forOrganization($organization)->create(['lead_source' => null]);
        app(RegisterClientAcquisition::class)->handle($organization, $referred, $sessionId);

        $retryClient = Client::query()->whereKey($referred->getKey())->firstOrFail();
        app(FinalizeClientAcquisition::class)->handle($retryClient, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($retryClient, $sessionId);

        self::assertSame(1, ReferralRelationship::query()->where('referred_client_id', $referred->getKey())->count());
        self::assertSame('referral', ClientAttribution::query()->where('client_id', $referred->getKey())->value('source_type'));
        self::assertNotNull(DB::table('client_acquisition_registrations')->where('client_id', $referred->getKey())->value('finalized_at'));
    }

    public function test_existing_client_cannot_acquire_from_a_later_login(): void
    {
        $organization = $this->organizationWithClientRecords();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $identity = app(EnsureReferralIdentity::class)->handle($referrer);
        $client = Client::factory()->forOrganization($organization)->create();
        $sessionId = 'm11a-returning-client-session';
        app(CapturePreAuthAttribution::class)->handle(
            sessionId: $sessionId,
            input: ['referral_code' => $identity->public_code],
            captureChannel: 'portal',
            captureContext: 'referral_route',
        );

        app(FinalizeClientAcquisition::class)->handle($client, $sessionId);

        self::assertDatabaseMissing('referral_relationships', ['referred_client_id' => $client->getKey()]);
        self::assertDatabaseMissing('client_attributions', ['client_id' => $client->getKey()]);
    }

    public function test_manual_referral_assignment_is_authorized_product_neutral_and_does_not_rewrite_first_touch(): void
    {
        $organization = $this->organizationWithClientRecords();
        $actor = User::factory()->forOrganization($organization)->create();
        $referrer = Client::factory()->forOrganization($organization)->create(['full_name' => 'Реферер']);
        $referred = Client::factory()->forOrganization($organization)->create(['full_name' => 'Клиент']);
        ClientAttribution::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $referred->getKey(),
            'source_type' => 'utm',
            'utm_source' => 'OriginalCampaign',
            'capture_channel' => 'portal',
            'captured_at' => now(),
            'accepted_at' => now(),
        ]);

        $relationship = app(EstablishManualReferralRelationship::class)->handle(
            actor: $actor,
            referrerClientId: $referrer->getKey(),
            referredClientId: $referred->getKey(),
        );

        self::assertSame(ReferralEstablishmentMethod::ManualCrm, $relationship->establishment_method);
        self::assertSame('OriginalCampaign', ClientAttribution::query()->where('client_id', $referred->getKey())->value('utm_source'));
        self::assertSame(1, DB::table('audit_events')->where('action', 'referral.relationship.created')->count());

        $this->expectException(ValidationException::class);
        app(EstablishManualReferralRelationship::class)->handle($actor, $referrer->getKey(), $referred->getKey());
    }

    public function test_manual_referral_rejects_self_and_foreign_clients(): void
    {
        $organization = $this->organizationWithClientRecords();
        $actor = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();

        try {
            app(EstablishManualReferralRelationship::class)->handle($actor, $client->getKey(), $client->getKey());
            self::fail('A client must not refer itself.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        $this->expectException(ModelNotFoundException::class);
        app(EstablishManualReferralRelationship::class)->handle($actor, $foreignClient->getKey(), $client->getKey());
    }

    public function test_manual_referral_does_not_contradict_an_accepted_referral_first_touch(): void
    {
        $organization = $this->organizationWithClientRecords();
        $actor = User::factory()->forOrganization($organization)->create();
        $firstReferrer = Client::factory()->forOrganization($organization)->create();
        $manualReferrer = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create();
        $identity = app(EnsureReferralIdentity::class)->handle($firstReferrer);
        ClientAttribution::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $referred->getKey(),
            'source_type' => 'referral',
            'referral_code' => $identity->public_code,
            'capture_channel' => 'portal',
            'captured_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(EstablishManualReferralRelationship::class)->handle(
            actor: $actor,
            referrerClientId: $manualReferrer->getKey(),
            referredClientId: $referred->getKey(),
        );
    }

    public function test_feedback_fingerprint_is_keyed_and_same_key_different_payload_conflicts(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create();
        $fingerprint = app(FeedbackRequestFingerprint::class)->handle([
            'client_id' => $client->getKey(),
            'score' => 4,
            'internal_feedback' => 'Private feedback',
            'source' => 'portal',
        ]);
        self::assertNotSame(hash('sha256', json_encode([
            'client_id' => $client->getKey(),
            'score' => 4,
            'internal_feedback' => 'Private feedback',
            'source' => 'portal',
        ], JSON_THROW_ON_ERROR)), $fingerprint);
        self::assertStringNotContainsString('Private feedback', $fingerprint);

        $this->withSession(['client_portal.client_id' => $client->getKey()]);
        $this->post(route('portal.feedback.store'), [
            'score' => 9,
            'idempotency_key' => 'm11a-hmac-conflict',
        ])->assertRedirect(route('portal.feedback'));
        $this->post(route('portal.feedback.store'), [
            'score' => 8,
            'idempotency_key' => 'm11a-hmac-conflict',
        ])->assertInvalid('idempotency_key');
    }

    public function test_referral_link_registration_is_same_organization_first_wins_and_idempotent(): void
    {
        $organization = $this->organizationWithClientRecords();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $identity = app(EnsureReferralIdentity::class)->handle($referrer);
        $this->get(route('portal.referral', ['referralCode' => $identity->public_code]))->assertRedirect(route('portal.home'));
        $sessionId = session()->getId();
        $referred = Client::factory()->forOrganization($organization)->create(['lead_source' => null]);

        app(RegisterClientAcquisition::class)->handle($organization, $referred, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($referred, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($referred, $sessionId);

        self::assertSame(1, ReferralRelationship::query()->where('referred_client_id', $referred->id)->count());
        self::assertSame('referral', ClientAttribution::query()->where('client_id', $referred->id)->value('source_type'));
        self::assertSame(1, ClientReferralIdentity::query()->where('client_id', $referred->id)->count());

        $this->withSession(['client_portal.client_id' => $referrer->id])
            ->get(route('portal.referrals'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Referrals')
                ->where('referrals.link', route('portal.referral', ['referralCode' => $identity->public_code])));
    }

    public function test_portal_referral_projection_exposes_neutral_finance_evidence_without_reward_fields(): void
    {
        $organization = $this->organizationWithClientRecords();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create(['full_name' => 'Приглашённый']);
        $relationship = new ReferralRelationship;
        $relationship->forceFill([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $referrer->getKey(),
            'referred_client_id' => $referred->getKey(),
            'establishment_method' => ReferralEstablishmentMethod::ManualCrm,
            'registered_at' => now(),
        ])->save();

        $this->withSession(['client_portal.client_id' => $referrer->getKey()])
            ->get(route('portal.referrals'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Referrals')
                ->where('referrals.registrations.0.name', 'Приглашённый')
                ->where('referrals.registrations.0.financeEvidenceRecorded', false)
                ->missing('referrals.registrations.0.reward')
                ->missing('referrals.registrations.0.bonus')
                ->missing('referrals.registrations.0.points')
                ->missing('referrals.registrations.0.payout')
                ->missing('referrals.registrations.0.conversionQualified'));
    }

    public function test_foreign_and_self_referrals_fail_closed(): void
    {
        $organization = $this->organizationWithClientRecords();
        $otherOrganization = Organization::factory()->create();
        $otherReferrer = Client::factory()->forOrganization($otherOrganization)->create();
        app(OrganizationContext::class)->set($otherOrganization);
        $foreignIdentity = app(EnsureReferralIdentity::class)->handle($otherReferrer);
        app(OrganizationContext::class)->set($organization);
        $client = Client::factory()->forOrganization($organization)->create(['lead_source' => null]);
        $this->get(route('portal.referral', ['referralCode' => $foreignIdentity->public_code]))->assertRedirect();
        $sessionId = session()->getId();
        app(RegisterClientAcquisition::class)->handle($organization, $client, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($client, $sessionId);
        self::assertSame(0, ReferralRelationship::query()->where('referred_client_id', $client->id)->count());

        $selfSessionId = 'm11a-self-referral-session';
        $ownIdentity = app(EnsureReferralIdentity::class)->handle($client);
        app(CapturePreAuthAttribution::class)->handle(
            sessionId: $selfSessionId,
            input: ['referral_code' => $ownIdentity->public_code],
            captureChannel: 'portal',
            captureContext: 'referral_route',
        );
        app(FinalizeClientAcquisition::class)->handle($client, $selfSessionId);
        self::assertSame(0, ReferralRelationship::query()->where('referred_client_id', $client->id)->count());
    }

    public function test_feedback_high_and_low_flows_are_idempotent_and_text_is_encrypted(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create();
        $admin = User::factory()->forOrganization($organization)->create();
        app(SaveFeedbackConfiguration::class)->handle(
            actor: $admin,
            enabled: true,
            positiveThreshold: 8,
            lowScoreFeedbackRequired: true,
            reviewUrlRu: 'https://reviews.example.test/ru',
            reviewUrlEn: 'https://reviews.example.test/en',
        );
        $this->withSession(['client_portal.client_id' => $client->id]);
        $this->get(route('portal.feedback'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Feedback')
                ->where('feedback.positiveThreshold', 8));

        $this->post(route('portal.feedback.store'), [
            'score' => 9,
            'idempotency_key' => 'feedback-high',
        ])->assertRedirect(route('portal.feedback'));
        $this->assertDatabaseHas('feedback_submissions', ['client_id' => $client->id, 'score' => 9]);

        $this->post(route('portal.feedback.store'), [
            'score' => 4,
            'idempotency_key' => 'feedback-low',
        ])->assertInvalid('internal_feedback');
        $this->post(route('portal.feedback.store'), [
            'score' => 4,
            'internal_feedback' => 'Слишком долго ждал',
            'idempotency_key' => 'feedback-low',
        ])->assertRedirect(route('portal.feedback'));
        $this->post(route('portal.feedback.store'), [
            'score' => 4,
            'internal_feedback' => 'Слишком долго ждал',
            'idempotency_key' => 'feedback-low',
        ])->assertRedirect(route('portal.feedback'));

        $submission = FeedbackSubmission::query()->where('idempotency_key', 'feedback-low')->sole();
        self::assertSame('Слишком долго ждал', $submission->internal_feedback);
        self::assertStringNotContainsString('Слишком долго ждал', (string) DB::table('feedback_submissions')->whereKey($submission->id)->value('internal_feedback'));
        self::assertStringNotContainsString('Слишком долго ждал', DB::table('audit_events')->pluck('metadata')->implode('|'));
        self::assertSame(2, FeedbackSubmission::query()->where('client_id', $client->id)->count());
    }

    public function test_feedback_configuration_rejects_non_https_review_urls_without_fetching_them(): void
    {
        $organization = $this->organizationWithClientRecords();
        $admin = User::factory()->forOrganization($organization)->create();

        $this->expectException(ValidationException::class);
        app(SaveFeedbackConfiguration::class)->handle(
            actor: $admin,
            enabled: true,
            positiveThreshold: 8,
            lowScoreFeedbackRequired: true,
            reviewUrlRu: 'http://127.0.0.1/private',
            reviewUrlEn: null,
        );
    }

    public function test_portal_drops_a_forged_foreign_client_session(): void
    {
        $organization = $this->organizationWithClientRecords();
        $otherOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($otherOrganization)->create();

        $this->withSession(['client_portal.client_id' => $foreignClient->getKey()])
            ->get(route('portal.home'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('Portal/Entry'))
            ->assertSessionMissing('client_portal.client_id');
    }

    public function test_profile_updates_cannot_mutate_legacy_attribution_fields(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create(['lead_source' => 'verified-source']);

        $this->expectException(\InvalidArgumentException::class);
        app(UpdateClientProfileFromPortal::class)->handle($client, ['lead_source' => 'forged'], []);
    }

    public function test_legacy_attribution_adoption_is_bounded_repeatable_and_preserves_compatibility_fields(): void
    {
        $organization = $this->organizationWithClientRecords();
        $first = Client::factory()->forOrganization($organization)->create([
            'lead_source' => 'old-campaign',
            'referral_code' => 'legacy-code',
        ]);
        $second = Client::factory()->forOrganization($organization)->create(['lead_source' => 'old-partner']);

        Artisan::call('clients:adopt-attribution', ['--limit' => 1]);
        self::assertSame(1, ClientAttribution::query()->count());
        self::assertSame('legacy', ClientAttribution::query()->value('source_type'));
        self::assertSame('legacy-code', $first->fresh()->referral_code);

        Artisan::call('clients:adopt-attribution', ['--limit' => 100]);
        self::assertSame(2, ClientAttribution::query()->count());
        self::assertSame(1, ClientAttribution::query()->where('client_id', $first->getKey())->count());
        self::assertSame(1, ClientAttribution::query()->where('client_id', $second->getKey())->count());

        Artisan::call('clients:adopt-referral-identities', ['--limit' => 1]);
        self::assertSame(1, ClientReferralIdentity::query()->count());
        Artisan::call('clients:adopt-referral-identities', ['--limit' => 100]);
        self::assertSame(2, ClientReferralIdentity::query()->count());
    }

    public function test_crm_queries_are_explicitly_scoped_to_the_current_organization(): void
    {
        $organization = $this->organizationWithClientRecords();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create();
        $otherReferrer = Client::factory()->forOrganization($otherOrganization)->create();
        $otherReferred = Client::factory()->forOrganization($otherOrganization)->create();
        $identity = new ClientReferralIdentity;
        $identity->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $referrer->getKey(),
            'public_code' => str_repeat('A', 32),
        ])->save();
        $otherIdentity = new ClientReferralIdentity;
        $otherIdentity->forceFill([
            'organization_id' => $otherOrganization->getKey(),
            'client_id' => $otherReferrer->getKey(),
            'public_code' => str_repeat('B', 32),
        ])->save();
        $relationship = new ReferralRelationship;
        $relationship->forceFill([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $referrer->getKey(),
            'referred_client_id' => $referred->getKey(),
            'establishment_method' => 'automatic_referral_link',
            'registered_at' => now(),
        ])->save();
        $otherRelationship = new ReferralRelationship;
        $otherRelationship->forceFill([
            'organization_id' => $otherOrganization->getKey(),
            'referrer_client_id' => $otherReferrer->getKey(),
            'referred_client_id' => $otherReferred->getKey(),
            'establishment_method' => 'automatic_referral_link',
            'registered_at' => now(),
        ])->save();
        FeedbackSubmission::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $referred->getKey(),
            'score' => 9,
            'source' => 'portal',
            'idempotency_key' => 'crm-a',
            'request_hash' => hash('sha256', 'crm-a'),
            'submitted_at' => now(),
        ]);
        FeedbackSubmission::query()->forceCreate([
            'organization_id' => $otherOrganization->getKey(),
            'client_id' => $otherReferred->getKey(),
            'score' => 4,
            'source' => 'portal',
            'idempotency_key' => 'crm-b',
            'request_hash' => hash('sha256', 'crm-b'),
            'submitted_at' => now(),
        ]);

        $relationships = app(ListReferralRelationshipsForCrm::class)->query($admin)->get();
        $feedback = app(ListFeedbackSubmissionsForCrm::class)->query($admin)->get();

        self::assertCount(1, $relationships);
        self::assertSame($organization->getKey(), $relationships->sole()->organization_id);
        self::assertCount(1, $feedback);
        self::assertSame($organization->getKey(), $feedback->sole()->organization_id);
    }

    private function organizationWithClientRecords(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        return $organization;
    }
}
