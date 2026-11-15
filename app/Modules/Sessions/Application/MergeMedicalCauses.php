<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class MergeMedicalCauses
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private MedicalEncryptorInterface $encryptor,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, int $sourceId, int $targetId): MedicalCauseDefinition
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
            throw ValidationException::withMessages(['cause' => 'Выберите две разные причины для объединения.']);
        }
        $organizationId = (int) $organization->getKey();

        return DB::transaction(function () use ($actor, $organization, $organizationId, $sourceId, $targetId): MedicalCauseDefinition {
            $source = $this->lockedCause($organizationId, $sourceId);
            $target = $this->lockedCause($organizationId, $targetId);
            $sourceCanonical = $this->resolveLocked($organizationId, $source);
            $targetCanonical = $this->resolveLocked($organizationId, $target);

            if ((int) $sourceCanonical->getKey() === (int) $targetCanonical->getKey()) {
                return $targetCanonical->refresh();
            }
            if ($source->merged_into_id !== null) {
                throw ValidationException::withMessages(['cause' => 'Причина уже объединена с другой записью.']);
            }

            $targetId = (int) $targetCanonical->getKey();
            $targetSynonyms = $this->stringList($targetCanonical->synonyms);
            $sourceSynonyms = $this->stringList($source->synonyms);
            $targetCanonical->forceFill([
                'synonyms' => MedicalCauseDirectory::normalizeSynonyms([
                    ...$targetSynonyms,
                    (string) $source->name,
                    ...$sourceSynonyms,
                ]),
            ])->save();

            $this->reassignSessionCauses($organizationId, $sourceId, $targetId);
            $this->reassignTestCauses($organizationId, $sourceId, $targetId);
            $this->reassignRootCauses($organizationId, $sourceId, $targetId);
            $this->reassignTestLinks($organizationId, $sourceId, $targetId);
            $this->rewriteEncryptedCauseReferences($organizationId, $sourceId, $targetId);

            $source->forceFill([
                'merged_into_id' => $targetId,
                'is_active' => false,
                'moderation_status' => MedicalCauseDefinition::MODERATION_HIDDEN,
            ])->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.catalog.updated',
                targetType: MedicalCauseDefinition::class,
                targetId: (string) $targetId,
                metadata: [],
            );

            return $targetCanonical->refresh();
        });
    }

    private function lockedCause(int $organizationId, int $causeId): MedicalCauseDefinition
    {
        $cause = MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereKey($causeId)
            ->lockForUpdate()
            ->first();
        if ($cause === null) {
            throw (new ModelNotFoundException)->setModel(MedicalCauseDefinition::class, [$causeId]);
        }

        return $cause;
    }

    private function resolveLocked(int $organizationId, MedicalCauseDefinition $cause): MedicalCauseDefinition
    {
        $seen = [];
        while ($cause->merged_into_id !== null) {
            $id = (int) $cause->getKey();
            if (isset($seen[$id])) {
                throw ValidationException::withMessages(['cause' => 'В справочнике обнаружена циклическая связь причин.']);
            }
            $seen[$id] = true;
            $cause = $this->lockedCause($organizationId, (int) $cause->merged_into_id);
        }

        return $cause;
    }

    private function reassignSessionCauses(int $organizationId, int $sourceId, int $targetId): void
    {
        $rows = DB::table('medical_session_causes')
            ->where('organization_id', $organizationId)
            ->where('medical_cause_definition_id', $sourceId)
            ->lockForUpdate()
            ->get(['id', 'medical_session_id']);
        foreach ($rows as $row) {
            $exists = DB::table('medical_session_causes')
                ->where('organization_id', $organizationId)
                ->where('medical_session_id', $row->medical_session_id)
                ->where('medical_cause_definition_id', $targetId)
                ->exists();
            if ($exists) {
                DB::table('medical_session_causes')->where('id', $row->id)->delete();

                continue;
            }
            DB::table('medical_session_causes')->where('id', $row->id)->update([
                'medical_cause_definition_id' => $targetId,
                'updated_at' => now(),
            ]);
        }
    }

    private function reassignTestCauses(int $organizationId, int $sourceId, int $targetId): void
    {
        $rows = DB::table('medical_session_test_causes')
            ->where('organization_id', $organizationId)
            ->where('medical_cause_definition_id', $sourceId)
            ->lockForUpdate()
            ->get(['id', 'medical_session_test_result_id']);
        foreach ($rows as $row) {
            $exists = DB::table('medical_session_test_causes')
                ->where('organization_id', $organizationId)
                ->where('medical_session_test_result_id', $row->medical_session_test_result_id)
                ->where('medical_cause_definition_id', $targetId)
                ->exists();
            if ($exists) {
                DB::table('medical_session_test_causes')->where('id', $row->id)->delete();

                continue;
            }
            DB::table('medical_session_test_causes')->where('id', $row->id)->update([
                'medical_cause_definition_id' => $targetId,
                'updated_at' => now(),
            ]);
        }
    }

    private function reassignRootCauses(int $organizationId, int $sourceId, int $targetId): void
    {
        DB::table('medical_session_root_causes')
            ->where('organization_id', $organizationId)
            ->where('medical_cause_definition_id', $sourceId)
            ->update([
                'medical_cause_definition_id' => $targetId,
                'updated_at' => now(),
            ]);
    }

    private function reassignTestLinks(int $organizationId, int $sourceId, int $targetId): void
    {
        $rows = DB::table('medical_test_cause_links')
            ->where('organization_id', $organizationId)
            ->where('medical_cause_definition_id', $sourceId)
            ->lockForUpdate()
            ->get(['id', 'medical_test_definition_id', 'scope_key', 'is_active']);
        foreach ($rows as $row) {
            $targetQuery = DB::table('medical_test_cause_links')
                ->where('organization_id', $organizationId)
                ->where('medical_test_definition_id', $row->medical_test_definition_id)
                ->where('medical_cause_definition_id', $targetId);
            $targetQuery = $row->scope_key === null
                ? $targetQuery->whereNull('scope_key')
                : $targetQuery->where('scope_key', $row->scope_key);
            $targetLink = $targetQuery->lockForUpdate()->first(['id', 'is_active']);
            if ($targetLink !== null) {
                if ((bool) $row->is_active && ! (bool) $targetLink->is_active) {
                    DB::table('medical_test_cause_links')->where('id', $targetLink->id)->update([
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                }
                DB::table('medical_test_cause_links')->where('id', $row->id)->delete();

                continue;
            }
            DB::table('medical_test_cause_links')->where('id', $row->id)->update([
                'medical_cause_definition_id' => $targetId,
                'updated_at' => now(),
            ]);
        }
    }

    private function rewriteEncryptedCauseReferences(int $organizationId, int $sourceId, int $targetId): void
    {
        $results = DB::table('medical_session_test_results as results')
            ->join('medical_sessions as sessions', function ($join): void {
                $join->on('sessions.organization_id', '=', 'results.organization_id')
                    ->on('sessions.id', '=', 'results.medical_session_id');
            })
            ->where('results.organization_id', $organizationId)
            ->whereNotNull('results.structured_payload')
            ->lockForUpdate()
            ->get([
                'results.id',
                'results.structured_payload',
                'results.structured_payload_key_version',
                'sessions.encryption_key_version as session_key_version',
            ]);
        foreach ($results as $result) {
            $keyVersion = (int) ($result->structured_payload_key_version ?? $result->session_key_version ?? 1);
            $payload = $this->decodeEncryptedJson($organizationId, (string) $result->structured_payload, $keyVersion);
            if (! $this->replaceCauseReferences($payload, $sourceId, $targetId)) {
                continue;
            }
            DB::table('medical_session_test_results')
                ->where('organization_id', $organizationId)
                ->where('id', $result->id)
                ->update([
                    'structured_payload' => $this->encodeEncryptedJson($organizationId, $payload, $keyVersion),
                    'structured_payload_key_version' => $keyVersion,
                    'updated_at' => now(),
                ]);
        }

        $roots = DB::table('medical_session_root_causes')
            ->where('organization_id', $organizationId)
            ->whereNotNull('influences')
            ->lockForUpdate()
            ->get(['id', 'influences', 'encryption_key_version']);
        foreach ($roots as $root) {
            $keyVersion = (int) $root->encryption_key_version;
            $influences = $this->decodeEncryptedJson($organizationId, (string) $root->influences, $keyVersion);
            if (! $this->replaceCauseReferences($influences, $sourceId, $targetId)) {
                continue;
            }
            DB::table('medical_session_root_causes')
                ->where('organization_id', $organizationId)
                ->where('id', $root->id)
                ->update([
                    'influences' => $this->encodeEncryptedJson($organizationId, $influences, $keyVersion),
                    'updated_at' => now(),
                ]);
        }
    }

    /** @return array<string, mixed> */
    private function decodeEncryptedJson(int $organizationId, string $ciphertext, int $keyVersion): array
    {
        try {
            $decoded = json_decode(
                (string) $this->encryptor->decryptField($organizationId, $ciphertext, $keyVersion),
                true,
                12,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw ValidationException::withMessages(['cause' => 'Не удалось обновить зашифрованные связи причины.']);
        }
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['cause' => 'Зашифрованные связи причины имеют неверный формат.']);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function encodeEncryptedJson(int $organizationId, array $payload, int $keyVersion): string
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['cause' => 'Не удалось сохранить связи причины.']);
        }

        return (string) $this->encryptor->encryptField($organizationId, $encoded, $keyVersion);
    }

    private function replaceCauseReferences(mixed &$value, int $sourceId, int $targetId, ?string $key = null): bool
    {
        if ($key === 'cause_ids' || $key === 'primary_cause_id') {
            if ($key === 'cause_ids' && is_array($value)) {
                $changed = false;
                $replacement = [];
                foreach ($value as $id) {
                    $id = (int) $id;
                    if ($id === $sourceId) {
                        $id = $targetId;
                        $changed = true;
                    }
                    $replacement[] = $id;
                }
                $value = array_values(array_unique($replacement));

                return $changed;
            }
            if (is_numeric($value) && (int) $value === $sourceId) {
                $value = $targetId;

                return true;
            }

            return false;
        }
        if (! is_array($value)) {
            return false;
        }

        $changed = false;
        foreach ($value as $childKey => &$child) {
            $changed = $this->replaceCauseReferences($child, $sourceId, $targetId, is_string($childKey) ? $childKey : null) || $changed;
        }

        return $changed;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
