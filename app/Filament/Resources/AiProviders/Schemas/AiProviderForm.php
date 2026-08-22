<?php

namespace App\Filament\Resources\AiProviders\Schemas;

use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AiProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('provider_name')
                    ->label('Провайдер')
                    ->options(AiProviderCatalog::options())
                    ->native(false)
                    ->live()
                    ->disabled(fn (?AiProviderConfiguration $record): bool => $record !== null)
                    ->required()
                    ->helperText('Выберите поддерживаемый провайдер из каталога.'),
                TextInput::make('display_name')
                    ->label('Название в CRM')
                    ->required()
                    ->maxLength(120),
                TextInput::make('api_key')
                    ->label(fn (string $operation): string => $operation === 'create' ? 'API-ключ' : 'Новый API-ключ')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->helperText(fn (Get $get): string => self::apiKeyHelp($get('provider_name')))
                    ->nullable()
                    ->dehydrated(fn (mixed $state): bool => filled($state))
                    ->required(fn (Get $get, string $operation): bool => $operation === 'create' && blank($get('credential_id'))),
                Toggle::make('is_enabled')
                    ->label('Провайдер включен')
                    ->default(true),
                Section::make('Дополнительно: использовать сохранённый ключ')
                    ->description('Выберите уже сохранённый ключ, если он был добавлен ранее. Новый API-ключ надёжно сохраняется автоматически.')
                    ->collapsed()
                    ->schema([
                        Select::make('credential_id')
                            ->label('Сохранённый API-ключ')
                            ->placeholder('Не выбирать')
                            ->options(fn (Get $get): array => self::credentialOptions($get('provider_name')))
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => self::credentialOptions($get('provider_name'), $search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::credentialLabel($value))
                            ->optionsLimit(50)
                            ->searchable()
                            ->native(false),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function apiKeyHelp(mixed $providerName): string
    {
        try {
            $provider = AiProviderCatalog::label($providerName);
        } catch (\InvalidArgumentException) {
            $provider = 'выбранного провайдера';
        }

        return "Ключ доступа из личного кабинета {$provider}. После сохранения ключ больше не показывается; затем нажмите «Проверить связь».";
    }

    /** @return array<int|string, string> */
    private static function credentialOptions(mixed $providerName, string $search = ''): array
    {
        $orgId = app(OrganizationContext::class)->id();
        $supportedProvider = self::supportedProvider($providerName);

        if (is_string($providerName) && trim($providerName) !== '' && $supportedProvider === null) {
            return [];
        }

        $query = OrganizationCredential::query()
            ->where('organization_id', $orgId)
            ->whereIn('provider', AiProviderCatalog::keys())
            ->when($supportedProvider !== null, function (Builder $query) use ($supportedProvider): void {
                $query->where('provider', $supportedProvider);
            })
            ->when(trim($search) !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('credential_name', 'like', '%'.trim($search).'%')
                        ->orWhere('provider', 'like', '%'.trim($search).'%');
                });
            })
            ->orderBy('credential_name')
            ->limit(50);

        return $query
            ->get(['id', 'organization_id', 'provider', 'credential_name', 'status', 'last_rotated_at'])
            ->mapWithKeys(static fn (OrganizationCredential $credential): array => [
                $credential->getKey() => self::credentialDisplayLabel($credential),
            ])
            ->all();
    }

    private static function credentialLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $credential = OrganizationCredential::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey((int) $value)
            ->first(['id', 'organization_id', 'provider', 'credential_name', 'status', 'last_rotated_at']);

        return $credential instanceof OrganizationCredential
            ? self::credentialDisplayLabel($credential)
            : 'Сохранённый API-ключ недоступен';
    }

    private static function credentialDisplayLabel(OrganizationCredential $credential): string
    {
        $status = $credential->status === CredentialStatus::Active ? 'активны' : 'отключены';

        return $credential->credential_name.' · '.AiProviderCatalog::label($credential->provider).' · '.$status;
    }

    private static function supportedProvider(mixed $providerName): ?string
    {
        if (! is_string($providerName) || trim($providerName) === '') {
            return null;
        }

        try {
            return AiProviderCatalog::normalize($providerName);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
