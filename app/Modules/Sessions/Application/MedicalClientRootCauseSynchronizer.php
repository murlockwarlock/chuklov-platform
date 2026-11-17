<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalClientRootCause;
use App\Modules\Sessions\Domain\Models\MedicalClientRootCauseHistory;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class MedicalClientRootCauseSynchronizer
{
    public function __construct(private MedicalEncryptorInterface $encryptor) {}

    /** @param array<int, array<string, mixed>> $roots */
    public function sync(MedicalSession $session, array $roots, int $keyVersion): void
    {
        if ($roots === []) {
            return;
        }

        $now = now();
        DB::transaction(function () use ($session, $roots, $keyVersion, $now): void {
            foreach ($roots as $root) {
                $identityKey = $this->identityKey($root);
                $model = MedicalClientRootCause::query()
                    ->where('organization_id', $session->organization_id)
                    ->where('client_id', $session->client_id)
                    ->where('identity_key', $identityKey)
                    ->first() ?? new MedicalClientRootCause;
                $existingStatus = $model->exists ? (string) $model->status : null;
                $status = $this->status($root['status'] ?? $existingStatus ?? MedicalClientRootCause::STATUS_NEW);
                $model->forceFill([
                    'organization_id' => $session->organization_id,
                    'client_id' => $session->client_id,
                    'medical_cause_definition_id' => $root['cause_id'],
                    'identity_key' => $identityKey,
                    'status' => $status,
                    'region' => $this->encryptedText($session, $root['region'] ?? null, $keyVersion),
                    'region_key_version' => filled($root['region'] ?? null) ? $keyVersion : null,
                    'free_text' => $this->encryptedText($session, $root['free_text'] ?? null, $keyVersion),
                    'free_text_key_version' => filled($root['free_text'] ?? null) ? $keyVersion : null,
                    'relations' => $this->encryptedJson($session, ['cause_ids' => $root['influences'] ?? []], $keyVersion),
                    'relations_key_version' => ($root['influences'] ?? []) === [] ? null : $keyVersion,
                    'source_session_id' => $session->getKey(),
                    'first_seen_at' => $model->first_seen_at ?? $now,
                    'last_seen_at' => $now,
                    'resolved_at' => $status === MedicalClientRootCause::STATUS_RESOLVED ? ($model->resolved_at ?? $now) : null,
                    'sort_order' => $root['sort_order'] ?? 0,
                ]);
                $model->save();

                $history = MedicalClientRootCauseHistory::query()
                    ->where('organization_id', $session->organization_id)
                    ->where('medical_client_root_cause_id', $model->getKey())
                    ->where('medical_session_id', $session->getKey())
                    ->first() ?? new MedicalClientRootCauseHistory;
                $history->forceFill([
                    'organization_id' => $session->organization_id,
                    'client_id' => $session->client_id,
                    'medical_client_root_cause_id' => $model->getKey(),
                    'medical_session_id' => $session->getKey(),
                    'status' => $status,
                    'region' => $model->region,
                    'region_key_version' => $model->region_key_version,
                    'free_text' => $model->free_text,
                    'free_text_key_version' => $model->free_text_key_version,
                    'relations' => $model->relations,
                    'relations_key_version' => $model->relations_key_version,
                ]);
                $history->save();
            }
        });
    }

    private function identityKey(array $root): string
    {
        $causeId = (int) ($root['cause_id'] ?? 0);
        if ($causeId > 0) {
            return 'cause:'.$causeId;
        }

        return 'text:'.hash('sha256', mb_strtolower(trim((string) ($root['free_text'] ?? '')), 'UTF-8'));
    }

    private function status(mixed $status): string
    {
        $status = trim((string) $status);
        if (! in_array($status, [
            MedicalClientRootCause::STATUS_NEW,
            MedicalClientRootCause::STATUS_IN_PROGRESS,
            MedicalClientRootCause::STATUS_RESOLVED,
        ], true)) {
            throw ValidationException::withMessages(['root_causes' => 'Статус первопричины выбран неверно.']);
        }

        return $status;
    }

    private function encryptedText(MedicalSession $session, mixed $value, int $keyVersion): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->encryptor->encryptField((int) $session->organization_id, trim($value), $keyVersion);
    }

    /** @param array<string, mixed> $value */
    private function encryptedJson(MedicalSession $session, array $value, int $keyVersion): ?string
    {
        if (($value['cause_ids'] ?? []) === []) {
            return null;
        }
        try {
            return $this->encryptor->encryptField(
                (int) $session->organization_id,
                json_encode($value, JSON_THROW_ON_ERROR),
                $keyVersion,
            );
        } catch (JsonException) {
            throw ValidationException::withMessages(['root_causes' => 'Связи первопричины заполнены неверно.']);
        }
    }
}
