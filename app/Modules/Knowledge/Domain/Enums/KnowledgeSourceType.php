<?php

namespace App\Modules\Knowledge\Domain\Enums;

enum KnowledgeSourceType: string
{
    case AuthoredText = 'authored_text';
    case UploadedText = 'uploaded_text';
}
