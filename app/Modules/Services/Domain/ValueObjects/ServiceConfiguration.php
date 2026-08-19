<?php

namespace App\Modules\Services\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use InvalidArgumentException;

final readonly class ServiceConfiguration
{
    /** @param list<string> $formats */
    private function __construct(
        public string $name,
        public string $summary,
        public ?string $imagePath,
        public ?string $externalImageUrl,
        public bool $isActive,
        public CatalogItemType $catalogType,
        public ?string $nameRu,
        public ?string $nameEn,
        public ?string $descriptionRu,
        public ?string $descriptionEn,
        public ?string $category,
        public ?int $durationMinutes,
        public int $bufferMinutes,
        public array $formats,
        public ?int $priceMinor,
        public ?string $priceCurrency,
        public ?string $paymentPolicy,
    ) {}

    /** @param array<string, mixed> $attributes */
    public static function from(array $attributes): self
    {
        $nameRu = self::optionalString($attributes['name_ru'] ?? null, 'The Russian service name is invalid.', 160);
        $nameEn = self::optionalString($attributes['name_en'] ?? null, 'The English service name is invalid.', 160);
        $name = self::requiredString(
            self::emptyToNull($attributes['name'] ?? null) ?? ($nameEn ?? $nameRu),
            'The service name is invalid.',
            160,
        );

        $summary = self::requiredString(
            self::emptyToNull($attributes['summary'] ?? null)
                ?? self::emptyToNull($attributes['description_en'] ?? null)
                ?? self::emptyToNull($attributes['description_ru'] ?? null),
            'The service summary is invalid.',
            500,
        );
        $imagePath = self::imagePath($attributes['image_path'] ?? null);
        $externalImageUrl = self::externalImageUrl($attributes['external_image_url'] ?? null);

        if ($imagePath !== null && $externalImageUrl !== null) {
            throw new InvalidArgumentException('A service cannot have both a managed image and an external image URL.');
        }

        $catalogType = self::catalogType($attributes['catalog_type'] ?? CatalogItemType::Service->value);

        $descriptionRu = self::optionalString(
            $attributes['description_ru'] ?? null,
            'The Russian service description is invalid.',
            10000,
        );
        $descriptionEn = self::optionalString(
            $attributes['description_en'] ?? null,
            'The English service description is invalid.',
            10000,
        );
        $category = self::optionalString($attributes['category'] ?? null, 'The service category is invalid.', 120);
        $durationMinutes = self::optionalNonNegativeInteger(
            $attributes['duration_minutes'] ?? null,
            'The service duration is invalid.',
            65535,
            true,
        );

        if ($durationMinutes === 0) {
            throw new InvalidArgumentException('The service duration must be positive.');
        }

        $bufferMinutes = self::nonNegativeInteger(
            self::emptyToZero($attributes['buffer_minutes'] ?? 0),
            'The service buffer is invalid.',
            65535,
            true,
        );
        $priceCurrency = self::currency($attributes['price_currency'] ?? null);
        $priceMinor = array_key_exists('price', $attributes)
            ? self::priceMinorFromMajor($attributes['price'], $priceCurrency)
            : self::optionalNonNegativeInteger($attributes['price_minor'] ?? null, 'The service price is invalid.');

        $formats = self::formats($attributes['formats'] ?? []);

        if (($priceMinor === null) !== ($priceCurrency === null)) {
            throw new InvalidArgumentException('A service price requires an explicit currency.');
        }

        $paymentPolicy = self::optionalString(
            $attributes['payment_policy'] ?? null,
            'The service payment policy is invalid.',
            64,
        );

        return new self(
            name: $name,
            summary: $summary,
            imagePath: $imagePath,
            externalImageUrl: $externalImageUrl,
            isActive: (bool) ($attributes['is_active'] ?? true),
            catalogType: $catalogType,
            nameRu: $nameRu,
            nameEn: $nameEn,
            descriptionRu: $descriptionRu,
            descriptionEn: $descriptionEn,
            category: $category,
            durationMinutes: $durationMinutes,
            bufferMinutes: $bufferMinutes,
            formats: $formats,
            priceMinor: $priceMinor,
            priceCurrency: $priceCurrency,
            paymentPolicy: $paymentPolicy,
        );
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'name' => $this->name,
            'summary' => $this->summary,
            'image_path' => $this->imagePath,
            'external_image_url' => $this->externalImageUrl,
            'is_active' => $this->isActive,
            'catalog_type' => $this->catalogType->value,
            'name_ru' => $this->nameRu,
            'name_en' => $this->nameEn,
            'description_ru' => $this->descriptionRu,
            'description_en' => $this->descriptionEn,
            'category' => $this->category,
            'duration_minutes' => $this->durationMinutes,
            'buffer_minutes' => $this->bufferMinutes,
            'formats' => $this->formats,
            'price_minor' => $this->priceMinor,
            'price_currency' => $this->priceCurrency,
            'payment_policy' => $this->paymentPolicy,
        ];
    }

    private static function requiredString(mixed $value, string $message, int $maxLength): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException($message);
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private static function optionalString(mixed $value, string $message, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::requiredString($value, $message, $maxLength);
    }

    private static function emptyToNull(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    private static function emptyToZero(mixed $value): mixed
    {
        return $value === null || (is_string($value) && trim($value) === '') ? 0 : $value;
    }

    private static function imagePath(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The service image path is invalid.');
        }

        $value = trim($value);

        if ($value === ''
            || mb_strlen($value) > 255
            || str_starts_with($value, '/')
            || str_contains($value, '..')
            || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $value) === 1
            || preg_match('/\.(?:jpe?g|png|webp|avif)$/i', $value) !== 1
        ) {
            throw new InvalidArgumentException('The service image path is invalid.');
        }

        return $value;
    }

    private static function externalImageUrl(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The external service image URL is invalid.');
        }

        $value = trim($value);

        if ($value === ''
            || mb_strlen($value) > 2048
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException('The external service image URL is invalid.');
        }

        $parts = parse_url($value);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
        ) {
            throw new InvalidArgumentException('The external service image URL must use HTTPS.');
        }

        return $value;
    }

    private static function priceMinorFromMajor(mixed $value, ?string $currency): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($currency === null) {
            throw new InvalidArgumentException('A service price requires an explicit currency.');
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('The service price is invalid.');
        }

        $amount = is_string($value) ? trim($value) : $value;

        if ($amount === '') {
            return null;
        }

        try {
            $money = Money::fromDecimal($amount, $currency);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('The service price is invalid.', previous: $exception);
        }

        if ($money->isNegative()) {
            throw new InvalidArgumentException('The service price is invalid.');
        }

        return $money->minorUnits();
    }

    private static function catalogType(mixed $value): CatalogItemType
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('The catalog item type is invalid.');
        }

        return CatalogItemType::tryFrom($value)
            ?? throw new InvalidArgumentException('The catalog item type is invalid.');
    }

    private static function nonNegativeInteger(
        mixed $value,
        string $message,
        ?int $max = null,
        bool $allowIntegralFloat = false,
    ): int {
        $normalized = self::optionalNonNegativeInteger($value, $message, $max, $allowIntegralFloat);

        if ($normalized === null) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private static function optionalNonNegativeInteger(
        mixed $value,
        string $message,
        ?int $max = null,
        bool $allowIntegralFloat = false,
    ): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException($message);
            }

            if ($max !== null && $value > $max) {
                throw new InvalidArgumentException($message);
            }

            return $value;
        }

        if ($allowIntegralFloat && is_float($value) && is_finite($value) && floor($value) === $value) {
            if ($value < 0 || $value > PHP_INT_MAX || ($max !== null && $value > $max)) {
                throw new InvalidArgumentException($message);
            }

            return (int) $value;
        }

        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new InvalidArgumentException($message);
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($normalized === false) {
            throw new InvalidArgumentException($message);
        }

        if ($max !== null && $normalized > $max) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    /** @return list<string> */
    private static function formats(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('The service formats are invalid.');
        }

        $formats = [];

        foreach ($value as $format) {
            if (! is_string($format) || ! in_array($format, ['office', 'home', 'online'], true)) {
                throw new InvalidArgumentException('The service formats are invalid.');
            }

            $formats[] = $format;
        }

        return array_values(array_unique($formats));
    }

    private static function currency(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The service currency is invalid.');
        }

        $currency = strtoupper(trim($value));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || CurrencyCode::tryFrom($currency) === null) {
            throw new InvalidArgumentException('The service currency is invalid.');
        }

        return $currency;
    }
}
