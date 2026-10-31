<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\FinanceMovementConfiguration;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class FinanceMovementSettings extends LocalizedPage
{
    protected static ?string $title = 'Счета и расходы';

    protected static ?string $navigationLabel = 'Счета и расходы';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.finance-movement-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public static function canAccess(): bool
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }
        try {
            return app(FinanceAuthorization::class)->allowsView($actor);
        } catch (LogicException) {
            return false;
        }
    }

    public function mount(): void
    {
        $this->form->fill(app(FinanceMovementConfiguration::class)->values());
    }

    public function form(Schema $schema): Schema
    {
        $configuration = app(FinanceMovementConfiguration::class);

        return $schema->components([
            Section::make(__('Счета и кассы'))
                ->description(__('Названия и активность можно менять без изменения истории операций.'))
                ->schema([
                    Repeater::make('accounts')
                        ->label(__('Счета и кассы'))
                        ->schema([
                            Hidden::make('id')->dehydrated(true),
                            TextInput::make('name')->label(__('Название'))->required()->maxLength(160),
                            Select::make('account_type')->label(__('Тип'))->options($configuration->accountTypeOptions())->required(),
                            Select::make('currency')->label(__('Валюта'))->options($configuration->currencyOptions())->required(),
                            TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0)->required(),
                            Toggle::make('is_active')->label(__('Использовать для новых операций'))->default(true),
                        ])
                        ->columns(['default' => 1, 'sm' => 2])
                        ->defaultItems(0)
                        ->addActionLabel(__('Добавить счёт или кассу'))
                        ->reorderable(false),
                ])
                ->columnSpanFull(),
            Section::make(__('Категории расходов'))
                ->schema([
                    Repeater::make('categories')
                        ->label(__('Категории расходов'))
                        ->schema([
                            Hidden::make('id')->dehydrated(true),
                            TextInput::make('name')->label(__('Название'))->required()->maxLength(160),
                            TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0)->required(),
                            Toggle::make('is_active')->label(__('Использовать для новых операций'))->default(true),
                        ])
                        ->columns(['default' => 1, 'sm' => 3])
                        ->defaultItems(0)
                        ->addActionLabel(__('Добавить категорию'))
                        ->reorderable(false),
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
            ->id('finance-movement-settings-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label(__('Сохранить справочники'))->submit('save')])]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        try {
            $state = $this->form->getState();
            app(FinanceMovementConfiguration::class)->save(
                actor: $actor,
                accounts: is_array($state['accounts'] ?? null) ? $state['accounts'] : [],
                categories: is_array($state['categories'] ?? null) ? $state['categories'] : [],
            );
            Notification::make()->success()->title(__('Справочники счетов и расходов сохранены'))->send();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            Notification::make()->danger()->title(is_string($message) ? $message : __('Проверьте заполнение полей.'))->send();
        }
    }
}
