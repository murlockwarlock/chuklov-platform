<?php

namespace App\Modules\Specialists\Domain\ValueObjects;

use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use InvalidArgumentException;

final readonly class SpecialistProfile
{
    private function __construct(
        public string $displayName,
        public ?string $timezone,
    ) {}

    public static function from(string $displayName, ?string $timezone): self
    {
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

        return new self($displayName, $timezone);
    }

    /** @return array{display_name: string, timezone: string|null} */
    public function attributes(): array
    {
        return [
            'display_name' => $this->displayName,
            'timezone' => $this->timezone,
        ];
    }
}
