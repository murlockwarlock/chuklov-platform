<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Services\Application\ServiceImageUrlResolver;
use App\Modules\Services\Domain\Models\Service;

final class ProjectPortalService
{
    public function __construct(private readonly ServiceImageUrlResolver $imageResolver) {}

    /** @return array<string, mixed> */
    public function handle(Service $service, string $locale): array
    {
        return [
            'id' => $service->getKey(),
            'name' => $this->localizedValue($service, 'name', $locale) ?? (string) $service->name,
            'summary' => $this->localizedValue($service, 'description', $locale)
                ?? (string) $service->summary,
            'imageUrl' => $this->imageResolver->resolve($service),
            'category' => $this->stringValue($service->getAttribute('category')),
            'durationMinutes' => $service->durationMinutes(),
            'priceMinor' => $service->getAttribute('price_minor'),
            'priceMajor' => $this->priceMajor($service),
            'priceCurrency' => $this->stringValue($service->getAttribute('price_currency')),
        ];
    }

    /** @return array<string, mixed> */
    public function booking(Service $service, string $locale): array
    {
        return [
            ...$this->handle($service, $locale),
            'formats' => $service->supportedFormats(),
        ];
    }

    private function localizedValue(Service $service, string $field, string $locale): ?string
    {
        $primary = $locale === 'ru' ? $field.'_ru' : $field.'_en';
        $secondary = $locale === 'ru' ? $field.'_en' : $field.'_ru';

        return $this->stringValue($service->getAttribute($primary))
            ?? $this->stringValue($service->getAttribute($secondary));
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function priceMajor(Service $service): ?string
    {
        $minor = $service->getAttribute('price_minor');
        $currency = $this->stringValue($service->getAttribute('price_currency'));

        if ($minor === null || $currency === null) {
            return null;
        }

        try {
            return Money::ofMinor($minor, $currency)->toDecimalString();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
