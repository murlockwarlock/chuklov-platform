<?php

namespace App\Modules\Attribution\Domain\ValueObjects;

final readonly class AttributionData
{
    public function __construct(
        public string $sourceType,
        public ?string $source,
        public ?string $referralCode,
        public ?string $utmSource,
        public ?string $utmMedium,
        public ?string $utmCampaign,
        public ?string $utmContent,
        public ?string $utmTerm,
    ) {}

    public function hasEvidence(): bool
    {
        return $this->source !== null
            || $this->referralCode !== null
            || $this->utmSource !== null
            || $this->utmMedium !== null
            || $this->utmCampaign !== null
            || $this->utmContent !== null
            || $this->utmTerm !== null;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source' => $this->source,
            'referral_code' => $this->referralCode,
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'utm_content' => $this->utmContent,
            'utm_term' => $this->utmTerm,
        ];
    }
}
