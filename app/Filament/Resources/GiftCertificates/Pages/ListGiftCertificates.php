<?php

namespace App\Filament\Resources\GiftCertificates\Pages;

use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Filament\Resources\GiftCertificates\GiftCertificateResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\LocalizedListRecords;
use App\Models\User;
use App\Modules\Commerce\Application\CreateCrmGiftCertificateSale;
use App\Modules\Commerce\Application\ListGiftCertificateOfferings;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ListGiftCertificates extends LocalizedListRecords
{
    protected static string $resource = GiftCertificateResource::class;

    protected static ?string $title = 'Подарочные сертификаты';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sellGiftCertificate')
                ->label(__('Продать сертификат'))
                ->icon('heroicon-o-gift')
                ->color('success')
                ->extraAttributes(['data-testid' => 'sell-gift-certificate'])
                ->modalHeading(__('Продать сертификат'))
                ->modalDescription(__('Сертификат будет выпущен после полной оплаты созданной покупки.'))
                ->modalSubmitActionLabel(__('Создать продажу'))
                ->schema([
                    Select::make('client_id')
                        ->label(__('Клиент'))
                        ->options([])
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::clientOptions($search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => self::clientOptionLabel($value))
                        ->required(),
                    Select::make('product_id')
                        ->label(__('Сертификат'))
                        ->options(fn (): array => app(ListGiftCertificateOfferings::class)->options())
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->required(),
                    Placeholder::make('nominal')
                        ->label(__('Номинал'))
                        ->content(fn (Get $get): string => app(ListGiftCertificateOfferings::class)->amountLabel(
                            self::selectedProductId($get('product_id')),
                        ))
                        ->visible(fn (Get $get): bool => self::selectedProductId($get('product_id')) !== null),
                    Placeholder::make('currency')
                        ->label(__('Валюта'))
                        ->content(fn (Get $get): string => app(ListGiftCertificateOfferings::class)->currencyLabel(
                            self::selectedProductId($get('product_id')),
                        ))
                        ->visible(fn (Get $get): bool => self::selectedProductId($get('product_id')) !== null),
                    Placeholder::make('catalog_empty')
                        ->label(__('Каталог услуг'))
                        ->content(__('Сначала добавьте подарочный сертификат в Каталоге услуг.'))
                        ->visible(fn (): bool => app(ListGiftCertificateOfferings::class)->options() === [])
                        ->columnSpanFull(),
                    Hidden::make('idempotency_key')
                        ->default(fn (): string => 'crm-gift-sale-'.Str::uuid()->toString()),
                    Actions::make([
                        Action::make('openCatalog')
                            ->label(__('Перейти в Каталог услуг'))
                            ->url(fn (): string => ServiceResource::getUrl('index'))
                            ->icon('heroicon-o-arrow-top-right-on-square'),
                    ])
                        ->visible(fn (): bool => app(ListGiftCertificateOfferings::class)->options() === [])
                        ->columnSpanFull(),
                ])
                ->visible(function (): bool {
                    $actor = auth()->user();

                    return $actor instanceof User && app(FinanceAuthorization::class)->allowsManage($actor);
                })
                ->action(function (Action $action, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $obligation = app(CreateCrmGiftCertificateSale::class)->handle(
                        actor: $actor,
                        clientId: (int) ($data['client_id'] ?? 0),
                        productId: (int) ($data['product_id'] ?? 0),
                        idempotencyKey: (string) ($data['idempotency_key'] ?? ''),
                    );

                    Notification::make()
                        ->success()
                        ->title(__('Продажа создана. Запишите оплату, чтобы выпустить сертификат.'))
                        ->send();
                    $action->redirect(FinancialObligationResource::getUrl('view', ['record' => $obligation->getKey()]));
                }),
        ];
    }

    /** @return array<int, string> */
    private static function clientOptions(string $search): array
    {
        $search = trim($search);
        if ($search === '' || mb_strlen($search) > 160) {
            return [];
        }

        $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $pattern = '%'.addcslashes($search, '\\%_').'%';
        $organizationId = app(OrganizationContext::class)->id();

        return Client::query()
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($operator, $pattern): void {
                $query
                    ->where('full_name', $operator, $pattern)
                    ->orWhere('email', $operator, $pattern)
                    ->orWhere('phone', $operator, $pattern);
            })
            ->orderBy('full_name')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'organization_id', 'full_name', 'email', 'phone'])
            ->mapWithKeys(fn (Client $client): array => [$client->getKey() => self::formatClientLabel($client)])
            ->all();
    }

    private static function clientOptionLabel(mixed $value): ?string
    {
        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        $client = Client::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey((int) $value)
            ->first();

        return $client instanceof Client ? self::formatClientLabel($client) : null;
    }

    private static function formatClientLabel(Client $client): string
    {
        return implode(' · ', array_values(array_filter([
            trim((string) $client->full_name),
            trim((string) $client->phone),
            trim((string) $client->email),
        ], static fn (string $value): bool => $value !== ''))) ?: __('Клиент без имени');
    }

    private static function selectedProductId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
