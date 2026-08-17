<?php

namespace App\Modules\Security\Domain\Events;

final readonly class OrganizationCredentialReplaced
{
    public function __construct(
        public int $organizationId,
        public string $provider,
        public int $credentialId,
        public string $revisionId,
    ) {}
}
