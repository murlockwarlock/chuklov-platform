<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Application\SaveMedicalClinicalCatalog;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
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
final class MedicalClinicalCatalogConfiguration extends LocalizedPage
{
    protected static ?string $title = 'Тесты и причины нарушений';

    protected static ?string $navigationLabel = 'Тесты и причины нарушений';

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
            ->with('values')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalTestDefinition $test): array => [
                'key' => $test->key,
                'name' => $test->name,
                'section' => $test->section,
                'is_active' => $test->is_active,
                'sort_order' => $test->sort_order,
                'values' => $test->values->map(static fn ($value): array => [
                    'key' => $value->key,
                    'label' => $value->label,
                    'is_abnormal' => $value->is_abnormal,
                    'is_active' => $value->is_active,
                    'sort_order' => $value->sort_order,
                ])->all(),
            ])->all();
        $causes = MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
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
                    ->description(__('Создавайте и меняйте каталог тестов и значения до/после без изменения программы.'))
                    ->schema([
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
                    ->description(__('Справочник причин используется для ручного выбора по каждому abnormal-тесту.'))
                    ->schema([
                        Repeater::make('causes')
                            ->schema([
                                TextInput::make('name')->label(__('Название причины'))->required()->maxLength(255),
                                TextInput::make('group_name')->label(__('Группа'))->maxLength(255),
                                Toggle::make('is_active')->label(__('Активна'))->default(true),
                                TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                            ])->columns(2)->defaultItems(0)->reorderable()->addActionLabel(__('Добавить причину'))->columnSpanFull(),
                    ])->columnSpanFull(),
                Section::make(__('Категории проблем'))
                    ->description(__('Нормализованные категории используются для статистики боли и не заменяют защищённый текст жалобы.'))
                    ->schema([
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
