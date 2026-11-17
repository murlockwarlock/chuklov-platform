<?php

namespace App\Modules\Attachments\Application;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

final class MedicalFilePolicy
{
    /** @return list<string> */
    public static function allowedMimeTypes(): array
    {
        return [
            'application/pdf',
            'text/plain',
            'image/jpeg',
            'image/png',
            'image/webp',
            'video/mp4',
            'video/quicktime',
            'video/webm',
        ];
    }

    public function maxBytes(int $organizationId): int
    {
        $hardCap = (int) config('medical.attachment_max_bytes', 20_971_520);
        $setting = $organizationId > 0
            ? OrganizationSetting::query()
                ->where('organization_id', $organizationId)
                ->where('setting_key', OrganizationSettingKey::MedicalGeneralFileBusinessMaxBytes->value)
                ->first()
            : null;

        return is_int($setting?->integer_value) && $setting->integer_value > 0
            ? min($setting->integer_value, $hardCap)
            : $hardCap;
    }
}
