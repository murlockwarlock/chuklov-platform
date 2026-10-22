<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Contracts\EmailVerificationCodeSender;
use App\Modules\Identity\Domain\Models\ClientEmailAuthChallenge;
use App\Modules\Identity\Domain\ValueObjects\NormalizedEmail;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class RequestClientEmailVerificationCode
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationFeatureGate $features,
        private readonly EmailVerificationCodeSender $sender,
    ) {}

    public function handle(string $email): void
    {
        $organization = $this->context->organization();
        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $normalizedEmail = NormalizedEmail::from($email)->value;
        $rateLimitKey = $this->rateLimitKey($organization->getKey(), $normalizedEmail);
        $maxRequests = max(1, (int) config('portal.email_auth.request_limit', 5));
        $decay = max(1, (int) config('portal.email_auth.request_decay', 900));

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxRequests)) {
            Log::notice('client.email_auth.code_request_rate_limited', [
                'event_name' => 'client.email_auth.code_request_rate_limited',
                'operation' => 'client.email_auth.code_request',
                'organization_id' => (int) $organization->getKey(),
                'actor_type' => Auth::check() ? 'user' : 'anonymous',
                'actor_user_id' => Auth::id(),
                'entity_type' => 'client_email_auth_challenge',
                'entity_id' => null,
                'integration' => 'email_auth',
                'attempt' => RateLimiter::attempts($rateLimitKey),
                'error_code' => 'request_rate_limited',
                'error_class' => TooManyRequestsHttpException::class,
                'outcome' => 'failed',
                'occurred_at_utc' => now()->utc()->toIso8601String(),
            ]);

            throw new TooManyRequestsHttpException($decay, 'Please try again later.');
        }

        RateLimiter::hit($rateLimitKey, $decay);
        $code = (string) random_int(100000, 999999);
        $codeHash = Hash::make($code);
        $ttl = max(1, (int) config('portal.email_auth.code_ttl', 600));

        $challenge = DB::transaction(function () use ($organization, $normalizedEmail, $codeHash, $ttl): ClientEmailAuthChallenge {
            $challenge = ClientEmailAuthChallenge::query()
                ->where('organization_id', $organization->getKey())
                ->where('email', $normalizedEmail)
                ->lockForUpdate()
                ->first();

            if (! $challenge instanceof ClientEmailAuthChallenge) {
                $challenge = new ClientEmailAuthChallenge;
            }

            $challenge->forceFill([
                'organization_id' => $organization->getKey(),
                'email' => $normalizedEmail,
                'code_hash' => $codeHash,
                'attempts' => 0,
                'expires_at' => now()->addSeconds($ttl),
                'consumed_at' => null,
            ]);
            $challenge->save();

            return $challenge;
        });

        try {
            $this->sender->send($normalizedEmail, $code);
        } catch (Throwable $exception) {
            try {
                DB::transaction(function () use ($challenge, $codeHash): void {
                    $currentChallenge = ClientEmailAuthChallenge::query()
                        ->whereKey($challenge->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $currentChallenge instanceof ClientEmailAuthChallenge
                        || ! hash_equals((string) $currentChallenge->code_hash, $codeHash)) {
                        return;
                    }

                    $currentChallenge->forceFill(['consumed_at' => now()])->save();
                });
            } catch (Throwable $invalidationException) {
                Log::error('client.email_auth.challenge_invalidation_failed', [
                    'operation' => 'client.email_auth.code_delivery',
                    'organization_id' => $organization->getKey(),
                    'actor_type' => Auth::check() ? 'user' : 'anonymous',
                    'actor_user_id' => Auth::id(),
                    'entity_type' => 'client_email_auth_challenge',
                    'entity_id' => $challenge->getKey(),
                    'integration' => 'email_auth',
                    'error_code' => 'challenge_invalidation_failed',
                    'error_class' => $invalidationException::class,
                    'outcome' => 'failed',
                    'occurred_at_utc' => now()->utc()->toIso8601String(),
                ]);
            }

            Log::warning('client.email_auth.code_delivery_failed', [
                'operation' => 'client.email_auth.code_delivery',
                'organization_id' => $organization->getKey(),
                'actor_type' => Auth::check() ? 'user' : 'anonymous',
                'actor_user_id' => Auth::id(),
                'entity_type' => 'client_email_auth_challenge',
                'entity_id' => $challenge->getKey(),
                'integration' => 'email_auth',
                'attempt' => RateLimiter::attempts($rateLimitKey),
                'error_code' => match (true) {
                    $exception instanceof LogicException => 'mail_transport_configuration_invalid',
                    $exception instanceof TransportExceptionInterface => 'mail_provider_unavailable',
                    default => 'mail_delivery_failed',
                },
                'error_class' => $exception::class,
                'outcome' => 'failed',
                'occurred_at_utc' => now()->utc()->toIso8601String(),
            ]);

            throw new EmailVerificationCodeDeliveryFailed;
        }
    }

    private function rateLimitKey(int $organizationId, string $email): string
    {
        return 'client-email-auth:request:'.$organizationId.':'.hash('sha256', $email);
    }
}
