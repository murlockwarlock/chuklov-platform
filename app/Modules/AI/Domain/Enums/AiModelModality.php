<?php

namespace App\Modules\AI\Domain\Enums;

enum AiModelModality: string
{
    case ImageInput = 'image_input';
    case DocumentInput = 'document_input';

    public function label(): string
    {
        return match ($this) {
            self::ImageInput => 'Входные изображения и сканы',
            self::DocumentInput => 'Входные документы и файлы',
        };
    }
}
