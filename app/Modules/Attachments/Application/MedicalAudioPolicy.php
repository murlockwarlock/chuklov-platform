<?php

namespace App\Modules\Attachments\Application;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class MedicalAudioPolicy
{
    /** @var array<string, string> */
    private const MIME_LABELS = [
        'audio/mpeg' => 'MP3',
        'audio/mp4' => 'M4A / MP4',
        'audio/x-m4a' => 'M4A',
        'audio/wav' => 'WAV',
        'audio/x-wav' => 'WAV',
        'audio/ogg' => 'OGG',
        'audio/webm' => 'WebM',
    ];

    /** @return list<string> */
    public static function allowedMimeTypes(): array
    {
        return array_keys(self::MIME_LABELS);
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return self::MIME_LABELS;
    }

    /** @return list<string> */
    public function mimeTypes(int $organizationId): array
    {
        $setting = $this->setting($organizationId, OrganizationSettingKey::MedicalAudioAllowedMimeTypes);
        if ($setting === null || ! is_string($setting->string_value)) {
            return self::allowedMimeTypes();
        }

        $value = json_decode($setting->string_value, true);
        if (! is_array($value)) {
            return self::allowedMimeTypes();
        }

        $mimeTypes = array_values(array_intersect(self::allowedMimeTypes(), array_map('strval', $value)));

        return $mimeTypes === [] ? self::allowedMimeTypes() : $mimeTypes;
    }

    public function maxBytes(int $organizationId): int
    {
        $hardCap = (int) config('medical.audio_attachment_max_bytes', 67_108_864);
        $setting = $this->setting($organizationId, OrganizationSettingKey::MedicalAudioBusinessMaxBytes);
        $configured = $setting?->integer_value;

        return is_int($configured) && $configured > 0 && $configured <= $hardCap ? $configured : $hardCap;
    }

    public function assertUploadAllowed(int $organizationId, UploadedFile $file): void
    {
        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, $this->mimeTypes($organizationId), true)) {
            throw ValidationException::withMessages([
                'file' => 'Этот аудиоформат отключён в настройках организации.',
            ]);
        }

        $size = $file->getSize();
        if ($size !== false && $size > $this->maxBytes($organizationId)) {
            $limitMb = round($this->maxBytes($organizationId) / 1024 / 1024, 1);
            throw ValidationException::withMessages([
                'file' => "Размер аудиофайла превышает настроенный лимит {$limitMb} МБ.",
            ]);
        }
    }

    private function setting(int $organizationId, OrganizationSettingKey $key): ?OrganizationSetting
    {
        return OrganizationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('setting_key', $key->value)
            ->first();
    }
}
