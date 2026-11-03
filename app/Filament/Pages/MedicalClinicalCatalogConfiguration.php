<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Application\ImportMedicalClinicalCatalogCsv;
use App\Modules\Sessions\Application\SaveMedicalClinicalCatalog;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;
use UnitEnum;

/** @property-read Schema $form */
final class MedicalClinicalCatalogConfiguration extends LocalizedPage
{
    protected static ?string $title = 'Клинические справочники';

    protected static ?string $navigationLabel = 'Клинические справочники';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 36;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    protected string $view = 'filament.pages.medical-clinical-catalog-configuration';

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
        $organizationId = app(OrganizationContext::class)->id();
        $tests = MedicalTestDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with(['values' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalTestDefinition $test): array => [
                'key' => $test->key,
                'name' => $test->name,
                'section' => $test->section,
                'is_active' => $test->is_active,
                'sort_order' => $test->sort_order,
                'values' => $test->values->map(static fn (MedicalTestValue $value): array => [
                    'key' => $value->key,
                    'label' => $value->label,
                    'is_abnormal' => $value->is_abnormal,
                    'is_active' => $value->is_active,
                    'sort_order' => $value->sort_order,
                ])->all(),
            ])->all();
        $causes = MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalCauseDefinition $cause): array => [
                'name' => $cause->name,
                'group_name' => $cause->group_name,
                'is_active' => $cause->is_active,
                'sort_order' => $cause->sort_order,
            ])->all();
        $problemTags = MedicalProblemTag::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalProblemTag $tag): array => [
                'name' => $tag->name,
                'group_name' => $tag->group_name,
                'is_active' => $tag->is_active,
                'sort_order' => $tag->sort_order,
            ])->all();
        $this->form->fill(['tests' => $tests, 'causes' => $causes, 'problem_tags' => $problemTags]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Тесты'))
                    ->description(__('Эти тесты появляются у специалиста в блоке «Кинезиологические/мануальные тесты» медицинского сеанса. Отключённые значения нельзя выбрать заново, но они остаются читаемыми в истории. Например: «Название теста» и его допустимые значения.'))
                    ->schema([
                        Placeholder::make('tests_empty')
                            ->label(__('Каталог пока пуст'))
                            ->content(__('Добавьте первый тест кнопкой «Добавить тест». После сохранения он станет доступен в новом медицинском сеансе.'))
                            ->visible(fn (Get $get): bool => ! is_array($get('tests')) || $get('tests') === [])
                            ->columnSpanFull(),
                        Repeater::make('tests')
                            ->schema([
                                Hidden::make('key')->default(fn (): string => 'test_'.Str::lower(Str::random(12))),
                                TextInput::make('name')->label(__('Название теста'))->required()->maxLength(255),
                                TextInput::make('section')->label(__('Раздел'))->maxLength(255),
                                Toggle::make('is_active')->label(__('Активен'))->default(true),
                                TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                                Repeater::make('values')
                                    ->label(__('Допустимые значения'))
                                    ->schema([
                                        Hidden::make('key')->default(fn (): string => 'value_'.Str::lower(Str::random(12))),
                                        TextInput::make('label')->label(__('Название значения'))->required()->maxLength(255),
                                        Toggle::make('is_abnormal')->label(__('Считать нарушением')),
                                        Toggle::make('is_active')->label(__('Активно'))->default(true),
                                        TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                                    ])->columns(2)->defaultItems(1)->reorderable()->addActionLabel(__('Добавить значение'))->columnSpanFull(),
                            ])->columns(2)->defaultItems(0)->reorderable()->collapsible()->addActionLabel(__('Добавить тест'))->columnSpanFull(),
                    ])->columnSpanFull(),
                Section::make(__('Причины нарушений'))
                    ->description(__('Причины из этого списка доступны специалисту для ручной корректировки в медицинском сеансе после выявленного нарушения. Отключённые причины нельзя выбирать заново, но они сохраняются в истории. Например: «Название причины».'))
                    ->schema([
                        Placeholder::make('causes_empty')
                            ->label(__('Справочник пока пуст'))
                            ->content(__('Добавьте первую причину кнопкой «Добавить причину».'))
                            ->visible(fn (Get $get): bool => ! is_array($get('causes')) || $get('causes') === [])
                            ->columnSpanFull(),
                        Repeater::make('causes')
                            ->schema([
                                TextInput::make('name')->label(__('Название причины'))->required()->maxLength(255),
                                TextInput::make('group_name')->label(__('Группа'))->maxLength(255),
                                Toggle::make('is_active')->label(__('Активна'))->default(true),
                                TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                            ])->columns(2)->defaultItems(0)->reorderable()->addActionLabel(__('Добавить причину'))->columnSpanFull(),
                    ])->columnSpanFull(),
                Section::make(__('Категории проблем'))
                    ->description(__('Категории из этого списка используются для классификации жалоб и статистики боли. Они не заменяют свободный текст жалобы. Отключённые категории остаются читаемыми в истории. Например: «Категория проблемы».'))
                    ->schema([
                        Placeholder::make('problem_tags_empty')
                            ->label(__('Категории пока не настроены'))
                            ->content(__('Добавьте первую категорию кнопкой «Добавить категорию», чтобы использовать её в профиле и медицинском сеансе.'))
                            ->visible(fn (Get $get): bool => ! is_array($get('problem_tags')) || $get('problem_tags') === [])
                            ->columnSpanFull(),
                        Repeater::make('problem_tags')
                            ->schema([
                                TextInput::make('name')->label(__('Название категории'))->required()->maxLength(255),
                                TextInput::make('group_name')->label(__('Группа'))->maxLength(255),
                                Toggle::make('is_active')->label(__('Активна'))->default(true),
                                TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                            ])->columns(2)->defaultItems(0)->reorderable()->addActionLabel(__('Добавить категорию'))->columnSpanFull(),
                    ])->columnSpanFull(),
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
            ->id('medical-clinical-catalog-form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')->label(__('Сохранить каталог'))->submit('save'),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label(__('Импортировать CSV/XLSX'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->authorize(fn (): bool => self::canAccess())
                ->form([
                    Select::make('section')
                        ->label(__('Раздел каталога'))
                        ->options([
                            'tests' => __('Тесты и значения'),
                            'causes' => __('Причины нарушений'),
                            'problem_tags' => __('Категории проблем'),
                        ])
                        ->required(),
                    FileUpload::make('file')
                        ->label(__('CSV/XLSX-файл'))
                        ->helperText(__('Тесты: «Название теста», «Раздел», «Значение», «Нарушение», «Активен», «Порядок теста», «Порядок значения». Причины и категории: «Название», «Группа», «Активна», «Порядок». До 2 МБ.'))
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(2048)
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $actor = Auth::user();
                    $file = $data['file'] ?? null;
                    if (! $actor instanceof User || ! $file instanceof UploadedFile) {
                        abort(422);
                    }

                    try {
                        $rows = app(ImportMedicalClinicalCatalogCsv::class)->handle($actor, $file, (string) ($data['section'] ?? ''));
                        $this->mount();
                        Notification::make()->success()->title(__('Файл каталога импортирован'))->body(__('Импортировано строк: :count', ['count' => $rows]))->send();
                    } catch (ValidationException $exception) {
                        $message = collect($exception->errors())->flatten()->first();
                        Notification::make()->danger()->title(is_string($message) ? $message : __('Проверьте файл каталога.'))->send();
                    } catch (Throwable) {
                        Notification::make()->danger()->title(__('Файл каталога не удалось импортировать.'))->send();
                    }
                }),
        ];
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $state = $this->form->getState();
        app(SaveMedicalClinicalCatalog::class)->handle(
            $actor,
            is_array($state['tests'] ?? null) ? $state['tests'] : [],
            is_array($state['causes'] ?? null) ? $state['causes'] : [],
            is_array($state['problem_tags'] ?? null) ? $state['problem_tags'] : [],
        );
        Notification::make()->success()->title(__('Каталог сохранён'))->send();
    }
}
