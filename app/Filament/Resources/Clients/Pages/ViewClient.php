<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Models\User;
use App\Modules\Attribution\Application\ManageAttributionSourceDetail;
use App\Modules\Identity\Application\BlockClientSelfBooking;
use App\Modules\Identity\Application\GetLatestClientMarketingConsent;
use App\Modules\Identity\Application\RecordClientConsent;
use App\Modules\Identity\Application\UnblockClientSelfBooking;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Application\EstablishManualReferralRelationship;
use App\Modules\Referrals\Application\SearchClientsForReferralAssignment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function getRecordTitle(): string
    {
        return ClientResource::getRecordTitle($this->getRecord());
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRecordTitle();
    }

    public function getBreadcrumb(): string
    {
        return $this->getRecordTitle();
    }

    /** @return array<string> */
    public function getBreadcrumbs(): array
    {
        return [
            ...$this->getResourceBreadcrumbs(),
            $this->getBreadcrumb(),
        ];
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Клинический профиль';
    }

    public function infolist(Schema $schema): Schema
    {
        return ClientResource::infolist($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Редактировать клиента')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
            Action::make('editMedicalProfile')
                ->label('Изменить медицинский профиль')
                ->icon('heroicon-o-heart')
                ->fillForm(function (): array {
                    $actor = auth()->user();
                    $client = $this->clientRecord();

                    if (! $actor instanceof User) {
                        return [];
                    }

                    $profile = app(GetMedicalProfile::class)->handle($actor, $client);

                    return [
                        'anamnesis' => $profile?->anamnesis,
                        'complaints_goals' => $profile?->complaintsGoals,
                        'operations_injuries' => $profile?->operationsInjuries,
                        'medicines' => $profile?->medicines,
                        'supplements' => $profile?->supplements,
                    ];
                })
                ->schema(self::medicalProfileSchema())
                ->visible(fn (): bool => ClientResource::canEdit($this->clientRecord()))
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $client = $this->clientRecord();

                    abort_unless($actor instanceof User, 403);

                    app(UpdateMedicalProfile::class)->handle(
                        $actor,
                        $client,
                        UpdateMedicalProfileCommand::fromArray($data),
                    );
                }),
            ActionGroup::make([
                $this->marketingConsentActionGroup(),
                $this->assignReferrerAction(),
                Action::make('sourceDetail')
                    ->label('Уточнение источника')
                    ->modalHeading('Уточнение источника')
                    ->modalSubmitActionLabel('Сохранить')
                    ->authorize(fn (): bool => ClientResource::canEdit($this->clientRecord()))
                    ->visible(fn (): bool => ClientResource::canEdit($this->clientRecord()))
                    ->fillForm(fn (): array => ['source_detail' => app(ManageAttributionSourceDetail::class)->read($this->actor(), $this->clientRecord())])
                    ->schema([
                        Textarea::make('source_detail')
                            ->label('Кто порекомендовал или откуда узнали')
                            ->helperText('Имя, Telegram, телефон или другое уточнение.')
                            ->maxLength(500)
                            ->rows(3),
                    ])
                    ->action(function (array $data): void {
                        app(ManageAttributionSourceDetail::class)->update($this->actor(), $this->clientRecord(), $data['source_detail'] ?? null);
                    }),
                Action::make('companionHistory')
                    ->label('AI-компаньон / История общения')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->url(fn (): string => ClientResource::getUrl('companion', ['record' => $this->clientRecord()])),
                Action::make('newSession')
                    ->label('Новый сеанс')
                    ->icon('heroicon-o-plus')
                    ->url(fn (): string => MedicalSessionResource::getUrl('create', shouldGuessMissingParameters: true))
                    ->visible(fn (): bool => MedicalSessionResource::canCreate()),
                ActionGroup::make([
                    $this->blockSelfBookingAction(),
                    $this->unblockSelfBookingAction(),
                ])
                    ->label('Доступ к записи')
                    ->icon('heroicon-o-lock-closed'),
            ])
                ->label('Дополнительные действия')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button()
                ->color('gray'),
        ];
    }

    private function marketingConsentActionGroup(): ActionGroup
    {
        $actions = $this->marketingConsentIsGranted()
            ? [$this->revokeMarketingConsentAction(), $this->grantMarketingConsentAction()]
            : [$this->grantMarketingConsentAction(), $this->revokeMarketingConsentAction()];

        return ActionGroup::make($actions)
            ->label('Маркетинговые рассылки')
            ->icon('heroicon-o-megaphone')
            ->button()
            ->color('gray')
            ->visible(fn (): bool => $this->canRecordMarketingConsent());
    }

    private function grantMarketingConsentAction(): Action
    {
        return Action::make('grantMarketingConsent')
            ->label('Зафиксировать согласие на рассылки')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading('Зафиксировать согласие на рассылки')
            ->modalDescription('Укажите, как клиент подтвердил согласие, и подтвердите факт получения согласия.')
            ->modalSubmitActionLabel('Зафиксировать согласие')
            ->requiresConfirmation()
            ->fillForm(fn (): array => [
                'version' => $this->latestMarketingConsent()?->version,
            ])
            ->schema($this->marketingConsentSchema(true))
            ->authorize(fn (): bool => $this->canRecordMarketingConsent())
            ->visible(fn (): bool => $this->canRecordMarketingConsent())
            ->action(function (array $data): void {
                $this->recordMarketingConsent(true, $data);
            });
    }

    private function revokeMarketingConsentAction(): Action
    {
        return Action::make('revokeMarketingConsent')
            ->label('Отозвать согласие на рассылки')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Отозвать согласие на рассылки')
            ->modalDescription('Укажите, как клиент сообщил об отзыве, и подтвердите факт получения отказа.')
            ->modalSubmitActionLabel('Отозвать согласие')
            ->requiresConfirmation()
            ->fillForm(fn (): array => [
                'version' => $this->latestMarketingConsent()?->version,
            ])
            ->schema($this->marketingConsentSchema(false))
            ->authorize(fn (): bool => $this->canRecordMarketingConsent())
            ->visible(fn (): bool => $this->canRecordMarketingConsent())
            ->action(function (array $data): void {
                $this->recordMarketingConsent(false, $data);
            });
    }

    /** @return array<Checkbox|Select|TextInput> */
    private function marketingConsentSchema(bool $granted): array
    {
        return [
            TextInput::make('version')
                ->label('Версия согласия')
                ->required()
                ->maxLength(64)
                ->helperText('Укажите версию текста или условия, которые подтвердил клиент.'),
            Select::make('evidence')
                ->label('Источник подтверждения')
                ->options([
                    'crm' => 'Зафиксировано оператором в CRM',
                    'telegram' => 'Сообщение клиента в Telegram',
                    'phone' => 'Телефонный разговор',
                    'written' => 'Письменное согласие',
                ])
                ->native(false)
                ->required(),
            Checkbox::make('confirmed')
                ->label($granted
                    ? 'Подтверждаю, что клиент действительно согласился на маркетинговые рассылки.'
                    : 'Подтверждаю, что клиент действительно отозвал согласие на маркетинговые рассылки.')
                ->accepted(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function recordMarketingConsent(bool $granted, array $data): void
    {
        if (! in_array($data['confirmed'] ?? null, [true, 1, '1', 'on', 'yes'], true)) {
            throw ValidationException::withMessages([
                'confirmed' => 'Подтвердите, что сообщение клиента действительно получено.',
            ]);
        }

        $consent = app(RecordClientConsent::class)->handle(
            actor: $this->actor(),
            client: $this->clientRecord(),
            subject: ConsentSubject::Marketing,
            version: (string) ($data['version'] ?? ''),
            granted: $granted,
            evidence: (string) ($data['evidence'] ?? ''),
        );

        $this->getRecord()->refresh();

        Notification::make()
            ->title($granted ? 'Согласие на рассылки зафиксировано' : 'Согласие на рассылки отозвано')
            ->body('Версия: '.$consent->version)
            ->success()
            ->send();
    }

    private function canRecordMarketingConsent(): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        $client = $this->clientRecord();
        $organization = app(OrganizationContext::class)->organization();

        return (int) $client->organization_id === (int) $organization->getKey()
            && app(OrganizationAuthorizer::class)->allows(
                $actor,
                $organization,
                OrganizationPermission::RecordConsent,
            );
    }

    private function marketingConsentIsGranted(): bool
    {
        return $this->latestMarketingConsent()?->granted === true;
    }

    private function latestMarketingConsent(): ?ClientConsent
    {
        return app(GetLatestClientMarketingConsent::class)->handle($this->actor(), $this->clientRecord());
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function blockSelfBookingAction(): Action
    {
        return Action::make('blockSelfBooking')
            ->label('Запретить самостоятельную запись')
            ->color('danger')
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')
                    ->label('Причина ограничения')
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (): bool => $this->clientRecord()->activeBookingRestriction === null
                && ClientResource::canEdit($this->clientRecord()))
            ->action(function (array $data): void {
                $actor = auth()->user();
                $client = $this->clientRecord();

                abort_unless($actor instanceof User, 403);

                app(BlockClientSelfBooking::class)->handle($actor, $client, (string) $data['reason']);
                $client->load('activeBookingRestriction');
            });
    }

    private function assignReferrerAction(): Action
    {
        return Action::make('assignReferrer')
            ->label('Назначить реферера')
            ->icon('heroicon-o-user-plus')
            ->schema([
                Select::make('referrer_client_id')
                    ->label('Реферер')
                    ->searchable()
                    ->native(false)
                    ->options([])
                    ->optionsLimit(50)
                    ->getSearchResultsUsing(function (string $search): array {
                        $actor = auth()->user();
                        $client = $this->clientRecord();

                        return $actor instanceof User
                            ? app(SearchClientsForReferralAssignment::class)->handle($actor, $search, (int) $client->getKey())
                            : [];
                    })
                    ->getOptionLabelUsing(function (mixed $value): ?string {
                        $actor = auth()->user();

                        return $actor instanceof User
                            ? app(SearchClientsForReferralAssignment::class)->optionLabel($actor, $value)
                            : null;
                    })
                    ->required(),
            ])
            ->visible(function (): bool {
                $actor = auth()->user();

                return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
                    $actor,
                    app(OrganizationContext::class)->organization(),
                    OrganizationPermission::ManageClients,
                );
            })
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                app(EstablishManualReferralRelationship::class)->handle(
                    actor: $actor,
                    referrerClientId: (int) $data['referrer_client_id'],
                    referredClientId: (int) $this->clientRecord()->getKey(),
                );
                Notification::make()->title('Реферер назначен')->success()->send();
            });
    }

    private function unblockSelfBookingAction(): Action
    {
        return Action::make('unblockSelfBooking')
            ->label('Разрешить самостоятельную запись')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->clientRecord()->activeBookingRestriction !== null
                && ClientResource::canEdit($this->clientRecord()))
            ->action(function (): void {
                $actor = auth()->user();
                $client = $this->clientRecord();

                abort_unless($actor instanceof User, 403);

                app(UnblockClientSelfBooking::class)->handle($actor, $client);
                $client->load('activeBookingRestriction');
            });
    }

    /** @return array<Textarea> */
    private static function medicalProfileSchema(): array
    {
        return [
            Textarea::make('anamnesis')
                ->label('Анамнез')
                ->rows(3)
                ->maxLength(10000),
            Textarea::make('complaints_goals')
                ->label('Жалобы и цели')
                ->rows(3)
                ->maxLength(10000),
            Textarea::make('operations_injuries')
                ->label('Операции и травмы')
                ->rows(3)
                ->maxLength(10000),
            Textarea::make('medicines')
                ->label('Лекарственные препараты')
                ->rows(3)
                ->maxLength(10000),
            Textarea::make('supplements')
                ->label('Биологически активные добавки')
                ->rows(3)
                ->maxLength(10000),
        ];
    }

    private function clientRecord(): Client
    {
        $record = $this->getRecord();

        abort_unless($record instanceof Client, 404);

        return $record;
    }
}
