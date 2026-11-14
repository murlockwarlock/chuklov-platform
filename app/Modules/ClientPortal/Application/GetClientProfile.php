<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Broadcasts\Domain\Models\BroadcastClientProfile;
use App\Modules\Identity\Application\ListPublishedLegalDocuments;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Support\RichText\RichTextDocument;
use App\Support\SupportedLocale;
use Illuminate\Support\Collection;

final class GetClientProfile
{
    /** @var array<string, array{ru: string, en: string}> */
    private const TIMEZONE_LABELS = [
        'UTC' => ['ru' => 'UTC', 'en' => 'UTC'],
        'Asia/Almaty' => ['ru' => 'Алматы', 'en' => 'Almaty'],
        'Asia/Aqtau' => ['ru' => 'Актау', 'en' => 'Aktau'],
        'Asia/Atyrau' => ['ru' => 'Атырау', 'en' => 'Atyrau'],
        'Asia/Aqtobe' => ['ru' => 'Актобе', 'en' => 'Aktobe'],
        'Asia/Bangkok' => ['ru' => 'Бангкок', 'en' => 'Bangkok'],
        'Asia/Dubai' => ['ru' => 'Дубай', 'en' => 'Dubai'],
        'Asia/Tashkent' => ['ru' => 'Ташкент', 'en' => 'Tashkent'],
        'Asia/Tokyo' => ['ru' => 'Токио', 'en' => 'Tokyo'],
        'Europe/Berlin' => ['ru' => 'Берлин', 'en' => 'Berlin'],
        'Europe/London' => ['ru' => 'Лондон', 'en' => 'London'],
        'Europe/Moscow' => ['ru' => 'Москва', 'en' => 'Moscow'],
        'Europe/Paris' => ['ru' => 'Париж', 'en' => 'Paris'],
        'America/Los_Angeles' => ['ru' => 'Лос-Анджелес', 'en' => 'Los Angeles'],
        'America/New_York' => ['ru' => 'Нью-Йорк', 'en' => 'New York'],
    ];

    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly ListPublishedLegalDocuments $legalDocuments,
    ) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $client = $this->clientContext->client();
        $documents = $this->legalDocuments->handle($this->locale($client->language));
        $consents = $this->consents($client, $documents);
        $marketingDocument = $documents->first(fn (LegalDocument $document): bool => $document->document_type === ConsentSubject::Marketing->value);
        $marketingConsent = $marketingDocument === null ? null : $consents->get($marketingDocument->getKey());
        $b2bProfile = BroadcastClientProfile::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->first();
        $b2bAnswer = $b2bProfile?->getRawOriginal('b2b_specialist_answer');

        return [
            'currencyPreference' => app(GetClientCurrencyPreference::class)->handle($client),
            'profile' => [
                'fullName' => $client->full_name,
                'firstName' => $client->first_name,
                'lastName' => $client->last_name,
                'middleName' => $client->middle_name,
                'gender' => $client->gender,
                'birthDate' => $client->birth_date?->toDateString(),
                'country' => $client->country,
                'city' => $client->city,
                'email' => $client->email,
                'phone' => $client->phone,
                'timezone' => $client->timezone,
                'locale' => $this->locale($client->language),
                'emailEditable' => ! $client->channelIdentities()
                    ->where('channel', 'email')
                    ->where('verification_status', ChannelIdentityStatus::Verified)
                    ->exists(),
            ],
            'b2bSpecialistAnswer' => $b2bAnswer,
            'legalDocuments' => $documents->map(function (LegalDocument $document) use ($consents): array {
                $consent = $consents->get($document->getKey());
                $subject = ConsentSubject::tryFrom($document->document_type);

                return [
                    'id' => $document->getKey(),
                    'documentType' => $document->document_type,
                    'title' => $subject?->label($this->locale($document->locale)) ?? $document->purpose,
                    'purpose' => $document->purpose,
                    'content' => $document->content,
                    'contentHtml' => RichTextDocument::canonicalHtml($document->content),
                    'version' => $document->version,
                    'isRequired' => $subject?->isRequired() ?? false,
                    'accepted' => $consent instanceof ClientConsent
                        && $consent->version === $document->version
                        && $consent->granted,
                ];
            })->values()->all(),
            'marketingConsent' => $marketingDocument === null ? null : [
                'documentId' => $marketingDocument->getKey(),
                'accepted' => $marketingConsent instanceof ClientConsent
                    && $marketingConsent->version === $marketingDocument->version
                    && $marketingConsent->granted,
            ],
            'timezoneOptions' => $this->timezoneOptions($client->timezone, $this->locale($client->language)),
            'urls' => [
                'family' => app(OrganizationFeatureGate::class)->isEnabled($client->organization,
                    OrganizationFeature::ClientRecords) ? route('portal.family') : null,
                'currency' => route('portal.profile.currency'),
                'cities' => route('portal.profile.cities'),
                'update' => route('portal.profile.update'),
                'consents' => route('portal.profile.consents'),
                'b2bAnswer' => route('portal.profile.b2b-answer'),
            ],
        ];
    }

    private function locale(?string $language): string
    {
        return SupportedLocale::normalize($language, (string) config('portal.default_locale', 'ru'));
    }

    /** @return list<array{value: string, label: string}> */
    public function timezoneOptions(?string $current = null, ?string $locale = null): array
    {
        $timezones = timezone_identifiers_list();
        if ($current !== null && in_array($current, $timezones, true)) {
            $timezones = [$current, ...array_values(array_diff($timezones, [$current]))];
        }

        $locale = $locale === 'en' ? 'en' : 'ru';

        return array_map(fn (string $timezone): array => [
            'value' => $timezone,
            'label' => $this->timezoneLabel($timezone, $locale),
        ], $timezones);
    }

    private function timezoneLabel(string $timezone, string $locale): string
    {
        $city = self::TIMEZONE_LABELS[$timezone][$locale] ?? str_replace('_', ' ', (string) last(explode('/', $timezone)));

        return trim($city) === ''
            ? $timezone
            : $city.' ('.$this->timezoneOffset($timezone).')';
    }

    private function timezoneOffset(string $timezone): string
    {
        $offset = (new \DateTimeZone($timezone))->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $sign = $offset < 0 ? '-' : '+';
        $seconds = abs($offset);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('UTC%s%02d:%02d', $sign, $hours, $minutes);
    }

    /**
     * @param  Collection<int, LegalDocument>  $documents
     * @return Collection<int, ClientConsent>
     */
    private function consents(Client $client, Collection $documents): Collection
    {
        $documentIds = $documents->pluck('id')->all();

        if ($documentIds === []) {
            return collect();
        }

        return ClientConsent::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->whereIn('legal_document_id', $documentIds)
            ->latest('id')
            ->get()
            ->unique('legal_document_id')
            ->keyBy('legal_document_id');
    }
}
