<?php

namespace App\Filament\Resources\Specialists\Pages;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Models\User;
use App\Modules\Identity\Application\InitiateTelegramOrganizationLink;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;

class ViewSpecialist extends ViewRecord
{
    protected static string $resource = SpecialistResource::class;

    protected static ?string $title = 'Специалист';

    protected function getHeaderActions(): array
    {
        return [
            $this->createTelegramLinkAction(),
            EditAction::make()
                ->label('Редактировать специалиста')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }

    private function createTelegramLinkAction(): Action
    {
        return Action::make('createTelegramLink')
            ->label('Создать ссылку Telegram')
            ->icon('heroicon-o-link')
            ->color('primary')
            ->authorize(fn (): bool => $this->canCreateTelegramLink())
            ->visible(fn (): bool => $this->canCreateTelegramLink() && ! $this->hasVerifiedTelegram())
            ->modalHeading('Привязать Telegram сотрудника')
            ->modalDescription('Отправьте сотруднику ссылку ниже. Она одноразовая и действует ограниченное время. Уже привязанный другой Telegram-аккаунт автоматически заменён не будет.')
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
            ->fillForm(fn (): array => ['link' => $this->generateTelegramLink()]);
    }

    private function generateTelegramLink(): string
    {
        $actor = auth()->user();
        $specialist = $this->specialist();

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

    private function canCreateTelegramLink(): bool
    {
        $actor = auth()->user();
        $specialist = $this->specialist();

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

    private function hasVerifiedTelegram(): bool
    {
        $specialist = $this->specialist();

        return OrganizationChannelIdentity::query()
            ->where('organization_id', $specialist->organization_id)
            ->where('user_id', $specialist->staff_user_id)
            ->where('channel', 'telegram')
            ->where('verification_status', ChannelIdentityStatus::Verified->value)
            ->exists();
    }

    private function specialist(): Specialist
    {
        abort_unless($this->record instanceof Specialist, 404);

        return $this->record;
    }
}
