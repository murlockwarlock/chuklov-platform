<?php

namespace App\Modules\Channels\Domain\Enums;

enum NotificationMessageMode: string
{
    case Text = 'text';
    case Image = 'image';
    case ImageThenText = 'image_then_text';
    case TextThenImage = 'text_then_image';
    case ImageWithCaption = 'image_caption';

    public function includesText(): bool
    {
        return $this !== self::Image;
    }

    public function includesImage(): bool
    {
        return $this !== self::Text;
    }

    public function usesCaption(): bool
    {
        return $this === self::ImageWithCaption;
    }
}
