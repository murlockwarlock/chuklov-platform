<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Analytics\Application\PurchaseGroupCatalog;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
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
final class AnalyticsPurchaseGroupSettings extends LocalizedPage
{
    protected static ?string $title = 'Группы покупок';

    protected static ?string $navigationLabel = 'Группы покупок';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.analytics-purchase-group-settings';

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
        $this->form->fill(['groups' => app(PurchaseGroupCatalog::class)->rows(app(OrganizationContext::class)->id())]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Группы покупок'))
                ->description(__('Одна группа может включать несколько исторических типов каталога. Изменение названия не меняет сохранённые покупки.'))
                ->schema([
                    Repeater::make('groups')
                        ->label(__('Группы'))
                        ->schema([
                            TextInput::make('key')->label(__('Ключ'))->required()->maxLength(40),
                            TextInput::make('label')->label(__('Название'))->required()->maxLength(120),
                            TagsInput::make('catalog_types')
                                ->label(__('Типы каталога'))
                                ->required()
                                ->placeholder(__('service, program, ...'))
                                ->helperText(__('Стабильные типы из сохранённого снимка покупки.')),
                            Toggle::make('active')->label(__('Активна'))->default(true),
                            TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                        ])
                        ->columns(['default' => 1, 'sm' => 3])
                        ->defaultItems(0)
                        ->reorderable()
                        ->addActionLabel(__('Добавить группу'))
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
            ->id('analytics-purchase-groups-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label(__('Сохранить группы покупок'))->submit('save')])]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $groups = [];
        foreach ((array) ($this->form->getState()['groups'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }
            $groups[] = [
                'key' => trim((string) ($group['key'] ?? '')),
                'label' => trim((string) ($group['label'] ?? '')),
                'catalog_types' => array_values(array_filter(array_map('strval', (array) ($group['catalog_types'] ?? [])))),
                'active' => (bool) ($group['active'] ?? true),
                'sort_order' => max(0, (int) ($group['sort_order'] ?? 0)),
            ];
        }

        app(SetOrganizationSetting::class)->handle(
            $actor,
            OrganizationSettingKey::AnalyticsPurchaseGroups,
            json_encode($groups, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        $this->mount();
        Notification::make()->success()->title(__('Группы покупок сохранены'))->send();
    }
}
