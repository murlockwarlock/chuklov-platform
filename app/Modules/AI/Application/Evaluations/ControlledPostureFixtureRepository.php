<?php

namespace App\Modules\AI\Application\Evaluations;

use App\Models\User;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ControlledPostureFixtureRepository
{
    private const string IMAGE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /** @var list<string> */
    private const ROLES = ['front', 'side', 'back'];

    /**
     * @return array{client_id: int, key: string, references: list<AiInputReference>, roles: list<string>}
     */
    public function ensure(Organization $organization, User $actor, string $key): array
    {
        $fixtureKey = $this->normalizeKey($key);
        $existing = MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('evaluation_fixture_key', $fixtureKey)
            ->where('attachment_type', AttachmentType::PosturePhoto)
            ->get()
            ->keyBy('evaluation_fixture_role');

        if ($existing->isNotEmpty()) {
            if ($existing->count() !== count(self::ROLES) || $existing->keys()->sort()->values()->all() !== collect(self::ROLES)->sort()->values()->all()) {
                throw new InvalidArgumentException('Controlled posture fixture is incomplete.');
            }

            $clientId = (int) $existing->first()->client_id;
            if ($existing->contains(static fn (MedicalAttachment $attachment): bool => (int) $attachment->client_id !== $clientId)) {
                throw new InvalidArgumentException('Controlled posture fixture has inconsistent client ownership.');
            }

            return $this->result($fixtureKey, $clientId, $existing);
        }

        $disk = Storage::disk('private');
        $paths = [];

        try {
            $result = DB::transaction(function () use ($organization, $actor, $fixtureKey, $disk, &$paths): int {
                $client = Client::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('email', $this->syntheticEmail($fixtureKey))
                    ->first();

                if ($client === null) {
                    $client = new Client;
                    $client->forceFill([
                        'organization_id' => $organization->getKey(),
                        'full_name' => 'Синтетический тест осанки',
                        'email' => $this->syntheticEmail($fixtureKey),
                        'language' => 'ru',
                        'timezone' => 'UTC',
                        'lead_source' => 'synthetic_ai_evaluation',
                    ]);
                    $client->save();
                }

                foreach (self::ROLES as $role) {
                    $uuid = (string) Str::uuid();
                    $path = 'medical/attachments/'.((int) $organization->getKey()).'/'.$uuid.'.png';
                    $bytes = base64_decode(self::IMAGE, true);
                    if (! is_string($bytes) || ! $disk->put($path, $bytes)) {
                        throw new InvalidArgumentException('Controlled posture fixture storage is unavailable.');
                    }
                    $paths[] = $path;

                    $attachment = new MedicalAttachment;
                    $attachment->forceFill([
                        'uuid' => $uuid,
                        'organization_id' => $organization->getKey(),
                        'client_id' => $client->getKey(),
                        'uploaded_by_user_id' => $actor->getKey(),
                        'attachment_type' => AttachmentType::PosturePhoto,
                        'disk' => 'private',
                        'storage_path' => $path,
                        'original_filename' => 'synthetic-posture-'.$role.'.png',
                        'mime_type' => 'image/png',
                        'size_bytes' => strlen($bytes),
                        'sha256_checksum' => hash('sha256', $bytes),
                        'evaluation_fixture_key' => $fixtureKey,
                        'evaluation_fixture_role' => $role,
                    ]);
                    $attachment->save();
                }

                return $client->getKey();
            });
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                $disk->delete($path);
            }

            throw $exception;
        }

        $attachments = MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('evaluation_fixture_key', $fixtureKey)
            ->whereIn('evaluation_fixture_role', self::ROLES)
            ->get()
            ->keyBy('evaluation_fixture_role');

        return $this->result($fixtureKey, (int) $result, $attachments);
    }

    private function normalizeKey(string $key): string
    {
        $normalized = strtolower(trim($key));
        $normalized = preg_replace('/[^a-z0-9:_-]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        if ($normalized === '' || strlen($normalized) > 120) {
            throw new InvalidArgumentException('Controlled posture fixture key is invalid.');
        }

        return $normalized;
    }

    private function syntheticEmail(string $fixtureKey): string
    {
        return 'ai-eval+'.hash('sha256', $fixtureKey).'@synthetic.org';
    }

    /**
     * @param  Collection<int, MedicalAttachment>  $attachments
     * @return array{client_id: int, key: string, references: list<AiInputReference>, roles: list<string>}
     */
    private function result(string $key, int $clientId, Collection $attachments): array
    {
        $references = [];
        foreach (self::ROLES as $role) {
            $attachment = $attachments->get($role);
            if (! $attachment instanceof MedicalAttachment) {
                throw new InvalidArgumentException('Controlled posture fixture is incomplete.');
            }

            $references[] = new AiInputReference('medical_attachment', (int) $attachment->getKey(), $role);
        }

        return [
            'client_id' => $clientId,
            'key' => $key,
            'references' => $references,
            'roles' => self::ROLES,
        ];
    }
}
