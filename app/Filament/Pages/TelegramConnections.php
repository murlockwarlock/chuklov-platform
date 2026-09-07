<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Modules\Identity\Application\InitiateTelegramOrganizationLink;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
final class TelegramConnections extends Page
{
    protected static ?string $title = 'Telegram сотрудников';

    protected static ?string $navigationLabel = 'Telegram сотрудников';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Коммуникации';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.telegram-connections';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public ?string $telegramLink = null;

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
                OrganizationPermission::ViewScenarios,
            );
        } catch (LogicException) {
            return false;
        }
    }

    public function mount(): void
    {
        $actor = Auth::user();
        $membership = $actor instanceof User ? $actor->membershipFor(app(OrganizationContext::class)->organization()) : null;
        $this->form->fill([
            'user_id' => $actor?->getKey(),
            'notifications_enabled' => $membership?->notifications_enabled ?? true,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Подключение сотрудника')
                    ->description('Сотрудник привязывает свой Telegram через подтверждение в боте. Ввод Telegram ID вручную недоступен.')
                    ->schema([
                        Select::make('user_id')
                            ->label('Сотрудник')
                            ->options(fn (): array => $this->staffOptions())
                            ->live()
                            ->required(),
                        Toggle::make('notifications_enabled')
                            ->label('Получать внутренние уведомления')
                            ->helperText('Настройка применяется к CRM и Telegram уведомлениям этого сотрудника.')
                            ->required(),
                    ])
                    ->columns(2),
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
            ->id('telegram-connections-form')
            ->livewireSubmitHandler('savePreferences')
            ->footer([
                Actions::make([
                    Action::make('savePreferences')
                        ->label('Сохранить настройки')
                        ->submit('savePreferences'),
                    Action::make('createLink')
                        ->label('Создать ссылку подключения')
                        ->color('primary')
                        ->action('createLink'),
                ]),
            ]);
    }

    public function savePreferences(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();
        $userId = (int) ($data['user_id'] ?? 0);
        $this->authorizeTarget($actor, $userId);
        OrganizationMembership::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('user_id', $userId)
            ->active()
            ->update(['notifications_enabled' => (bool) ($data['notifications_enabled'] ?? false)]);

        Notification::make()->success()->title('Настройки уведомлений сохранены')->send();
    }

    public function createLink(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();
        $this->telegramLink = app(InitiateTelegramOrganizationLink::class)->handle($actor, (int) ($data['user_id'] ?? 0));
        Notification::make()->success()->title('Ссылка подключения создана')->body('Откройте её сотруднику. Ссылка действует ограниченное время.')->send();
    }

    /** @return array<int, string> */
    public function connections(): array
    {
        $organizationId = app(OrganizationContext::class)->id();
        $identities = OrganizationChannelIdentity::query()
            ->where('organization_id', $organizationId)
            ->where('channel', 'telegram')
            ->get()
            ->keyBy('user_id');

        return OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->active()
            ->with('user')
            ->orderBy('user_id')
            ->get()
            ->mapWithKeys(function (OrganizationMembership $membership) use ($identities): array {
                $identity = $identities->get($membership->user_id);
                $status = $identity?->verification_status === ChannelIdentityStatus::Verified
                    ? 'подключён'
                    : 'не подключён';

                return [$membership->user_id => ($membership->user?->name ?: $membership->user?->email).' — '.$status];
            })
            ->all();
    }

    /** @return array<int, string> */
    private function staffOptions(): array
    {
        $actor = Auth::user();
        $organizationId = app(OrganizationContext::class)->id();
        $canManage = $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageSettings,
        );

        return OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->active()
            ->when(! $canManage, fn ($query) => $query->where('user_id', $actor?->getKey()))
            ->with('user')
            ->orderBy('user_id')
            ->get()
            ->mapWithKeys(fn (OrganizationMembership $membership): array => [
                $membership->user_id => ($membership->user?->name ?: $membership->user?->email),
            ])
            ->all();
    }

    private function authorizeTarget(User $actor, int $userId): void
    {
        $organization = app(OrganizationContext::class)->organization();
        app(OrganizationAuthorizer::class)->authorize($actor, $organization, OrganizationPermission::ViewScenarios);
        if ($actor->getKey() !== $userId) {
            app(OrganizationAuthorizer::class)->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        }
    }
}
