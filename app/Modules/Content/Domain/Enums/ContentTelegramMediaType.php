<?php

namespace App\Modules\Content\Domain\Enums;

enum ContentTelegramMediaType: string
{
    case Video = 'video';
    case VideoNote = 'video_note';
}
