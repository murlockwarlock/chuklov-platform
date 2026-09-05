<?php

namespace App\Modules\Attribution\Application;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use Illuminate\Validation\ValidationException;

final readonly class AttributionSourceDetail
{
    public function __construct(
        private MedicalEncryptorInterface $encryptor,
        private MedicalKeyResolverInterface $keyResolver,
    ) {}

    public function normalize(mixed $detail): ?string
    {
        if ($detail === null) {
            return null;
        }
        if (! is_string($detail) || ! mb_check_encoding($detail, 'UTF-8') || mb_strlen($detail) > 500) {
            throw ValidationException::withMessages(['source_detail' => 'Укажите не более 500 символов.']);
        }
        $detail = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $detail));

        return $detail === '' ? null : $detail;
    }

    public function supports(?string $source): bool
    {
        return in_array(strtolower(trim($source ?? '')), ['friend', 'other'], true);
    }

    /** @return array{encrypted_source_detail: ?string, source_detail_key_version: ?int} */
    public function attributes(int $organizationId, ?string $source, mixed $detail): array
    {
        $detail = $this->normalize($detail);
        if ($detail !== null && ! $this->supports($source)) {
            throw ValidationException::withMessages(['source_detail' => 'Уточнение доступно для рекомендации знакомых или другого источника.']);
        }
        $version = $detail === null ? null : $this->keyResolver->getCurrentKeyVersion($organizationId);

        return [
            'encrypted_source_detail' => $this->encryptor->encryptField($organizationId, $detail, $version),
            'source_detail_key_version' => $version,
        ];
    }
}
