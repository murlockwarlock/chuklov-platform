<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class AnalyticsMetricFilters
{
    public function __construct(
        public ?int $problemTagId = null,
        public ?int $feedbackThemeId = null,
        public ?int $goalTagId = null,
        public ?string $sourceKey = null,
        public ?string $purchaseType = null,
        public ?string $bookingStatus = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /** @return array{problem_tag_id: ?int, feedback_theme_id: ?int, goal_tag_id: ?int, source_key: ?string, purchase_type: ?string, booking_status: ?string} */
    public function values(): array
    {
        return [
            'problem_tag_id' => $this->problemTagId,
            'feedback_theme_id' => $this->feedbackThemeId,
            'goal_tag_id' => $this->goalTagId,
            'source_key' => $this->sourceKey,
            'purchase_type' => $this->purchaseType,
            'booking_status' => $this->bookingStatus,
        ];
    }
}
