<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\FinanceMovementConfiguration;
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
                ->description(__('Эти варианты появляются в форме расхода и показывают, откуда списаны деньги. Если список пуст, сначала добавьте счёт или кассу здесь. Названия и активность можно менять без изменения истории операций.'))
                ->schema([
                    Placeholder::make('accounts_empty')
                        ->label(__('Счета и кассы пока не настроены'))
                        ->content(__('Добавьте первый счёт или кассу, чтобы при внесении расхода можно было выбрать источник списания.'))
                        ->visible(fn (Get $get): bool => ! is_array($get('accounts')) || $get('accounts') === [])
                        ->columnSpanFull(),
                    Repeater::make('accounts')
                        ->label(__('Счета и кассы'))
                        ->helperText(__('Пример: «Основной счёт» или «Касса клиники». Название увидят сотрудники при добавлении расхода.'))
                        ->schema([
                            Hidden::make('id')->dehydrated(true),
                            TextInput::make('name')->label(__('Название счёта или кассы'))->live(onBlur: true)->required()->maxLength(160),
                            Select::make('account_type')->label(__('Тип'))->options($configuration->accountTypeOptions())->required(),
                            Select::make('currency')->label(__('Валюта'))->options($configuration->currencyOptions())->required(),
                            Hidden::make('sort_order')->dehydrated(true),
                            Toggle::make('is_active')
                                ->label(__('Использовать для новых операций'))
                                ->helperText(__('Отключённый вариант не предлагается для новых операций, но старые проводки сохраняются.'))
                                ->default(true),
                        ])
                        ->columns(['default' => 1, 'sm' => 2])
                        ->defaultItems(0)
                        ->collapsible()
                        ->collapsed()
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->itemLabel(fn (array $state): ?string => self::accountItemLabel($state, $configuration))
                        ->addActionLabel(__('Добавить счёт или кассу')),
                ])
                ->columnSpanFull(),
            Section::make(__('Категории расходов'))
                ->description(__('Категории помогают группировать расходы в финансовом отчёте и появляются в форме добавления расхода. Если список пуст, сначала добавьте категорию здесь.'))
                ->schema([
                    Placeholder::make('categories_empty')
                        ->label(__('Категории пока не настроены'))
                        ->content(__('Добавьте первую категорию, чтобы расход можно было отнести к понятной статье отчёта.'))
                        ->visible(fn (Get $get): bool => ! is_array($get('categories')) || $get('categories') === [])
                        ->columnSpanFull(),
                    Repeater::make('categories')
                        ->label(__('Категории расходов'))
                        ->helperText(__('Пример: «Аренда», «Материалы» или «Реклама». Это название статьи отчёта, а не бухгалтерский код.'))
                        ->schema([
                            Hidden::make('id')->dehydrated(true),
                            TextInput::make('name')->label(__('Название категории'))->live(onBlur: true)->required()->maxLength(160),
                            Hidden::make('sort_order')->dehydrated(true),
                            Toggle::make('is_active')
                                ->label(__('Использовать для новых операций'))
                                ->helperText(__('Отключённая категория не появляется в новых расходах, но прежние операции остаются в отчёте.'))
                                ->default(true),
                        ])
                        ->columns(['default' => 1, 'sm' => 3])
                        ->defaultItems(0)
                        ->collapsible()
                        ->collapsed()
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->itemLabel(fn (array $state): ?string => self::categoryItemLabel($state))
                        ->addActionLabel(__('Добавить категорию')),
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
                accounts: self::withOrder(is_array($state['accounts'] ?? null) ? $state['accounts'] : []),
                categories: self::withOrder(is_array($state['categories'] ?? null) ? $state['categories'] : []),
            );
            Notification::make()->success()->title(__('Справочники счетов и расходов сохранены'))->send();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            Notification::make()->danger()->title(is_string($message) ? $message : __('Проверьте заполнение полей.'))->send();
        }
    }

    private static function accountItemLabel(array $state, FinanceMovementConfiguration $configuration): string
    {
        $name = trim((string) ($state['name'] ?? ''));
        $name = $name !== '' ? $name : __('Новый счёт или касса');
        $type = $configuration->accountTypeOptions()[(string) ($state['account_type'] ?? '')] ?? null;
        $currency = trim((string) ($state['currency'] ?? ''));
        $details = collect([$type, $currency !== '' ? $currency : null])->filter()->implode(' · ');

        return $name.($details !== '' ? ' · '.$details : '');
    }

    private static function categoryItemLabel(array $state): string
    {
        $name = trim((string) ($state['name'] ?? ''));

        return ($name !== '' ? $name : __('Новая категория')).' · '.(($state['is_active'] ?? true) ? __('Активна') : __('Отключена'));
    }

    private static function withOrder(array $rows): array
    {
        return array_values(array_map(static function (mixed $row, int $index): mixed {
            if (is_array($row)) {
                $row['sort_order'] = $index;
            }

            return $row;
        }, array_values($rows), array_keys(array_values($rows))));
    }
}
