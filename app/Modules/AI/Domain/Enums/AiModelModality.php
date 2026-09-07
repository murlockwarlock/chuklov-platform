<?php

namespace App\Modules\AI\Domain\Enums;

enum AiModelModality: string
{
    case ImageInput = 'image_input';
    case DocumentInput = 'document_input';

    public function label(): string
    {
        return match ($this) {
            self::ImageInput => 'Изображения и сканы (JPG, PNG, WebP)',
            self::DocumentInput => 'PDF и документы',
        };
    }
}
