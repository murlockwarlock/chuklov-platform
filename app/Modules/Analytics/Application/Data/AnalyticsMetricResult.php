<?php

namespace App\Modules\Analytics\Application\Data;

use App\Modules\Analytics\Domain\Enums\AnalyticsMetricUnit;

final readonly class AnalyticsMetricResult
{
    public function __construct(
        public string $key,
        public string $name,
        public AnalyticsMetricUnit $unit,
        public int $precision,
        public ?float $value,
        public ?string $currency,
        public ?string $message = null,
        public bool $signed = false,
    ) {}

    public function available(): bool
    {
        return $this->value !== null;
    }

    public function formatted(): string
    {
        if ($this->value === null) {
            return $this->message ?? 'Недостаточно данных';
        }

        $formatted = number_format($this->value, $this->precision, ',', ' ');
        if ($this->signed && $this->value > 0) {
            $formatted = '+'.$formatted;
        }

        return match ($this->unit->value) {
            'percent' => $formatted.' %',
            'money' => $formatted.' '.($this->currency ?? ''),
            default => $formatted,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'unit' => $this->unit->label(),
            'unit_key' => $this->unit->value,
            'precision' => $this->precision,
            'value' => $this->value,
            'currency' => $this->currency,
            'available' => $this->available(),
            'display' => $this->formatted(),
            'message' => $this->message,
            'signed' => $this->signed,
        ];
    }
}
