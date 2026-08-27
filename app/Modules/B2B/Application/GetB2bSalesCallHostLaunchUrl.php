<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Infrastructure\Video\VideoMeetingException;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class GetB2bSalesCallHostLaunchUrl
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly VideoMeetingProvider $provider,
    ) {}

    public function handle(User $actor, B2bSalesCall $salesCall): string
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageB2bLeads);

        if ((int) $salesCall->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The sales call is outside the current organization.');
        }

        $identity = $salesCall->providerIdentity();

        if ($salesCall->status !== B2bSalesCallStatus::Scheduled
            || $salesCall->meeting_mode !== VideoMeetingMode::Automatic
            || $identity === null) {
            throw ValidationException::withMessages([
                'provider' => 'A host link is available only for a scheduled Zoom sales call.',
            ]);
        }

        try {
            $hostUrl = $this->provider->obtainHostLaunchUrl(
                $organization,
                $identity,
                ProviderOperationDeadline::fromNow((int) config('b2b.provider.operation_deadline_seconds', 90)),
            );

            if ($this->provider->name() !== 'zoom' || ! $this->isAllowedZoomHostUrl($hostUrl)) {
                throw ValidationException::withMessages([
                    'provider' => 'The Zoom host link is invalid. Retry from the CRM.',
                ]);
            }

            return $hostUrl;
        } catch (VideoMeetingException $exception) {
            if ($exception->safeCode === 'zoom_host_url_404') {
                app(MarkB2bSalesCallProviderReconciliationRequired::class)->handle(
                    actor: $actor,
                    salesCall: $salesCall,
                    identity: $identity,
                    errorCode: $exception->safeCode,
                );

                throw ValidationException::withMessages([
                    'provider' => 'The Zoom meeting is no longer available. Reconcile or recreate it before launching.',
                ]);
            }

            throw ValidationException::withMessages([
                'provider' => 'The Zoom host link is temporarily unavailable. Retry from the CRM.',
            ]);
        }
    }

    private function isAllowedZoomHostUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        return $host === 'zoom.us' || preg_match('/^[a-z0-9-]+\.zoom\.us$/', $host) === 1;
    }
}
