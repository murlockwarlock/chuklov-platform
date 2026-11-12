<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Identity\Application\ProfileReferenceData;
use App\Modules\Scheduling\Application\ListBookableServices;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Application\SpecialistImageUrlResolver;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistExperience;

final class ProjectPortalSpecialist
{
    public function __construct(
        private readonly SpecialistImageUrlResolver $imageResolver,
        private readonly ProfileReferenceData $referenceData,
        private readonly ProjectPortalService $serviceProjection,
        private readonly ListBookableServices $bookableServices,
    ) {}

    /** @return array<string, mixed> */
    public function compact(Specialist $specialist, string $locale, string $detailsUrl): array
    {
        return [
            'id' => (int) $specialist->getKey(),
            'displayName' => (string) $specialist->display_name,
            'imageUrl' => $this->imageResolver->resolve($specialist),
            'specialization' => $this->stringValue($specialist->specialization),
            'experienceLabel' => SpecialistExperience::fromYear($specialist->practices_since_year)?->label($locale),
            'qualification' => $this->shortText($specialist->qualification),
            'city' => $this->location($specialist, $locale),
            'detailsUrl' => $detailsUrl,
        ];
    }

    /** @return array<string, mixed> */
    public function full(Specialist $specialist, string $locale, ?int $serviceId = null): array
    {
        $contextService = null;
        $services = $this->activeServices($specialist, $locale);

        if ($serviceId !== null) {
            $contextService = collect($services)->first(fn (array $service): bool => (int) $service['id'] === $serviceId);
        }

        return [
            'id' => (int) $specialist->getKey(),
            'displayName' => (string) $specialist->display_name,
            'imageUrl' => $this->imageResolver->resolve($specialist),
            'specialization' => $this->stringValue($specialist->specialization),
            'experienceLabel' => SpecialistExperience::fromYear($specialist->practices_since_year)?->label($locale),
            'qualification' => $this->stringValue($specialist->qualification),
            'bio' => $this->stringValue($specialist->bio),
            'country' => $this->country($specialist, $locale),
            'city' => $this->stringValue($specialist->city),
            'contacts' => $this->publicContacts($specialist, $locale),
            'services' => $services,
            'bookingUrl' => route('portal.bookings.create', $contextService === null
                ? []
                : ['service_id' => $contextService['id'], 'specialist_id' => $specialist->getKey()]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function activeServices(Specialist $specialist, string $locale): array
    {
        $bookable = $this->bookableServices->handle()->keyBy(fn (Service $service): int => (int) $service->getKey());
        if ($bookable->isEmpty()) {
            return [];
        }

        $services = SpecialistServiceAssignment::query()
            ->where('organization_id', $specialist->organization_id)
            ->where('specialist_id', $specialist->getKey())
            ->whereIn('service_id', $bookable->keys())
            ->with('service')
            ->get()
            ->map(function (SpecialistServiceAssignment $assignment) use ($bookable, $locale, $specialist): ?array {
                $service = $assignment->service;
                if (! $bookable->has($service->getKey())) {
                    return null;
                }

                return [
                    ...$this->serviceProjection->handle($service, $locale),
                    'bookingUrl' => route('portal.bookings.create', [
                        'service_id' => $service->getKey(),
                        'specialist_id' => $specialist->getKey(),
                    ]),
                ];
            })
            ->filter()
            ->sortBy('name')
            ->values()
            ->all();

        return array_values($services);
    }

    /** @return list<array{type: string, label: string, value: string, url: string}> */
    private function publicContacts(Specialist $specialist, string $locale): array
    {
        $contacts = [];
        if ($specialist->phone_visible && $this->stringValue($specialist->phone) !== null) {
            $phone = (string) $specialist->phone;
            $contacts[] = ['type' => 'phone', 'label' => $locale === 'ru' ? 'Телефон' : 'Phone', 'value' => $phone, 'url' => 'tel:'.(preg_replace('/[^+\d]/', '', $phone) ?? '')];
        }
        if ($specialist->email_visible && $this->stringValue($specialist->email) !== null) {
            $email = (string) $specialist->email;
            $contacts[] = ['type' => 'email', 'label' => 'Email', 'value' => $email, 'url' => 'mailto:'.$email];
        }
        if ($specialist->telegram_visible && $this->stringValue($specialist->telegram) !== null) {
            $telegram = ltrim((string) $specialist->telegram, '@');
            $contacts[] = ['type' => 'telegram', 'label' => 'Telegram', 'value' => '@'.$telegram, 'url' => 'https://t.me/'.$telegram];
        }

        return $contacts;
    }

    private function location(Specialist $specialist, string $locale): ?string
    {
        $city = $this->stringValue($specialist->city);
        $country = $this->country($specialist, $locale);

        return match (true) {
            $city !== null && $country !== null => $city.', '.$country,
            $city !== null => $city,
            default => $country,
        };
    }

    private function country(Specialist $specialist, string $locale): ?string
    {
        $country = $this->stringValue($specialist->country);
        if ($country === null) {
            return null;
        }

        return $this->referenceData->countryName($country, $locale)
            ?? (preg_match('/\A[A-Z]{2}\z/i', $country) === 1 ? null : $country);
    }

    private function shortText(mixed $value): ?string
    {
        $value = $this->stringValue($value);
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > 180 ? mb_substr($value, 0, 177).'...' : $value;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
