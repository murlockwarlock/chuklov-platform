<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Analytics\Application\ClientSegmentConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use Filament\Actions\Action;
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
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class ClientSegmentSettings extends LocalizedPage
{
    protected static ?string $title = 'Категории клиентов';

    protected static ?string $navigationLabel = 'Категории клиентов';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.client-segment-settings';

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
        $this->form->fill(app(ClientSegmentConfiguration::class)->values());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Правила категорий'))
                ->description(__('Пороговые значения меняются здесь и не требуют публикации новой версии программы.'))
                ->schema([
                    TextInput::make('new_days')->label(__('Считать клиента новым, дней'))->integer()->minValue(1)->maxValue(3650)->required(),
                    TextInput::make('regular_completed_visits')->label(__('Визитов для категории «Постоянные»'))->integer()->minValue(1)->maxValue(3650)->required(),
                    TextInput::make('dormant_days')->label(__('Период без визита для «Неактивные», дней'))->integer()->minValue(1)->maxValue(3650)->required(),
                    TextInput::make('sleeping_days')->label(__('Период для «Спящие», дней'))->integer()->minValue(1)->maxValue(3650)->required(),
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
            ->id('client-segment-settings-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label(__('Сохранить настройки'))->submit('save')])]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();
        $setter = app(SetOrganizationSetting::class);
        $setter->handle($actor, OrganizationSettingKey::ClientSegmentNewDays, (int) $data['new_days']);
        $setter->handle($actor, OrganizationSettingKey::ClientSegmentRegularCompletedVisits, (int) $data['regular_completed_visits']);
        $setter->handle($actor, OrganizationSettingKey::ClientSegmentDormantDays, (int) $data['dormant_days']);
        $setter->handle($actor, OrganizationSettingKey::ClientSegmentSleepingDays, (int) $data['sleeping_days']);
        Notification::make()->success()->title(__('Настройки категорий клиентов сохранены'))->send();
    }
}
