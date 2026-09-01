<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\ValueObjects\ProviderAccountAffinity;
use Illuminate\Validation\ValidationException;

final class GetB2bZoomProviderAffinity
{
    public function __construct(private readonly GetB2bZoomConfiguration $configuration) {}

    public function handle(): ProviderAccountAffinity
    {
        $configuration = $this->configuration->handle();

        if (! $configuration['configured']
            || ! is_string($configuration['accountId'])
            || ! is_string($configuration['hostUserId'])) {
            throw ValidationException::withMessages([
                'configuration' => 'An active, complete Zoom configuration is required for automatic sales calls.',
            ]);
        }

        return new ProviderAccountAffinity(
            accountId: $configuration['accountId'],
            hostUserId: $configuration['hostUserId'],
        );
    }
}
