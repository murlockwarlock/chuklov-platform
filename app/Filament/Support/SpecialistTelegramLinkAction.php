<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Modules\Identity\Application\InitiateTelegramOrganizationLink;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;

final class SpecialistTelegramLinkAction
{
    public static function make(): Action
    {
        return Action::make('createTelegramLink')
            ->label(fn (Specialist $record): string => self::hasVerifiedTelegram($record)
                ? 'Перепривязать Telegram'
                : 'Подключить Telegram')
            ->icon('heroicon-o-link')
            ->color('primary')
            ->authorize(fn (Specialist $record): bool => self::canCreateTelegramLink($record))
            ->visible(fn (Specialist $record): bool => self::canCreateTelegramLink($record))
            ->modalHeading(fn (Specialist $record): string => self::hasVerifiedTelegram($record)
                ? 'Перепривязать Telegram сотрудника'
                : 'Подключить Telegram сотрудника')
            ->modalDescription(fn (Specialist $record): string => self::hasVerifiedTelegram($record)
                ? 'Отправьте сотруднику новую одноразовую ссылку. После подтверждения новый Telegram заменит текущий аккаунт.'
                : 'Отправьте сотруднику одноразовую ссылку ниже. Она действует ограниченное время.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->schema([
                TextInput::make('link')
                    ->label('Ссылка для сотрудника')
                    ->readOnly()
                    ->copyable(copyMessage: 'Ссылка скопирована')
                    ->required()
                    ->columnSpanFull(),
            ])
            ->fillForm(fn (Specialist $record): array => ['link' => self::generateTelegramLink($record)]);
    }

    private static function generateTelegramLink(Specialist $specialist): string
    {
        $actor = auth()->user();

        if (! $actor instanceof User || $specialist->staff_user_id === null) {
            throw ValidationException::withMessages([
                'link' => 'Сначала привяжите к специалисту сотрудника CRM.',
            ]);
        }

        try {
            return app(InitiateTelegramOrganizationLink::class)->handle(
                actor: $actor,
                userId: (int) $specialist->staff_user_id,
            );
        } catch (AuthorizationException|LogicException) {
            throw ValidationException::withMessages([
                'link' => 'Ссылку пока не удалось создать. Проверьте настройки Telegram-бота.',
            ]);
        }
    }

    private static function canCreateTelegramLink(Specialist $specialist): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User || $specialist->staff_user_id === null) {
            return false;
        }

        try {
            $organization = app(OrganizationContext::class)->organization();
            $authorizer = app(OrganizationAuthorizer::class);

            if (! $authorizer->allows($actor, $organization, OrganizationPermission::ViewScenarios)) {
                return false;
            }

            return (int) $actor->getKey() === (int) $specialist->staff_user_id
                || $authorizer->allows($actor, $organization, OrganizationPermission::ManageSettings);
        } catch (LogicException) {
            return false;
        }
    }

    private static function hasVerifiedTelegram(Specialist $specialist): bool
    {
        if ($specialist->staff_user_id === null) {
            return false;
        }

        return OrganizationChannelIdentity::query()
            ->where('organization_id', $specialist->organization_id)
            ->where('user_id', $specialist->staff_user_id)
            ->where('channel', 'telegram')
            ->where('verification_status', ChannelIdentityStatus::Verified->value)
            ->exists();
    }
}
