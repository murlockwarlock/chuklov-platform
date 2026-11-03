<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Attribution\Application\AttributionSourceCatalog;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
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
use Illuminate\Support\Str;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class AttributionSourceSettings extends LocalizedPage
{
    protected static ?string $title = 'Источники клиентов';

    protected static ?string $navigationLabel = 'Источники клиентов';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    protected string $view = 'filament.pages.attribution-source-settings';

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
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        app(AttributionSourceCatalog::class)->ensureDefaults($actor);
        $rows = app(AttributionSourceCatalog::class)->rows(app(OrganizationContext::class)->id());
        $this->form->fill([
            'sources' => array_map(static fn (array $row): array => [
                ...$row,
                'aliases' => array_map(static fn (string $alias): array => ['value' => $alias], $row['aliases']),
            ], $rows),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Категории источников'))
                    ->description(__('Объединяйте разные варианты одного источника под общим названием в аналитике. Например, instagram, ig и insta можно показывать как один источник — Instagram. Категория «Другое» объединяет только добавленные варианты вроде other и другое; неизвестные значения не подставляются в неё автоматически. Изменение справочника не требует выпуска новой версии программы.'))
                    ->schema([
                        Repeater::make('sources')
                            ->label(__('Источники'))
                            ->helperText(__('Список показывает только краткое описание каждого источника. Нажмите «Настроить», чтобы изменить название или варианты источника. Перетаскивание и кнопки перемещения меняют порядок карточек.'))
                            ->schema([
                                Hidden::make('source_key')->default(fn (): string => 'source_'.Str::lower(Str::random(12))),
                                TextInput::make('label')
                                    ->label(__('Название в аналитике'))
                                    ->helperText(__('Так источник будет называться в отчётах и фильтрах.'))
                                    ->required()
                                    ->maxLength(160),
                                Repeater::make('aliases')
                                    ->label(__('Другие варианты этого источника'))
                                    ->helperText(__('Например: instagram, ig, insta. Добавьте значения из формы, UTM-метки или интеграции — все они будут объединены в один источник в аналитике.'))
                                    ->schema([
                                        TextInput::make('value')
                                            ->label(__('Вариант названия'))
                                            ->placeholder(__('Например: ig'))
                                            ->required()
                                            ->maxLength(120),
                                    ])
                                    ->defaultItems(0)
                                    ->addActionLabel(__('Добавить вариант'))
                                    ->columnSpanFull(),
                                Toggle::make('is_active')
                                    ->label(__('Использовать в аналитике'))
                                    ->helperText(__('Если выключить, источник останется в системе и старых данных, но не будет активной категорией в новых фильтрах аналитики.'))
                                    ->default(true),
                                Hidden::make('sort_order')->dehydrated(true),
                            ])
                            ->columns(['default' => 1, 'sm' => 2])
                            ->defaultItems(0)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => self::sourceItemLabel($state))
                            ->expandAction(fn (Action $action): Action => $action->label(__('Настроить')))
                            ->collapseAction(fn (Action $action): Action => $action->label(__('Свернуть')))
                            ->addActionLabel(__('Добавить источник'))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('attribution-source-settings-form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')->label(__('Сохранить источники'))->submit('save'),
                ]),
            ]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $state = $this->form->getState();
        $sources = array_values(is_array($state['sources'] ?? null) ? $state['sources'] : []);
        $sources = array_map(
            static function (mixed $source, int $index): mixed {
                if (is_array($source)) {
                    $source['sort_order'] = $index;
                }

                return $source;
            },
            $sources,
            array_keys($sources),
        );
        app(AttributionSourceCatalog::class)->save(
            $actor,
            $sources,
        );
        Notification::make()->success()->title(__('Источники сохранены'))->send();
    }

    private static function sourceItemLabel(array $state): ?string
    {
        $label = trim((string) ($state['label'] ?? ''));
        $aliases = collect((array) ($state['aliases'] ?? []))
            ->map(static fn (mixed $alias): string => is_array($alias) ? trim((string) ($alias['value'] ?? '')) : '')
            ->filter()
            ->take(4)
            ->implode(', ');

        if ($label === '' && $aliases === '') {
            return __('Новый источник');
        }

        $summary = $label !== '' ? $label : __('Без названия');
        if ($aliases !== '') {
            $summary .= ' — '.__('объединяет').': '.$aliases;
        }

        return $summary.' · '.(($state['is_active'] ?? true) ? __('В аналитике') : __('Отключён'));
    }
}
