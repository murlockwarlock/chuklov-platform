<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Contracts\EmailVerificationCodeSender;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientEmailAuthChallenge;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Security\Domain\Models\AuditEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordingEmailVerificationCodeSender;
use Tests\TestCase;

class MilestoneTwoEmailAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_code_normalizes_email_creates_verified_identity_and_regenerates_session(): void
    {
        $organization = $this->organizationWithClientRecords();
        $sender = $this->fakeSender();
        $sessionId = session()->getId();

        $this->post(route('portal.email.request'), ['email' => 'Case@Example.test'])
            ->assertRedirect(route('portal.services.index'))
            ->assertSessionHas('email_code_sent', true);

        self::assertSame('case@example.test', $sender->email);
        self::assertIsString($sender->code);

        $this->post(route('portal.email.verify'), [
            'email' => 'CASE@EXAMPLE.TEST',
            'code' => $sender->code,
            'organization_id' => 900001,
            'client_id' => 900001,
        ])->assertRedirect(route('portal.onboarding'));

        $client = Client::query()->sole();
        $identity = ClientChannelIdentity::query()->sole();
        $challenge = ClientEmailAuthChallenge::query()->sole();

        self::assertSame('case@example.test', $client->email);
        self::assertNull($client->full_name);
        self::assertSame('email', $identity->channel);
        self::assertSame('case@example.test', $identity->external_id);
        self::assertSame(ChannelIdentityStatus::Verified, $identity->verification_status);
        self::assertNotNull($challenge->consumed_at);
        self::assertTrue(Hash::check($sender->code, $challenge->code_hash));
        self::assertSame($client->id, (int) session('client_portal.client_id'));
        self::assertNotSame($sessionId, session()->getId());
    }

    public function test_invalid_expired_and_replayed_codes_are_rejected(): void
    {
        $this->organizationWithClientRecords();
        $sender = $this->fakeSender();

        $this->post(route('portal.email.request'), ['email' => 'invalid@example.test']);
        $response = $this->post(route('portal.email.verify'), [
            'email' => 'invalid@example.test',
            'code' => '000000',
        ]);
        $this->assertCodeValidationError($response);
        $response->assertSessionHas('email_code_sent', true);
        self::assertSame(
            1,
            ClientEmailAuthChallenge::query()->where('email', 'invalid@example.test')->sole()->attempts,
        );

        $this->post(route('portal.email.request'), ['email' => 'expired@example.test']);
        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertCodeValidationError($this->post(route('portal.email.verify'), [
            'email' => 'expired@example.test',
            'code' => $sender->code,
        ]));
        Carbon::setTestNow();

        $this->post(route('portal.email.request'), ['email' => 'replay@example.test']);
        $code = $sender->code;
        $this->post(route('portal.email.verify'), [
            'email' => 'replay@example.test',
            'code' => $code,
        ])->assertRedirect(route('portal.onboarding'));
        $this->assertCodeValidationError($this->post(route('portal.email.verify'), [
            'email' => 'replay@example.test',
            'code' => $code,
        ]));
    }

    public function test_attempts_are_bounded_and_request_rate_is_limited(): void
    {
        $this->organizationWithClientRecords();
        $this->fakeSender();
        config()->set('portal.email_auth.max_attempts', 2);
        config()->set('portal.email_auth.request_limit', 1);

        $this->post(route('portal.email.request'), ['email' => 'bounded@example.test'])
            ->assertRedirect();
        $this->assertCodeValidationError($this->post(route('portal.email.verify'), [
            'email' => 'bounded@example.test',
            'code' => '000000',
        ]));
        $this->assertCodeValidationError($this->post(route('portal.email.verify'), [
            'email' => 'bounded@example.test',
            'code' => '111111',
        ]));
        $this->assertCodeValidationError($this->post(route('portal.email.verify'), [
            'email' => 'bounded@example.test',
            'code' => '222222',
        ]));

        self::assertSame(2, ClientEmailAuthChallenge::query()->sole()->attempts);
        $this->post(route('portal.email.request'), ['email' => 'bounded@example.test'])
            ->assertTooManyRequests();
    }

    public function test_email_auth_does_not_merge_an_unverified_profile_or_persist_secrets(): void
    {
        $organization = $this->organizationWithClientRecords();
        $existing = Client::factory()->forOrganization($organization)->create([
            'email' => 'known@example.test',
        ]);
        $sender = $this->fakeSender();

        $this->post(route('portal.email.request'), ['email' => 'known@example.test']);
        $code = $sender->code;
        $this->post(route('portal.email.verify'), [
            'email' => 'known@example.test',
            'code' => $code,
        ])->assertRedirect(route('portal.onboarding'));

        self::assertCount(2, Client::query()->get());
        self::assertNotSame($existing->id, (int) session('client_portal.client_id'));
        self::assertStringNotContainsString($code, ClientEmailAuthChallenge::query()->where('email', 'known@example.test')->sole()->code_hash);
        self::assertStringNotContainsString(
            $code,
            ClientEmailAuthChallenge::query()->where('email', 'known@example.test')->sole()->toJson(),
        );
        self::assertStringNotContainsString($code, AuditEvent::query()->get()->toJson());
    }

    public function test_request_supplied_organization_and_client_ids_cannot_change_email_resolution(): void
    {
        $organization = $this->organizationWithClientRecords();
        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $sender = $this->fakeSender();

        $this->post(route('portal.email.request'), [
            'email' => 'server-scoped@example.test',
            'organization_id' => $otherOrganization->id,
            'client_id' => $otherClient->id,
        ]);
        $this->post(route('portal.email.verify'), [
            'email' => 'server-scoped@example.test',
            'code' => $sender->code,
            'organization_id' => $otherOrganization->id,
            'client_id' => $otherClient->id,
        ])->assertRedirect(route('portal.onboarding'));

        $resolved = Client::query()->where('email', 'server-scoped@example.test')->sole();
        self::assertSame($organization->id, $resolved->organization_id);
        self::assertNotSame($otherClient->id, (int) session('client_portal.client_id'));
    }

    private function fakeSender(): RecordingEmailVerificationCodeSender
    {
        $sender = new RecordingEmailVerificationCodeSender;
        $this->app->instance(
            EmailVerificationCodeSender::class,
            $sender,
        );

        return $sender;
    }

    private function assertCodeValidationError(TestResponse $response): void
    {
        $response->assertRedirect();
        $response->assertSessionHas('errors', static function (mixed $errors): bool {
            if ($errors instanceof ViewErrorBag) {
                return $errors->getBag('default')->has('code');
            }

            return is_array($errors)
                && isset($errors['default']['messages']['code'])
                && $errors['default']['messages']['code'] !== [];
        });
    }

    private function organizationWithClientRecords(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->id);

        return $organization;
    }
}
