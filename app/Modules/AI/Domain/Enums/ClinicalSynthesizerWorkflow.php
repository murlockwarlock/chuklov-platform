<?php

namespace App\Modules\AI\Domain\Enums;

enum ClinicalSynthesizerWorkflow: string
{
    case Summary = 'clinical_synthesizer';
    case CourseReport = 'clinical_course_report';
    case SessionDraft = 'clinical_session_draft';
}
