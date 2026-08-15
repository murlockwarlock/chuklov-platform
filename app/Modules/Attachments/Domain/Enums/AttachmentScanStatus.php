<?php

namespace App\Modules\Attachments\Domain\Enums;

enum AttachmentScanStatus: string
{
    case Pending = 'pending';
    case Cleared = 'cleared';
    case Quarantined = 'quarantined';
    case Rejected = 'rejected';

    public function isAvailable(): bool
    {
        return $this === self::Cleared;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'На проверке',
            self::Cleared => 'Проверен',
            self::Quarantined => 'На карантине',
            self::Rejected => 'Отклонён',
        };
    }
}
