<?php

namespace App\Filament\Resources\ReferralRelationships;

use App\Filament\Resources\ReferralRelationships\Pages\ListReferralRelationships;
use App\Models\User;
use App\Modules\Attribution\Application\AttributionSourcePresentation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Application\ListReferralRelationshipsForCrm;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<ReferralRelationship> */
final class ReferralRelationshipResource extends Resource
{
    protected static ?string $model = ReferralRelationship::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static ?string $navigationLabel = 'Рекомендации';

    protected static string|\UnitEnum|null $navigationGroup = 'Клиенты';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'рекомендация';

    protected static ?string $pluralModelLabel = 'рекомендации';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ViewClients,
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('referrer.full_name')->label('Кто пригласил')->searchable()->wrap(),
                TextColumn::make('referred.full_name')->label('Приглашённый клиент')->searchable()->wrap(),
                TextColumn::make('establishment_method')
                    ->label('Способ установления')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'automatic_referral_link' => 'Автоматическая ссылка',
                        'manual_crm' => 'Назначено в CRM',
                        default => 'Неизвестно',
                    })
                    ->badge()
                    ->visibleFrom('sm'),
                TextColumn::make('referred.attribution.source')
                    ->label('Источник')
                    ->placeholder('Не указан')
                    ->formatStateUsing(fn (mixed $state): string => AttributionSourcePresentation::label(is_string($state) ? $state : null))
                    ->wrap()
                    ->visibleFrom('md'),
                TextColumn::make('registered_at')->label('Регистрация')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('commercial_evidence_count')
                    ->label('Финансовое событие')
                    ->state(fn (ReferralRelationship $record): string => (int) ($record->commercial_evidence_count ?? 0) > 0 ? 'Оплата зафиксирована' : 'Пока нет')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Оплата зафиксирована' ? 'success' : 'gray'),
                TextColumn::make('commercial_evidence_max_observed_at')
                    ->label('Дата зафиксированной оплаты')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->visibleFrom('lg'),
            ])
            ->defaultSort('registered_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(ListReferralRelationshipsForCrm::class)->query($actor);
    }

    public static function getRecordTitle(?Model $record): string
    {
        if (! $record instanceof ReferralRelationship) {
            return 'Рекомендация';
        }

        $referred = $record->getRelation('referred');

        return $referred instanceof Client
            ? trim((string) ($referred->full_name ?? '')) ?: 'Рекомендация'
            : 'Рекомендация';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferralRelationships::route('/'),
        ];
    }
}
