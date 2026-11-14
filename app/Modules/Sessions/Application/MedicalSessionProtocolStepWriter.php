<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionProtocolStep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MedicalSessionProtocolStepWriter
{
    public function __construct(private MedicalEncryptorInterface $encryptor) {}

    /** @param array<int, mixed> $rawSteps */
    public function replace(MedicalSession $session, array $rawSteps, int $keyVersion): void
    {
        $existing = $session->protocolSteps()->lockForUpdate()->get()->keyBy('lineage_key');
        $normalized = [];
        $lineages = [];

        foreach (array_values($rawSteps) as $index => $rawStep) {
            if (! is_array($rawStep)) {
                throw ValidationException::withMessages(['protocol_steps' => 'Проверьте шаги протокола.']);
            }
            $text = trim((string) ($rawStep['text'] ?? $rawStep['step_text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > MedicalSessionFieldLimits::MaxTextLength) {
                throw ValidationException::withMessages(['protocol_steps' => 'Текст шага слишком длинный.']);
            }
            $lineage = (string) ($rawStep['lineage_key'] ?? '');
            if (! Str::isUuid($lineage)) {
                $lineage = (string) Str::uuid();
            }
            if (isset($lineages[$lineage])) {
                throw ValidationException::withMessages(['protocol_steps' => 'Шаги протокола должны быть уникальными.']);
            }
            $lineages[$lineage] = true;
            $normalized[] = [$lineage, $text, $index];
        }

        foreach ($existing as $lineage => $step) {
            if (! isset($lineages[$lineage])) {
                $step->delete();
            }
        }

        foreach ($normalized as $order => [$lineage, $text]) {
            $step = $existing->get($lineage) ?? new MedicalSessionProtocolStep;
            $encryptedText = $step->exists
                && (int) $step->encryption_key_version === $keyVersion
                && $this->encryptor->decryptField((int) $session->organization_id, $step->getRawOriginal('step_text'), $keyVersion) === $text
                ? (string) $step->getRawOriginal('step_text')
                : (string) $this->encryptor->encryptField((int) $session->organization_id, $text, $keyVersion);
            $step->forceFill([
                'organization_id' => $session->organization_id,
                'client_id' => $session->client_id,
                'medical_session_id' => $session->getKey(),
                'patient_profile_id' => $session->patient_profile_id,
                'lineage_key' => $lineage,
                'step_order' => $order,
                'step_text' => $encryptedText,
                'encryption_key_version' => $keyVersion,
            ])->save();
        }
    }
}
