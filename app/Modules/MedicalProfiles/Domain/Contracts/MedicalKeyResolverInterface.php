<?php

namespace App\Modules\MedicalProfiles\Domain\Contracts;

interface MedicalKeyResolverInterface
{
    public function resolveKey(int $organizationId, int $keyVersion): string;

    public function getCurrentKeyVersion(int $organizationId): int;

    public function getCipher(int $organizationId): string;
}
