<?php

namespace App\Filament\Resources\TrackerPlans;

use App\Filament\Resources\TrackerPlans\Pages\CreateTrackerPlan;
use App\Filament\Resources\TrackerPlans\Pages\EditTrackerPlan;
use App\Filament\Resources\TrackerPlans\Pages\ListTrackerPlans;
use App\Filament\Resources\TrackerPlans\Schemas\TrackerPlanForm;
use App\Filament\Resources\TrackerPlans\Tables\TrackerPlansTable;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class TrackerPlanResource extends Resource
{
    protected static ?string $model = TrackerPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Трекер и тарифы';

    protected static string|\UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'тариф';

    protected static ?string $pluralModelLabel = 'тарифы';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageSettings,
        );
    }

    public static function form(Schema $schema): Schema
    {
        return TrackerPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrackerPlansTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('organization_id', app(OrganizationContext::class)->id())->with('currentVersion');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrackerPlans::route('/'),
            'create' => CreateTrackerPlan::route('/create'),
            'edit' => EditTrackerPlan::route('/{record}/edit'),
        ];
    }
}
