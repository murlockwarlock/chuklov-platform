<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsTaxonomyGroupCatalog;
use App\Modules\Analytics\Application\SaveAnalyticsTaxonomyGroups;
use App\Modules\Analytics\Domain\Enums\AnalyticsTaxonomy;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
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
use Illuminate\Validation\ValidationException;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class AnalyticsTaxonomyGroups extends LocalizedPage
{
    protected static ?string $title = 'Группы аналитики';

    protected static ?string $navigationLabel = 'Группы аналитики';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.analytics-taxonomy-groups';

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
        $this->form->fill(app(AnalyticsTaxonomyGroupCatalog::class)->all(app(OrganizationContext::class)->id()));
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (AnalyticsTaxonomy::cases() as $taxonomy) {
            $sections[] = Section::make(__($taxonomy->label()))
                ->description(__('Эти группы используются в фильтрах аналитики и сохраняются в истории записей. Переименуйте группу под терминологию команды или отключите её, если она больше не нужна.'))
                ->schema([
                    Placeholder::make($taxonomy->value.'_empty')
                        ->label(__('Список пока пуст'))
                        ->content(__('Добавьте первую группу кнопкой «Добавить группу», чтобы использовать этот фильтр в аналитике.'))
                        ->visible(fn (Get $get): bool => ! is_array($get($taxonomy->value)) || $get($taxonomy->value) === [])
                        ->columnSpanFull(),
                    Repeater::make($taxonomy->value)
                        ->label(__('Группы'))
                        ->helperText(__('Пример: «Опорно-двигательная система». Это иллюстрация формата; используйте термины вашей команды.'))
                        ->schema([
                            Hidden::make('id')->dehydrated(true),
                            TextInput::make('name')->label(__('Название группы'))->required()->maxLength(255),
                            Hidden::make('original_name')->dehydrated(true),
                            Toggle::make('is_active')
                                ->label(__('Активна'))
                                ->helperText(__('Отключённая группа не предлагается в новых фильтрах, но прежняя история остаётся читаемой.'))
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
                ->columnSpanFull();
        }

        return $schema->components($sections)->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('analytics-taxonomy-groups-form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')->label(__('Сохранить группы'))->submit('save'),
                ]),
            ]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        try {
            $state = $this->form->getState();
            foreach (AnalyticsTaxonomy::cases() as $taxonomy) {
                $groups = array_values((array) ($state[$taxonomy->value] ?? []));
                foreach ($groups as $index => $group) {
                    if (is_array($group)) {
                        $groups[$index]['sort_order'] = $index;
                    }
                }
                $state[$taxonomy->value] = $groups;
            }
            app(SaveAnalyticsTaxonomyGroups::class)->handle($actor, $state);
            $this->mount();
            Notification::make()->success()->title(__('Группы аналитики сохранены'))->send();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            Notification::make()->danger()->title(is_string($message) ? $message : __('Проверьте группы аналитики.'))->send();
        }
    }

    private static function groupItemLabel(array $state): string
    {
        $name = trim((string) ($state['name'] ?? ''));
        $name = $name !== '' ? $name : __('Новая группа');

        return $name.' · '.(($state['is_active'] ?? true) ? __('Активна') : __('Отключена'));
    }
}
