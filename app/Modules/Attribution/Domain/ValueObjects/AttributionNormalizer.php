<?php

namespace App\Modules\Attribution\Domain\ValueObjects;

use Illuminate\Support\Str;

final class AttributionNormalizer
{
    /** @param array<string, mixed> $input */
    public function handle(array $input): ?AttributionData
    {
        $source = $this->text($input['source'] ?? $input['deep_link_source'] ?? null, (int) config('attribution.max_source_length', 120));
        $referralCode = $this->referralCode($input['referral_code'] ?? null);
        $utmSource = $this->text($input['utm_source'] ?? null, (int) config('attribution.max_source_length', 120));
        $utmMedium = $this->text($input['utm_medium'] ?? null, (int) config('attribution.max_source_length', 120));
        $utmCampaign = $this->text($input['utm_campaign'] ?? null, (int) config('attribution.max_utm_length', 160));
        $utmContent = $this->text($input['utm_content'] ?? null, (int) config('attribution.max_utm_length', 160));
        $utmTerm = $this->text($input['utm_term'] ?? null, (int) config('attribution.max_utm_length', 160));

        if ($referralCode !== null) {
            $sourceType = 'referral';
        } elseif ($source !== null) {
            $sourceType = 'source';
        } elseif ($utmSource !== null || $utmMedium !== null || $utmCampaign !== null || $utmContent !== null || $utmTerm !== null) {
            $sourceType = 'utm';
        } else {
            return null;
        }

        return new AttributionData(
            sourceType: $sourceType,
            source: $source,
            referralCode: $referralCode,
            utmSource: $utmSource,
            utmMedium: $utmMedium,
            utmCampaign: $utmCampaign,
            utmContent: $utmContent,
            utmTerm: $utmTerm,
        );
    }

    private function text(mixed $value, int $maximumLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/[\\x00-\\x1F\\x7F]+/u', ' ', trim($value));
        $value = is_string($value) ? preg_replace('/\\s+/u', ' ', $value) : null;
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return null;
        }

        return Str::limit($value, $maximumLength, '');
    }

    private function referralCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        $maximumLength = (int) config('attribution.max_referral_code_length', 128);

        if ($value === '' || mb_strlen($value) > $maximumLength || preg_match('/^[A-Za-z0-9_-]{16,128}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
