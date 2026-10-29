<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class AnalyticsMetricSettings extends LocalizedPage
{
    protected static ?string $title = 'Метрики аналитики';

    protected static ?string $navigationLabel = 'Метрики аналитики';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.analytics-metric-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public static function canAccess(): bool
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }

        try {
            return app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ManageSettings,
            );
        } catch (LogicException) {
            return false;
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Снижение боли, %'))
                ->description(__('Стандартная метрика рассчитывается по оценкам боли до и после сеанса и не требует ручной формулы.'))
                ->schema([
                    Placeholder::make('therapy_effectiveness_status')
                        ->label(__('Как считается'))
                        ->content(__('Если значение до сеанса больше нуля и значение после заполнено: (до − после) ÷ до × 100. При отсутствии данных процент не рассчитывается.'))
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make(__('LTV новых клиентов'))
                ->description(__('В аналитике доступен существующий денежный LTV новых клиентов. Процентная версия не рассчитывается без утверждённой базы сравнения.'))
                ->schema([
                    Placeholder::make('ltv_percent_status')
                        ->label(__('Процентная версия'))
                        ->content(__('Не рассчитывается: база сравнения и бизнес-смысл процента ещё не определены.'))
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])->id('analytics-metric-settings-form');
    }
}
