<?php

namespace App\Modules\Feedback\Application;

use App\Modules\Feedback\Domain\Models\FeedbackConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;

final class GetFeedbackConfiguration
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return array{enabled: bool, positiveThreshold: int, lowScoreFeedbackRequired: bool, reviewLinks: array{ru: string|null, en: string|null}} */
    public function handle(): array
    {
        $configuration = FeedbackConfiguration::query()
            ->where('organization_id', $this->context->id())
            ->first();

        return [
            'enabled' => $configuration->enabled ?? true,
            'positiveThreshold' => $configuration->positive_threshold ?? 8,
            'lowScoreFeedbackRequired' => $configuration->low_score_feedback_required ?? true,
            'reviewLinks' => [
                'ru' => $configuration?->review_url_ru,
                'en' => $configuration?->review_url_en,
            ],
        ];
    }

    public function record(): ?FeedbackConfiguration
    {
        return FeedbackConfiguration::query()
            ->where('organization_id', $this->context->id())
            ->first();
    }
}
