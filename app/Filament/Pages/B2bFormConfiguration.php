<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\B2B\Application\PublishB2bFormConfiguration;
use App\Modules\B2B\Application\SaveB2bFormDraft;
use App\Modules\B2B\Domain\Enums\B2bFormConfigurationStatus;
use App\Modules\B2B\Domain\Models\B2bFormConfiguration as B2bFormConfigurationModel;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class B2bFormConfiguration extends LocalizedPage
{
    protected static ?string $title = 'Поля заявки для бизнеса';

    protected static ?string $navigationLabel = 'Поля заявки для бизнеса';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Коммуникации';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    protected string $view = 'filament.pages.b2b-form-configuration';

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
        $organizationId = app(OrganizationContext::class)->id();
        $configuration = B2bFormConfigurationModel::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', [B2bFormConfigurationStatus::Draft->value, B2bFormConfigurationStatus::Published->value])
            ->latest('version')
            ->first();
        $fields = $configuration === null ? [] : (array) $configuration->getAttribute('fields');
        $this->form->fill(['fields' => $fields]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Поля заявки'))
                    ->description(__('Добавьте поля, которые клиент заполнит перед выбором времени разговора. Ключи создаются автоматически и не показываются клиенту.'))
                    ->schema([
                        Repeater::make('fields')
                            ->label(__('Поля'))
                            ->schema([
                                Hidden::make('key')->default(fn (): string => 'field_'.Str::lower(Str::random(12))),
                                TextInput::make('label')->label(__('Название поля'))->required()->maxLength(160),
                                Select::make('type')->label(__('Тип ответа'))->options([
                                    'text' => __('Короткий текст'),
                                    'textarea' => __('Подробный текст'),
                                    'select' => __('Список вариантов'),
                                    'checkbox' => __('Подтверждение'),
                                ])->required()->default('text')->live(),
                                Repeater::make('options')
                                    ->label(__('Варианты ответа'))
                                    ->schema([
                                        Hidden::make('value')->default(fn (): string => 'option_'.Str::lower(Str::random(12))),
                                        TextInput::make('label')->label(__('Название варианта'))->required()->maxLength(160),
                                    ])
                                    ->columns(1)
                                    ->defaultItems(0)
                                    ->addActionLabel(__('Добавить вариант'))
                                    ->visible(fn (Get $get): bool => $get('type') === 'select'),
                                Toggle::make('required')->label(__('Обязательное поле'))->default(false),
                                Toggle::make('active')->label(__('Показывать клиенту'))->default(true),
                                TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                            ])
                            ->columns(['default' => 1, 'md' => 2, 'lg' => 3])
                            ->defaultItems(0)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['label'] ?? __('Новое поле')))
                            ->addActionLabel(__('Добавить поле'))
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
            ->id('b2b-form-configuration')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')->label(__('Сохранить черновик'))->submit('save'),
                    Action::make('publish')->label(__('Опубликовать для клиентов'))->requiresConfirmation()->action('publish'),
                ]),
            ]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $this->form->getState();
        app(SaveB2bFormDraft::class)->handle($actor, is_array($this->data['fields'] ?? null) ? $this->data['fields'] : []);
        Notification::make()->success()->title(__('Черновик полей сохранён'))->send();
    }

    public function publish(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $this->save();
        $draft = B2bFormConfigurationModel::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('status', B2bFormConfigurationStatus::Draft->value)
            ->latest('version')
            ->firstOrFail();
        app(PublishB2bFormConfiguration::class)->handle($actor, $draft);
        Notification::make()->success()->title(__('Поля заявки опубликованы'))->send();
    }
}
