<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Analytics\Application\ClientAgeRangeCatalog;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
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
final class AnalyticsAgeRangeSettings extends LocalizedPage
{
    protected static ?string $title = 'Возрастные диапазоны';

    protected static ?string $navigationLabel = 'Возрастные диапазоны';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.analytics-age-range-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public static function canAccess(): bool
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }
        try {
            return app(OrganizationAuthorizer::class)->allows($actor, app(OrganizationContext::class)->organization(), OrganizationPermission::ManageSettings);
        } catch (LogicException) {
            return false;
        }
    }

    public function mount(): void
    {
        $this->form->fill(['ranges' => app(ClientAgeRangeCatalog::class)->rows(app(OrganizationContext::class)->id())]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Возрастные диапазоны'))
                ->description(__('Эти названия используются в фильтрах аналитики и могут быть заменены владельцем без изменения кода.'))
                ->schema([
                    Repeater::make('ranges')
                        ->label(__('Диапазоны'))
                        ->schema([
                            TextInput::make('key')->label(__('Ключ'))->required()->maxLength(40),
                            TextInput::make('label')->label(__('Название'))->required()->maxLength(80),
                            TextInput::make('min')->label(__('От'))->integer()->minValue(0)->maxValue(150)->required(),
                            TextInput::make('max')->label(__('До'))->integer()->minValue(0)->maxValue(150)->nullable(),
                            Toggle::make('active')->label(__('Активен'))->default(true),
                            TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                        ])
                        ->columns(['default' => 1, 'sm' => 4])
                        ->defaultItems(0)
                        ->reorderable()
                        ->addActionLabel(__('Добавить диапазон'))
                        ->columnSpanFull(),
                ])->columnSpanFull(),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('analytics-age-range-settings-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label(__('Сохранить диапазоны'))->submit('save')])]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $rows = [];
        foreach ((array) ($this->form->getState()['ranges'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = [
                'key' => trim((string) ($row['key'] ?? '')),
                'label' => trim((string) ($row['label'] ?? '')),
                'min' => (int) ($row['min'] ?? 0),
                'max' => ($row['max'] ?? '') === '' ? null : (int) $row['max'],
                'active' => (bool) ($row['active'] ?? true),
                'sort_order' => max(0, (int) ($row['sort_order'] ?? 0)),
            ];
        }
        app(SetOrganizationSetting::class)->handle($actor, OrganizationSettingKey::AnalyticsAgeRanges, json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        Notification::make()->success()->title(__('Возрастные диапазоны сохранены'))->send();
    }
}
