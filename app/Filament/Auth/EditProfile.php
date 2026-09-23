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
use App\Support\SupportedLocale;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
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
        $data['crm_locale'] = app()->getLocale();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $locale = SupportedLocale::normalize(is_string($data['crm_locale'] ?? null) ? $data['crm_locale'] : null);
        session()->put(SupportedLocale::AdminSessionKey, $locale);
        app()->setLocale($locale);

        $this->membership()->forceFill([
            'notifications_enabled' => (bool) ($data['notifications_enabled'] ?? true),
        ])->save();
        unset($data['notifications_enabled']);
        unset($data['crm_locale']);

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
                Section::make(__('Настройки CRM'))
                    ->schema([
                        Select::make('crm_locale')
                            ->label(__('Язык CRM'))
                            ->options([
                                'ru' => __('Русский'),
                                'en' => __('English'),
                            ])
                            ->required()
                            ->native(false),
                        Placeholder::make('telegram_status')
                            ->label(__('Telegram'))
                            ->content(fn (): string => $this->telegramConnectionStatus()),
                        Toggle::make('notifications_enabled')
                            ->label(__('Уведомления CRM'))
                            ->required()
                            ->helperText(__('Разрешить автоматические уведомления для этого сотрудника.')),
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
            ->label(fn (): string => $this->hasVerifiedTelegram() ? __('Перепривязать Telegram') : __('Подключить Telegram'))
            ->icon('heroicon-o-link')
            ->authorize(fn (): bool => $this->canConnectTelegram())
            ->visible(fn (): bool => $this->canConnectTelegram())
            ->modalHeading(fn (): string => $this->hasVerifiedTelegram()
                ? __('Перепривязать Telegram')
                : __('Подключить Telegram'))
            ->modalDescription(__('Скопируйте одноразовую ссылку и откройте её в Telegram. После подтверждения аккаунт будет использоваться для уведомлений CRM.'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Закрыть'))
            ->schema([
                TextInput::make('link')
                    ->label(__('Ссылка для Telegram'))
                    ->readOnly()
                    ->copyable(copyMessage: __('Ссылка скопирована'))
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
                'link' => __('Не удалось определить сотрудника CRM.'),
            ]);
        }

        try {
            return app(InitiateTelegramOrganizationLink::class)->handle(
                actor: $user,
                userId: (int) $user->getKey(),
            );
        } catch (AuthorizationException|LogicException) {
            throw ValidationException::withMessages([
                'link' => __('Ссылку пока не удалось создать. Проверьте подключение Telegram-бота.'),
            ]);
        }
    }

    private function telegramConnectionStatus(): string
    {
        $user = $this->getUser();

        if (! $user instanceof User) {
            return __('Не подключён');
        }

        return OrganizationChannelIdentity::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('user_id', $user->getKey())
            ->where('channel', 'telegram')
            ->where('verification_status', ChannelIdentityStatus::Verified->value)
            ->exists()
            ? __('Подключён')
            : __('Не подключён');
    }

    private function hasVerifiedTelegram(): bool
    {
        return $this->telegramConnectionStatus() === __('Подключён');
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
