<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Contracts\EmailVerificationCodeSender;
use App\Modules\Identity\Domain\Models\ClientEmailAuthChallenge;
use App\Modules\Identity\Domain\ValueObjects\NormalizedEmail;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

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
            throw new TooManyRequestsHttpException($decay, 'Please try again later.');
        }

        RateLimiter::hit($rateLimitKey, $decay);
        $code = (string) random_int(100000, 999999);
        $ttl = max(1, (int) config('portal.email_auth.code_ttl', 600));

        DB::transaction(function () use ($organization, $normalizedEmail, $code, $ttl): void {
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
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addSeconds($ttl),
                'consumed_at' => null,
            ]);
            $challenge->save();
        });

        $this->sender->send($normalizedEmail, $code);
    }

    private function rateLimitKey(int $organizationId, string $email): string
    {
        return 'client-email-auth:request:'.$organizationId.':'.hash('sha256', $email);
    }
}
