<?php

namespace App\Modules\Specialists\Domain\ValueObjects;

use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SpecialistProfile
{
    private function __construct(
        public string $displayName,
        public ?string $timezone,
        public ?string $specialization,
        public ?int $practicesSinceYear,
        public ?string $qualification,
        public ?string $bio,
        public ?string $country,
        public ?string $city,
    ) {}

    public static function from(
        string $displayName,
        ?string $timezone,
        ?string $specialization = null,
        ?int $practicesSinceYear = null,
        ?string $qualification = null,
        ?string $bio = null,
        ?string $country = null,
        ?string $city = null,
    ): self {
        $displayName = trim($displayName);

        if ($displayName === '' || mb_strlen($displayName) > 160) {
            throw new InvalidArgumentException('The specialist name is invalid.');
        }

        if ($timezone !== null) {
            $timezone = trim($timezone);

            if ($timezone === '') {
                $timezone = null;
            } else {
                try {
                    $timezone = IanaTimezone::from($timezone)->value;
                } catch (InvalidArgumentException) {
                    throw new InvalidArgumentException('The specialist timezone must be an IANA timezone.');
                }
            }
        }

        $specialization = self::nullableText($specialization, 160);
        $qualification = self::nullableText($qualification, 2000);
        $bio = self::nullableText($bio, 10000);
        $country = self::nullableText($country, 100);
        $city = self::nullableText($city, 100);

        if ($practicesSinceYear !== null
            && ($practicesSinceYear < 1900 || $practicesSinceYear > CarbonImmutable::now('UTC')->year)) {
            throw new InvalidArgumentException('The specialist practice year is invalid.');
        }

        return new self(
            $displayName,
            $timezone,
            $specialization,
            $practicesSinceYear,
            $qualification,
            $bio,
            $country,
            $city,
        );
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'display_name' => $this->displayName,
            'timezone' => $this->timezone,
            'specialization' => $this->specialization,
            'practices_since_year' => $this->practicesSinceYear,
            'qualification' => $this->qualification,
            'bio' => $this->bio,
            'country' => $this->country,
            'city' => $this->city,
        ];
    }

    private static function nullableText(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException('The specialist profile text is too long.');
        }

        return $value;
    }
}
