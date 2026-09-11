<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Modules\Identity\Application\InitiateTelegramOrganizationLink;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;

final class EditProfile extends BaseEditProfile
{
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['notifications_enabled'] = $this->membership()->notifications_enabled;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->membership()->forceFill([
            'notifications_enabled' => (bool) ($data['notifications_enabled'] ?? true),
        ])->save();
        unset($data['notifications_enabled']);

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                Section::make('Уведомления сотрудника')
                    ->schema([
                        Placeholder::make('telegram_status')
                            ->label('Telegram')
                            ->content(fn (): string => $this->telegramConnectionStatus()),
                        Toggle::make('notifications_enabled')
                            ->label('Уведомления CRM')
                            ->required()
                            ->helperText('Разрешить автоматические уведомления для этого сотрудника.'),
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            $this->telegramConnectionAction(),
        ];
    }

    protected function telegramConnectionAction(): Action
    {
        return Action::make('telegramConnection')
            ->label(fn (): string => $this->hasVerifiedTelegram() ? 'Перепривязать Telegram' : 'Подключить Telegram')
            ->icon('heroicon-o-link')
            ->authorize(fn (): bool => $this->canConnectTelegram())
            ->visible(fn (): bool => $this->canConnectTelegram())
            ->modalHeading(fn (): string => $this->hasVerifiedTelegram()
                ? 'Перепривязать Telegram'
                : 'Подключить Telegram')
            ->modalDescription('Скопируйте одноразовую ссылку и откройте её в Telegram. После подтверждения аккаунт будет использоваться для уведомлений CRM.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->schema([
                TextInput::make('link')
                    ->label('Ссылка для Telegram')
                    ->readOnly()
                    ->copyable(copyMessage: 'Ссылка скопирована')
                    ->required()
                    ->columnSpanFull(),
            ])
            ->fillForm(fn (): array => ['link' => $this->generateTelegramLink()]);
    }

    private function generateTelegramLink(): string
    {
        $user = $this->getUser();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'link' => 'Не удалось определить сотрудника CRM.',
            ]);
        }

        try {
            return app(InitiateTelegramOrganizationLink::class)->handle(
                actor: $user,
                userId: (int) $user->getKey(),
            );
        } catch (AuthorizationException|LogicException) {
            throw ValidationException::withMessages([
                'link' => 'Ссылку пока не удалось создать. Проверьте подключение Telegram-бота.',
            ]);
        }
    }

    private function telegramConnectionStatus(): string
    {
        $user = $this->getUser();

        if (! $user instanceof User) {
            return 'Не подключён';
        }

        return OrganizationChannelIdentity::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('user_id', $user->getKey())
            ->where('channel', 'telegram')
            ->where('verification_status', ChannelIdentityStatus::Verified->value)
            ->exists()
            ? 'Подключён'
            : 'Не подключён';
    }

    private function hasVerifiedTelegram(): bool
    {
        return $this->telegramConnectionStatus() === 'Подключён';
    }

    private function canConnectTelegram(): bool
    {
        $user = $this->getUser();

        if (! $user instanceof User) {
            return false;
        }

        try {
            return app(OrganizationAuthorizer::class)->allows(
                $user,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ViewScenarios,
            );
        } catch (LogicException) {
            return false;
        }
    }

    private function membership(): OrganizationMembership
    {
        $user = $this->getUser();
        if (! $user instanceof User) {
            abort(403);
        }

        $membership = $user->membershipFor(app(OrganizationContext::class)->id());
        abort_unless($membership instanceof OrganizationMembership, 403);

        return $membership;
    }
}
