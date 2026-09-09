<?php

namespace App\Modules\Knowledge\Domain\Enums;

enum KnowledgeExtractionStatus: string
{
    case Ready = 'ready';
    case TextNotFound = 'text_not_found';
    case Suspicious = 'suspicious';
    case Failed = 'failed';
    case AiParseRequested = 'ai_parse_requested';
}
