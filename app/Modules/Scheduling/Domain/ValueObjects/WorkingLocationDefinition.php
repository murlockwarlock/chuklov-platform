<?php

namespace App\Modules\Scheduling\Domain\ValueObjects;

use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use InvalidArgumentException;

final readonly class WorkingLocationDefinition
{
    private function __construct(
        public string $name,
        public string $address,
        public string $timezone,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $mapUrl,
        public bool $isActive,
        public bool $isDefaultOffice,
    ) {}

    public static function from(
        string $name,
        string $address,
        string $timezone,
        ?float $latitude,
        ?float $longitude,
        ?string $mapUrl,
        bool $isActive,
        bool $isDefaultOffice,
    ): self {
        $name = trim($name);
        $address = trim($address);
        $timezone = trim($timezone);
        $mapUrl = $mapUrl === null ? null : trim($mapUrl);

        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('The working location name is invalid.');
        }

        if ($address === '' || mb_strlen($address) > 500) {
            throw new InvalidArgumentException('The working location address is invalid.');
        }

        $timezone = IanaTimezone::from($timezone)->value;

        if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
            throw new InvalidArgumentException('The working location latitude is invalid.');
        }

        if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
            throw new InvalidArgumentException('The working location longitude is invalid.');
        }

        if ($mapUrl !== null && ($mapUrl === '' || mb_strlen($mapUrl) > 2000 || filter_var($mapUrl, FILTER_VALIDATE_URL) === false)) {
            throw new InvalidArgumentException('The working location map URL is invalid.');
        }

        return new self($name, $address, $timezone, $latitude, $longitude, $mapUrl, $isActive, $isDefaultOffice);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'timezone' => $this->timezone,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'map_url' => $this->mapUrl,
            'is_active' => $this->isActive,
            'is_default_office' => $this->isDefaultOffice,
        ];
    }
}
