<?php

namespace Tests\Feature;

use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\ClientPortal\Application\PortalClientMessages;
use App\Modules\Identity\Application\AuthenticateClientWithEmailVerificationCode;
use App\Modules\Identity\Domain\Contracts\EmailVerificationCodeSender;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientAcquisitionRegistration;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientEmailAuthChallenge;
use App\Modules\Identity\Infrastructure\Mail\LaravelEmailVerificationCodeSender;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Application\EnsureReferralIdentity;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Security\Domain\Models\AuditEvent;
use Carbon\Carbon;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\Mailer\Exception\TransportException;
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
            ->assertRedirect(route('portal.home'))
            ->assertSessionHas('email_code_sent', true);

        self::assertSame('case@example.test', $sender->email);
        self::assertIsString($sender->code);

        $this->post(route('portal.email.verify'), [
            'email' => 'CASE@EXAMPLE.TEST',
            'code' => $sender->code,
            'organization_id' => 900001,
            'client_id' => 900001,
        ])->assertRedirect(route('portal.home'));

        $client = Client::query()->sole();
        $identity = ClientChannelIdentity::query()->sole();
        $challenge = ClientEmailAuthChallenge::query()->sole();

        self::assertSame('case@example.test', $client->email);
        self::assertNull($client->full_name);
        self::assertSame('ru', $client->language);
        self::assertSame('email', $identity->channel);
        self::assertSame('case@example.test', $identity->external_id);
        self::assertSame(ChannelIdentityStatus::Verified, $identity->verification_status);
        self::assertNotNull($challenge->consumed_at);
        self::assertTrue(Hash::check($sender->code, $challenge->code_hash));
        self::assertSame($client->id, (int) session('client_portal.client_id'));
        self::assertNotSame($sessionId, session()->getId());
    }

    public function test_email_acquisition_registration_survives_a_finalization_boundary_and_returning_retry(): void
    {
        $organization = $this->organizationWithClientRecords();
        app(OrganizationContext::class)->set($organization);
        $referrer = Client::factory()->forOrganization($organization)->create();
        $identity = app(EnsureReferralIdentity::class)->handle($referrer);
        $sessionId = session()->getId();
        app(CapturePreAuthAttribution::class)->handle($sessionId, ['referral_code' => $identity->public_code]);
        $sender = $this->fakeSender();

        $this->post(route('portal.email.request'), ['email' => 'acquisition@example.test'])
            ->assertRedirect();
        $client = app(AuthenticateClientWithEmailVerificationCode::class)->handle(
            email: 'acquisition@example.test',
            code: (string) $sender->code,
            acquisitionSessionId: $sessionId,
        );
        $registration = ClientAcquisitionRegistration::query()->where('client_id', $client->getKey())->sole();

        self::assertNull($registration->finalized_at);
        self::assertSame(2, Client::query()->count());

        $retryClient = Client::query()->whereKey($client->getKey())->firstOrFail();
        app(FinalizeClientAcquisition::class)->handle($retryClient, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($retryClient, $sessionId);

        self::assertNotNull($registration->refresh()->finalized_at);
        self::assertSame(1, ReferralRelationship::query()->where('referred_client_id', $client->getKey())->count());

        $returningSessionId = 'email-returning-client-session';
        app(CapturePreAuthAttribution::class)->handle($returningSessionId, ['referral_code' => $identity->public_code]);
        $this->post(route('portal.email.request'), ['email' => 'acquisition@example.test'])
            ->assertRedirect();
        $returningClient = app(AuthenticateClientWithEmailVerificationCode::class)->handle(
            email: 'acquisition@example.test',
            code: (string) $sender->code,
            acquisitionSessionId: $returningSessionId,
        );

        self::assertSame($client->getKey(), $returningClient->getKey());
        self::assertSame(1, ClientAcquisitionRegistration::query()->where('client_id', $client->getKey())->count());
        self::assertSame(1, ReferralRelationship::query()->where('referred_client_id', $client->getKey())->count());
    }

    public function test_invalid_expired_and_replayed_codes_are_rejected(): void
    {
        $this->organizationWithClientRecords();
        $sender = $this->fakeSender();
        Log::spy();

        $this->assertCodeValidationError($this->post(route('portal.email.verify'), [
            'email' => 'missing@example.test',
            'code' => '123456',
        ]));

        $this->post(route('portal.email.request'), ['email' => 'invalid@example.test']);
        $invalidChallenge = ClientEmailAuthChallenge::query()->where('email', 'invalid@example.test')->sole();
        $invalidCode = $sender->code;
        $response = $this->post(route('portal.email.verify'), [
            'email' => 'invalid@example.test',
            'code' => $invalidCode === '000000' ? '000001' : '000000',
        ]);
        $this->assertCodeValidationError($response);
        $response->assertSessionHas('email_code_sent', true);
        self::assertSame(
            1,
            ClientEmailAuthChallenge::query()->where('email', 'invalid@example.test')->sole()->attempts,
        );

        $this->post(route('portal.email.request'), ['email' => 'expired@example.test']);
        $expiredCode = $sender->code;
        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertCodeValidationError($this->post(route('portal.email.verify'), [
            'email' => 'expired@example.test',
            'code' => $expiredCode,
        ]));
        Carbon::setTestNow();

        $this->post(route('portal.email.request'), ['email' => 'replay@example.test']);
        $code = $sender->code;
        $this->post(route('portal.email.verify'), [
            'email' => 'replay@example.test',
            'code' => $code,
        ])->assertRedirect(route('portal.home'));
        $this->assertCodeValidationError($this->post(route('portal.email.verify'), [
            'email' => 'replay@example.test',
            'code' => $code,
        ]));

        $errorCodes = ['challenge_missing', 'code_mismatch', 'challenge_expired', 'challenge_consumed'];

        foreach ($errorCodes as $errorCode) {
            Log::shouldHaveReceived('warning')->with(
                'client.email_auth.code_verification_failed',
                \Mockery::on(function (array $context) use ($errorCode, $invalidChallenge, $invalidCode, $expiredCode, $code): bool {
                    if (($context['error_code'] ?? null) !== $errorCode) {
                        return false;
                    }

                    $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                    self::assertSame('anonymous', $context['actor_type']);
                    self::assertSame('client_email_auth_challenge', $context['entity_type']);
                    self::assertSame('email_auth', $context['integration']);
                    self::assertSame('failed', $context['outcome']);
                    self::assertArrayHasKey('occurred_at_utc', $context);
                    self::assertArrayNotHasKey('email', $context);
                    self::assertArrayNotHasKey('code', $context);
                    self::assertArrayNotHasKey('code_hash', $context);
                    self::assertStringNotContainsString('missing@example.test', $encoded);
                    self::assertStringNotContainsString('invalid@example.test', $encoded);
                    self::assertStringNotContainsString('expired@example.test', $encoded);
                    self::assertStringNotContainsString('replay@example.test', $encoded);
                    self::assertStringNotContainsString($invalidCode, $encoded);
                    self::assertStringNotContainsString($expiredCode, $encoded);
                    self::assertStringNotContainsString($code, $encoded);

                    if ($errorCode === 'code_mismatch') {
                        self::assertSame($invalidChallenge->getKey(), $context['entity_id']);
                        self::assertSame(1, $context['attempt']);
                    }

                    return true;
                }),
            )->once();
        }
    }

    public function test_attempts_are_bounded_and_request_rate_is_limited(): void
    {
        $organization = $this->organizationWithClientRecords();
        $this->fakeSender();
        Log::spy();
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
            ->assertRedirect(route('portal.home'))
            ->assertSessionHasErrors([
                'email' => app(PortalClientMessages::class)->message('email_code_rate_limited'),
            ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $eventName, array $context): bool => $eventName === 'client.email_auth.code_request_rate_limited'
                && $context['organization_id'] === $organization->id
                && $context['actor_type'] === 'anonymous'
                && $context['error_code'] === 'request_rate_limited'
                && $context['outcome'] === 'failed'
                && isset($context['occurred_at_utc'])
                && ! array_key_exists('email', $context))
            ->once();

        Log::shouldHaveReceived('warning')
            ->with('client.email_auth.code_verification_failed', \Mockery::on(static fn (array $context): bool => ($context['error_code'] ?? null) === 'attempt_limit'
                && ($context['attempt'] ?? null) === 2
                && ($context['outcome'] ?? null) === 'failed'))
            ->once();
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
        ])->assertRedirect(route('portal.home'));

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
        ])->assertRedirect(route('portal.home'));

        $resolved = Client::query()->where('email', 'server-scoped@example.test')->sole();
        self::assertSame($organization->id, $resolved->organization_id);
        self::assertNotSame($otherClient->id, (int) session('client_portal.client_id'));
    }

    public function test_configured_log_mailer_cannot_receive_an_authentication_code(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'm2-auth-mail-');
        self::assertIsString($logPath);
        config()->set('mail.default', 'log');
        config()->set('mail.auth_mailer', 'log');
        config()->set('mail.mailers.log.channel', 'm2-auth-mail');
        config()->set('logging.channels.m2-auth-mail', [
            'driver' => 'single',
            'path' => $logPath,
            'level' => 'debug',
        ]);
        Mail::forgetMailers();

        try {
            $this->expectException(LogicException::class);
            app(LaravelEmailVerificationCodeSender::class)->send('auth@example.test', '654321');
        } finally {
            $logContents = is_file($logPath) ? (string) file_get_contents($logPath) : '';
            self::assertStringNotContainsString('654321', $logContents);
            unlink($logPath);
        }
    }

    public function test_delivery_failure_is_reported_safely_and_invalidates_the_unsent_code(): void
    {
        $organization = $this->organizationWithClientRecords();
        $sender = new class implements EmailVerificationCodeSender
        {
            public ?string $email = null;

            public ?string $code = null;

            public function send(string $email, string $code): void
            {
                $this->email = $email;
                $this->code = $code;

                throw new TransportException('private provider details '.$email.' '.$code);
            }
        };
        $this->app->instance(EmailVerificationCodeSender::class, $sender);
        Log::spy();

        $this->post(route('portal.email.request'), ['email' => 'qa-mail-failure@example.test'])
            ->assertRedirect(route('portal.home'))
            ->assertSessionHasErrors([
                'email' => app(PortalClientMessages::class)->message('email_code_delivery_failed'),
            ])
            ->assertSessionMissing('email_code_sent');

        $challenge = ClientEmailAuthChallenge::query()->sole();
        self::assertSame($organization->id, $challenge->organization_id);
        self::assertNotNull($challenge->consumed_at);
        self::assertIsString($sender->email);
        self::assertIsString($sender->code);
        self::assertTrue(Hash::check($sender->code, $challenge->code_hash));

        $this->post(route('portal.email.verify'), [
            'email' => $sender->email,
            'code' => $sender->code,
        ])->assertSessionHasErrors('code');

        $warningEvents = [];
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $eventName, array $context) use ($organization, $challenge, $sender, &$warningEvents): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);
                self::assertSame('failed', $context['outcome']);
                self::assertArrayHasKey('occurred_at_utc', $context);
                self::assertStringNotContainsString((string) $sender->email, $encoded);
                self::assertStringNotContainsString((string) $sender->code, $encoded);

                if ($eventName === 'client.email_auth.code_delivery_failed') {
                    self::assertSame($organization->id, $context['organization_id']);
                    self::assertSame('anonymous', $context['actor_type']);
                    self::assertSame('client_email_auth_challenge', $context['entity_type']);
                    self::assertSame('email_auth', $context['integration']);
                    self::assertSame('mail_provider_unavailable', $context['error_code']);
                    $warningEvents['delivery'] = true;

                    return true;
                }

                if ($eventName === 'client.email_auth.code_verification_failed') {
                    self::assertSame($challenge->getKey(), $context['entity_id']);
                    self::assertSame('challenge_consumed', $context['error_code']);
                    self::assertArrayNotHasKey('email', $context);
                    self::assertArrayNotHasKey('code', $context);
                    $warningEvents['verification'] = true;

                    return true;
                }

                return false;
            })
            ->twice();
        self::assertSame(['delivery' => true, 'verification' => true], $warningEvents);
    }

    public function test_non_delivering_array_transport_does_not_report_success_outside_testing(): void
    {
        $organization = $this->organizationWithClientRecords();
        $this->app->detectEnvironment(static fn (): string => 'staging');
        config()->set('mail.auth_mailer', 'array');
        Mail::forgetMailers();
        Log::spy();

        $this->withHeader('Sec-Fetch-Site', 'same-origin')
            ->post(route('portal.email.request'), ['email' => 'array-mailer@example.test'])
            ->assertRedirect(route('portal.home'))
            ->assertSessionHasErrors([
                'email' => app(PortalClientMessages::class)->message('email_code_delivery_failed'),
            ])
            ->assertSessionMissing('email_code_sent');

        $challenge = ClientEmailAuthChallenge::query()->sole();
        self::assertSame($organization->id, $challenge->organization_id);
        self::assertNotNull($challenge->consumed_at);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('client.email_auth.code_delivery_failed', \Mockery::on(static function (array $context): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                self::assertSame('client.email_auth.code_delivery', $context['operation']);
                self::assertSame('mail_transport_configuration_invalid', $context['error_code']);
                self::assertSame('failed', $context['outcome']);
                self::assertArrayHasKey('occurred_at_utc', $context);
                self::assertArrayNotHasKey('email', $context);
                self::assertArrayNotHasKey('code', $context);
                self::assertStringNotContainsString('array-mailer@example.test', $encoded);

                return true;
            }));
    }

    public function test_reserved_qa_domain_uses_the_dedicated_mailer_only_when_enabled(): void
    {
        config()->set('mail.qa_auth.enabled', true);
        config()->set('mail.qa_auth.domain', 'qa.invalid');
        config()->set('mail.mailers.qa_auth.transport', 'array');
        config()->set('mail.mailers.ordinary_auth.transport', 'array');
        config()->set('mail.auth_mailer', 'ordinary_auth');
        Mail::forgetMailers();
        $mailer = Mail::mailer('qa_auth');
        $factory = \Mockery::mock(MailFactory::class);
        $factory->shouldReceive('mailer')->once()->with('qa_auth')->andReturn($mailer);

        (new LaravelEmailVerificationCodeSender($factory))->send('Portal-QA@QA.INVALID', '123456');
    }

    public function test_normal_addresses_and_disabled_qa_addresses_use_the_configured_auth_mailer(): void
    {
        config()->set('mail.qa_auth.enabled', false);
        config()->set('mail.qa_auth.domain', 'qa.invalid');
        config()->set('mail.mailers.ordinary_auth.transport', 'array');
        config()->set('mail.auth_mailer', 'ordinary_auth');
        Mail::forgetMailers();
        $mailer = Mail::mailer('ordinary_auth');
        $factory = \Mockery::mock(MailFactory::class);
        $factory->shouldReceive('mailer')->times(3)->with('ordinary_auth')->andReturn($mailer);
        $sender = new LaravelEmailVerificationCodeSender($factory);

        $sender->send('client@example.test', '123456');
        $sender->send('portal-qa@qa.invalid', '234567');

        config()->set('mail.qa_auth.enabled', true);
        config()->set('mail.qa_auth.domain', 'example.test');
        $sender->send('portal-qa@example.test', '345678');
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
