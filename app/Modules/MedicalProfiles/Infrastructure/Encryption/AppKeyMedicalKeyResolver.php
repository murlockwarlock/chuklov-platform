<?php

namespace App\Modules\MedicalProfiles\Infrastructure\Encryption;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\MedicalProfiles\Domain\Exceptions\MedicalKeyNotFoundException;

final class AppKeyMedicalKeyResolver implements MedicalKeyResolverInterface
{
    public function resolveKey(int $organizationId, int $keyVersion): string
    {
        $versionKey = config("medical.organizations.{$organizationId}.keys.{$keyVersion}")
            ?? config("medical.keys.{$keyVersion}");

        if (is_string($versionKey) && $versionKey !== '') {
            return $this->parseKey($versionKey);
        }

        if ($keyVersion === 1) {
            $rootKey = config('medical.root_key');

            if (is_string($rootKey) && $rootKey !== '') {
                return $this->parseKey($rootKey);
            }
        }

        throw new MedicalKeyNotFoundException("Medical encryption key version {$keyVersion} not configured for organization {$organizationId}.");
    }

    public function getCurrentKeyVersion(int $organizationId): int
    {
        return (int) (config("medical.organizations.{$organizationId}.current_version")
            ?? config('medical.current_version', 1));
    }

    public function getCipher(int $organizationId): string
    {
        return (string) (config("medical.organizations.{$organizationId}.cipher")
            ?? config('medical.cipher', 'AES-256-CBC'));
    }

    private function parseKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }
}
