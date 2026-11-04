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
use App\Modules\Services\Domain\Enums\CatalogItemType;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
                ->description(__('Группы покупок используются в фильтрах и отчётах аналитики. Объедините близкие типы каталога под понятным названием. Если список пуст, аналитика по группам покупок будет недоступна; старые покупки при изменении настройки не удаляются.'))
                ->schema([
                    Placeholder::make('groups_empty')
                        ->label(__('Список пока пуст'))
                        ->content(__('Добавьте первую группу и выберите типы покупок, которые должны в неё входить.'))
                        ->visible(fn (Get $get): bool => ! is_array($get('groups')) || $get('groups') === [])
                        ->columnSpanFull(),
                    Repeater::make('groups')
                        ->label(__('Группы'))
                        ->helperText(__('Например: «Консультации» для разовых сеансов. Это только название группы — сами покупки и их история сохраняются отдельно.'))
                        ->schema([
                            Hidden::make('key')->default(fn (): string => 'purchase_'.Str::lower(Str::random(20)))->dehydrated(),
                            TextInput::make('label')
                                ->label(__('Название'))
                                ->placeholder(__('Например: Консультации'))
                                ->required()
                                ->maxLength(120),
                            Select::make('catalog_types')
                                ->label(__('Типы каталога'))
                                ->multiple()
                                ->searchable()
                                ->options(fn (Get $get): array => $this->catalogTypeOptions($this->selectedCatalogTypes($get('catalog_types'))))
                                ->required()
                                ->helperText(__('Выберите понятные категории покупок. Старые исторические типы сохраняются автоматически.')),
                            Toggle::make('active')
                                ->label(__('Активна'))
                                ->helperText(__('Отключённая группа исчезает из новых фильтров, но не меняет сохранённые покупки.'))
                                ->default(true),
                            Hidden::make('sort_order')->dehydrated(true),
                        ])
                        ->columns(['default' => 1, 'sm' => 3])
                        ->defaultItems(0)
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => self::groupItemLabel($state))
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
        $mappedActiveTypes = [];
        $keys = [];
        foreach (array_values((array) ($this->form->getState()['groups'] ?? [])) as $index => $group) {
            if (! is_array($group)) {
                continue;
            }
            $active = (bool) ($group['active'] ?? true);
            $types = array_values(array_filter(array_map('strval', (array) ($group['catalog_types'] ?? []))));
            if ($active) {
                foreach ($types as $type) {
                    if (isset($mappedActiveTypes[$type])) {
                        $this->notifyValidation(__('Один исторический тип покупки нельзя включить сразу в несколько активных групп.'));

                        return;
                    }
                    $mappedActiveTypes[$type] = true;
                }
            }
            $key = trim((string) ($group['key'] ?? ''));
            if ($key === '' || isset($keys[$key])) {
                do {
                    $key = 'purchase_'.Str::lower(Str::random(20));
                } while (isset($keys[$key]));
            }
            $keys[$key] = true;
            $groups[] = [
                'key' => $key,
                'label' => trim((string) ($group['label'] ?? '')),
                'catalog_types' => $types,
                'active' => $active,
                'sort_order' => $index,
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

    private function notifyValidation(string $message): void
    {
        Notification::make()
            ->danger()
            ->title(__('Не удалось сохранить группы покупок'))
            ->body($message)
            ->send();
    }

    private static function groupItemLabel(array $state): string
    {
        $label = trim((string) ($state['label'] ?? ''));
        $label = $label !== '' ? $label : __('Новая группа');

        return $label.' · '.(($state['active'] ?? true) ? __('Активна') : __('Отключена'));
    }

    /**
     * @param  list<string>  $selected
     * @return array<string, string>
     */
    private function catalogTypeOptions(array $selected): array
    {
        $options = [
            CatalogItemType::Service->value => __('Разовые сеансы'),
            'program' => __('Пакеты и программы'),
            CatalogItemType::GiftCertificate->value => __('Сертификаты'),
            CatalogItemType::PhysicalProduct->value => __('Товары'),
            CatalogItemType::OnlineProduct->value => __('Онлайн-продукты'),
            'unknown' => __('Другие покупки'),
        ];
        foreach (app(PurchaseGroupCatalog::class)->rows(app(OrganizationContext::class)->id(), false) as $row) {
            foreach ($row['catalog_types'] as $type) {
                $options[$type] ??= __('Исторический тип: :type', ['type' => $type]);
            }
        }
        foreach ($selected as $type) {
            if ($type !== '') {
                $options[$type] ??= __('Исторический тип: :type', ['type' => $type]);
            }
        }

        return $options;
    }

    /** @return list<string> */
    private function selectedCatalogTypes(mixed $state): array
    {
        $selected = [];
        foreach ((array) $state as $value) {
            if (is_string($value) && $value !== '') {
                $selected[] = $value;
            }
        }

        return $selected;
    }
}
