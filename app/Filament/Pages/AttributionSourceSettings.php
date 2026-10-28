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
                    ->description(__('Названия и синонимы используются в отчёте по привлечению. Изменение справочника не требует выпуска новой версии программы.'))
                    ->schema([
                        Repeater::make('sources')
                            ->label(__('Источники'))
                            ->schema([
                                Hidden::make('source_key'),
                                TextInput::make('label')->label(__('Название'))->required()->maxLength(160),
                                Repeater::make('aliases')
                                    ->label(__('Синонимы и ключи из рекламы'))
                                    ->schema([
                                        TextInput::make('value')->label(__('Значение'))->required()->maxLength(120),
                                    ])
                                    ->defaultItems(0)
                                    ->addActionLabel(__('Добавить синоним'))
                                    ->columnSpanFull(),
                                Toggle::make('is_active')->label(__('Использовать в аналитике'))->default(true),
                                TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                            ])
                            ->columns(['default' => 1, 'sm' => 2])
                            ->defaultItems(0)
                            ->reorderable()
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
        app(AttributionSourceCatalog::class)->save(
            $actor,
            is_array($state['sources'] ?? null) ? $state['sources'] : [],
        );
        Notification::make()->success()->title(__('Источники сохранены'))->send();
    }
}
