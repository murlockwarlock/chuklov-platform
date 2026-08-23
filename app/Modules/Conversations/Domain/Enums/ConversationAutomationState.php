<?php

namespace App\Modules\Conversations\Domain\Enums;

enum ConversationAutomationState: string
{
    case AiActive = 'ai_active';
    case HumanHandoff = 'human_handoff';
}
