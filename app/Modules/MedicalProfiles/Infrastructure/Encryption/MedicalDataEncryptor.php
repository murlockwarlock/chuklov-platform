<?php

namespace App\Modules\MedicalProfiles\Infrastructure\Encryption;

use App\Modules\MedicalProfiles\Application\DTOs\MedicalProfileData;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\MedicalProfiles\Domain\Exceptions\MedicalDecryptionException;
use App\Modules\MedicalProfiles\Domain\Exceptions\MedicalEncryptionException;
use App\Modules\MedicalProfiles\Domain\ValueObjects\EncryptedMedicalPayload;
use Illuminate\Encryption\Encrypter;
use Throwable;

final readonly class MedicalDataEncryptor implements MedicalEncryptorInterface
{
    public function __construct(
        private MedicalKeyResolverInterface $keyResolver,
    ) {}

    public function encryptField(int $organizationId, ?string $plaintext, ?int $keyVersion = null): ?string
    {
        if ($plaintext === null) {
            return null;
        }

        $version = $keyVersion ?? $this->keyResolver->getCurrentKeyVersion($organizationId);
        $encrypter = $this->getEncrypter($organizationId, $version);

        try {
            return $encrypter->encryptString($plaintext);
        } catch (Throwable $e) {
            throw new MedicalEncryptionException("Failed to encrypt medical field: {$e->getMessage()}", previous: $e);
        }
    }

    public function decryptField(int $organizationId, ?string $ciphertext, int $keyVersion): ?string
    {
        if ($ciphertext === null) {
            return null;
        }

        $encrypter = $this->getEncrypter($organizationId, $keyVersion);

        try {
            return $encrypter->decryptString($ciphertext);
        } catch (Throwable $e) {
            throw new MedicalDecryptionException("Failed to decrypt medical field: {$e->getMessage()}", previous: $e);
        }
    }

    public function encryptProfile(int $organizationId, MedicalProfileData $data, ?int $keyVersion = null): EncryptedMedicalPayload
    {
        $version = $keyVersion ?? $this->keyResolver->getCurrentKeyVersion($organizationId);

        return new EncryptedMedicalPayload(
            encryptedAnamnesis: $this->encryptField($organizationId, $data->anamnesis, $version),
            encryptedComplaintsGoals: $this->encryptField($organizationId, $data->complaintsGoals, $version),
            encryptedOperationsInjuries: $this->encryptField($organizationId, $data->operationsInjuries, $version),
            encryptedMedicines: $this->encryptField($organizationId, $data->medicines, $version),
            encryptedSupplements: $this->encryptField($organizationId, $data->supplements, $version),
            keyVersion: $version,
        );
    }

    public function decryptProfile(int $organizationId, int $keyVersion, EncryptedMedicalPayload $payload): MedicalProfileData
    {
        return new MedicalProfileData(
            anamnesis: $this->decryptField($organizationId, $payload->encryptedAnamnesis, $keyVersion),
            complaintsGoals: $this->decryptField($organizationId, $payload->encryptedComplaintsGoals, $keyVersion),
            operationsInjuries: $this->decryptField($organizationId, $payload->encryptedOperationsInjuries, $keyVersion),
            medicines: $this->decryptField($organizationId, $payload->encryptedMedicines, $keyVersion),
            supplements: $this->decryptField($organizationId, $payload->encryptedSupplements, $keyVersion),
            encryptionKeyVersion: $keyVersion,
        );
    }

    private function getEncrypter(int $organizationId, int $keyVersion): Encrypter
    {
        $key = $this->keyResolver->resolveKey($organizationId, $keyVersion);
        $cipher = $this->keyResolver->getCipher($organizationId);

        return new Encrypter($key, $cipher);
    }
}
