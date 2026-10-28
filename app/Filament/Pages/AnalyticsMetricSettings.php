<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsMetricConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
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

    public function mount(): void
    {
        $metrics = app(AnalyticsMetricConfiguration::class)->values();
        $this->form->fill([
            'therapy_effectiveness_formula_reference' => $metrics['therapy_effectiveness']['formula_reference'],
            'ltv_percent_formula_reference' => $metrics['ltv_percent']['formula_reference'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Эффективность терапии, %'))
                ->description(__('Поле хранит утверждённое владельцем описание формулы. Сам процент не рассчитывается, пока формула не подключена к безопасному типизированному источнику данных.'))
                ->schema([
                    Placeholder::make('therapy_effectiveness_status')
                        ->label(__('Статус расчёта'))
                        ->content(__('Расчёт отключён до утверждения формулы и её источников. Абсолютное снижение боли доступно в консоли аналитики.'))
                        ->columnSpanFull(),
                    Textarea::make('therapy_effectiveness_formula_reference')
                        ->label(__('Описание формулы владельца'))
                        ->helperText(__('Укажите смысл, источники и правила округления. Это описание не выполняется как код.'))
                        ->rows(4)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make(__('LTV клиентов, %'))
                ->description(__('LTV в процентах не включается без однозначной бизнес-формулы. Денежный LTV остаётся доступен в текущем финансовом отчёте.'))
                ->schema([
                    Placeholder::make('ltv_percent_status')
                        ->label(__('Статус расчёта'))
                        ->content(__('Расчёт отключён до определения смысла процента и его базы.'))
                        ->columnSpanFull(),
                    Textarea::make('ltv_percent_formula_reference')
                        ->label(__('Описание формулы владельца'))
                        ->helperText(__('Укажите смысл, базу сравнения и период. Это описание не выполняется как код.'))
                        ->rows(4)
                        ->maxLength(2000)
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
        return Form::make([EmbeddedSchema::make('form')])
            ->id('analytics-metric-settings-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([
                Action::make('save')->label(__('Сохранить описание формул'))->submit('save'),
            ])]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        try {
            app(AnalyticsMetricConfiguration::class)->save($actor, $this->form->getState());
            Notification::make()->success()->title(__('Описание формул сохранено'))->send();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            Notification::make()->danger()->title(is_string($message) ? $message : __('Проверьте заполнение полей.'))->send();
        }
    }
}
