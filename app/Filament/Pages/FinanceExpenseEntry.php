<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\FinanceMovementConfiguration;
use App\Modules\Finance\Application\RecordExpense;
use App\Modules\Finance\Domain\Models\FinanceAccount;
use App\Modules\Finance\Domain\Models\FinanceExpenseCategory;
use App\Modules\Organizations\Application\OrganizationContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Validation\ValidationException;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class FinanceExpenseEntry extends LocalizedPage
{
    protected static ?string $title = 'Добавить расход';

    protected static ?string $navigationLabel = 'Добавить расход';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.finance-expense-entry';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public ?string $idempotencyKey = null;

    public static function canAccess(): bool
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }

        try {
            return app(FinanceAuthorization::class)->allowsManage($actor);
        } catch (LogicException) {
            return false;
        }
    }

    public function mount(): void
    {
        $timezone = app(OrganizationContext::class)->defaultTimezone();
        $this->idempotencyKey = 'crm-expense:'.Str::uuid()->toString();
        $this->form->fill([
            'occurred_at' => CarbonImmutable::now($timezone)->format('Y-m-d H:i'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $configuration = app(FinanceMovementConfiguration::class);

        return $schema->components([
            Section::make(__('Новый расход'))
                ->description(__('Расход попадёт в общий финансовый ledger и будет доступен в аналитике после сохранения.'))
                ->schema([
                    Select::make('account_id')
                        ->label(__('Счёт или касса'))
                        ->options($configuration->activeAccountOptions())
                        ->searchable()
                        ->required(),
                    Select::make('category_id')
                        ->label(__('Категория'))
                        ->options($configuration->activeCategoryOptions())
                        ->searchable()
                        ->required(),
                    TextInput::make('amount')
                        ->label(__('Сумма'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required(),
                    DateTimePicker::make('occurred_at')
                        ->label(__('Дата и время'))
                        ->timezone(app(OrganizationContext::class)->defaultTimezone())
                        ->seconds(false)
                        ->required(),
                    Textarea::make('note')
                        ->label(__('Примечание'))
                        ->maxLength(2000)
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'sm' => 2])
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
            ->id('finance-expense-entry-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label(__('Записать расход'))->submit('save')])]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $state = $this->form->getState();
        $organization = app(OrganizationContext::class)->organization();
        try {
            $account = FinanceAccount::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey((int) ($state['account_id'] ?? 0))
                ->firstOrFail();
            $category = FinanceExpenseCategory::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey((int) ($state['category_id'] ?? 0))
                ->firstOrFail();
            app(RecordExpense::class)->handle(
                actor: $actor,
                account: $account,
                category: $category,
                amount: is_string($state['amount'] ?? null) || is_int($state['amount'] ?? null) || is_float($state['amount'] ?? null)
                    ? (string) $state['amount']
                    : '',
                occurredAt: CarbonImmutable::parse((string) ($state['occurred_at'] ?? ''), $organization->defaultTimezone()),
                note: is_string($state['note'] ?? null) ? $state['note'] : null,
                idempotencyKey: $this->idempotencyKey ??= 'crm-expense:'.Str::uuid()->toString(),
            );
            Notification::make()->success()->title(__('Расход записан'))->send();
            $this->idempotencyKey = 'crm-expense:'.Str::uuid()->toString();
            $this->form->fill(['occurred_at' => CarbonImmutable::now($organization->defaultTimezone())->format('Y-m-d H:i')]);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            Notification::make()->danger()->title(is_string($message) ? $message : __('Проверьте заполнение полей.'))->send();
        }
    }
}
