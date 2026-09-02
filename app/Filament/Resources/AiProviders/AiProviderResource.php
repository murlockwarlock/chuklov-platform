<?php

namespace App\Filament\Resources\AiProviders;

use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\AiProviders\Pages\ListAiProviders;
use App\Filament\Resources\AiProviders\RelationManagers\ModelsRelationManager;
use App\Filament\Resources\AiProviders\Schemas\AiProviderForm;
use App\Modules\AI\Application\Actions\TestProviderConnection;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

final class AiProviderResource extends Resource
{
    protected static ?string $model = AiProviderConfiguration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Провайдеры и модели';

    protected static string|\UnitEnum|null $navigationGroup = 'Искусственный интеллект';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'провайдер AI';

    protected static ?string $pluralModelLabel = 'провайдеры AI';

    protected static ?string $breadcrumb = 'Провайдеры и модели';

    public static function form(Schema $schema): Schema
    {
        return AiProviderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('display_name')->label('Название')->searchable()->sortable(),
                TextColumn::make('provider_name')
                    ->label('Провайдер')
                    ->formatStateUsing(fn ($state): string => self::providerLabel($state)),
                TextColumn::make('credential.credential_name')
                    ->label('API-ключ')
                    ->placeholder('Не подключён')
                    ->formatStateUsing(function ($state, AiProviderConfiguration $record): string {
                        if (! filled($state)) {
                            return 'Не подключён';
                        }

                        return $record->credential?->status === CredentialStatus::Active
                            ? 'Подключён: '.$state
                            : 'Отключён: '.$state;
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('health_status')
                    ->label('Состояние')
                    ->badge()
                    ->color(fn ($state): string => match ($state instanceof ProviderHealthStatus ? $state->value : (string) $state) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'unavailable' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof ProviderHealthStatus ? $state->label() : (string) $state),
                TextColumn::make('is_enabled')->label('Статус')->formatStateUsing(fn ($state) => $state ? 'Включен' : 'Отключен'),
                TextColumn::make('models_count')->counts('models')->label('Моделей')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Изменён')->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('Сервисов AI пока нет')
            ->emptyStateDescription('Подключите сервис AI, которым будет пользоваться Chuklov. Сначала выберите провайдера и добавьте API-ключ.')
            ->recordActions([
                Action::make('test_connection')
                    ->label('Проверить связь')
                    ->icon(Heroicon::OutlinedSignal)
                    ->iconButton()
                    ->tooltip('Проверить связь с провайдером')
                    ->color('gray')
                    ->action(function (AiProviderConfiguration $record): void {
                        $actor = Auth::user();
                        if ($actor !== null) {
                            $result = app(TestProviderConnection::class)->handle($actor, $record->id);
                            if ($result['success']) {
                                Notification::make()
                                    ->title('Связь проверена успешно')
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Проблема при проверке связи')
                                    ->body('Провайдер требует внимания. Проверьте API-ключ и состояние подключения.')
                                    ->danger()
                                    ->send();
                            }
                        }
                    }),
                EditAction::make()
                    ->label('Редактировать')
                    ->icon(Heroicon::OutlinedPencil)
                    ->iconButton()
                    ->tooltip('Редактировать провайдера'),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with(['credential' => function (Relation $query): void {
                $query
                    ->where('organization_id', app(OrganizationContext::class)->id())
                    ->select(['id', 'organization_id', 'provider', 'credential_name', 'status', 'last_rotated_at']);
            }]);
    }

    public static function getRelations(): array
    {
        return [
            ModelsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiProviders::route('/'),
            'create' => CreateAiProvider::route('/create'),
            'edit' => EditAiProvider::route('/{record}/edit'),
        ];
    }

    private static function providerLabel(mixed $provider): string
    {
        try {
            return AiProviderCatalog::label($provider);
        } catch (\InvalidArgumentException) {
            return 'Провайдер требует проверки';
        }
    }
}
