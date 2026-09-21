<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Pages\Messages;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Filament\Resources\ReferralPartnerProfiles\ReferralPartnerProfileResource;
use App\Filament\Support\LocalizedViewRecord;
use App\Models\User;
use App\Modules\Attribution\Application\ManageAttributionSourceDetail;
use App\Modules\Identity\Application\BlockClientSelfBooking;
use App\Modules\Identity\Application\GetLatestClientMarketingConsent;
use App\Modules\Identity\Application\RecordClientConsent;
use App\Modules\Identity\Application\ResetStagingClientAccount;
use App\Modules\Identity\Application\UnblockClientSelfBooking;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Application\MedicalProfileSnapshotHasher;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\DeactivateReferralPartner;
use App\Modules\Referrals\Application\EstablishManualReferralRelationship;
use App\Modules\Referrals\Application\SearchActivePartnersForReferralAssignment;
use App\Modules\Tracker\Application\AssignTrackerTask;
use App\Modules\Tracker\Application\EndTrackerAccess;
use App\Modules\Tracker\Application\ExtendTrackerAccess;
use App\Modules\Tracker\Application\GrantTrackerAccess;
use App\Modules\Tracker\Application\ResolveTrackerAccess;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

class ViewClient extends LocalizedViewRecord
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
        return __('Клинический профиль');
    }

    public function infolist(Schema $schema): Schema
    {
        return ClientResource::infolist($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label(__('Редактировать клиента'))
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
            Action::make('companionHistory')
                ->label(__('Сообщения'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('primary')
                ->url(fn (): string => Messages::getUrl(['client' => $this->clientRecord()->getKey()])),
            $this->resetStagingAccountAction(),
            Action::make('editMedicalProfile')
                ->label(__('Изменить медицинский профиль'))
                ->icon('heroicon-o-heart')
                ->fillForm(function (): array {
                    $actor = auth()->user();
                    $client = $this->clientRecord();

                    if (! $actor instanceof User) {
                        return [];
                    }

                    $profile = app(GetMedicalProfile::class)->handle($actor, $client);
                    $profileRecord = MedicalProfile::query()
                        ->where('organization_id', $client->organization_id)
                        ->where('client_id', $client->getKey())
                        ->first();

                    return [
                        'anamnesis' => $profile?->anamnesis,
                        'complaints_goals' => $profile?->complaintsGoals,
                        'operations_injuries' => $profile?->operationsInjuries,
                        'medicines' => $profile?->medicines,
                        'supplements' => $profile?->supplements,
                        'expected_snapshot' => app(MedicalProfileSnapshotHasher::class)->forProfile($profileRecord),
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
            $this->activatePartnerAction(),
            $this->openPartnerWorkspaceAction(),
            $this->deactivatePartnerAction(),
            $this->assignPartnerAction(),
            ActionGroup::make([
                $this->assignTrackerTaskAction(),
                $this->grantTrackerAccessAction(),
                $this->extendTrackerAccessAction(),
                $this->endTrackerAccessAction(),
            ])
                ->label(__('Доступ к трекеру'))
                ->icon('heroicon-o-sparkles')
                ->button()
                ->color('gray')
                ->visible(fn (): bool => $this->canManageClients()),
            ActionGroup::make([
                $this->marketingConsentActionGroup(),
                Action::make('sourceDetail')
                    ->label(__('Уточнение источника'))
                    ->modalHeading(__('Уточнение источника'))
                    ->modalSubmitActionLabel(__('Сохранить'))
                    ->authorize(fn (): bool => ClientResource::canEdit($this->clientRecord()))
                    ->visible(fn (): bool => ClientResource::canEdit($this->clientRecord()))
                    ->fillForm(fn (): array => ['source_detail' => app(ManageAttributionSourceDetail::class)->read($this->actor(), $this->clientRecord())])
                    ->schema([
                        Textarea::make('source_detail')
                            ->label(__('Кто порекомендовал или откуда узнали'))
                            ->helperText(__('Имя, Telegram, телефон или другое уточнение.'))
                            ->maxLength(500)
                            ->rows(3),
                    ])
                    ->action(function (array $data): void {
                        app(ManageAttributionSourceDetail::class)->update($this->actor(), $this->clientRecord(), $data['source_detail'] ?? null);
                    }),
                Action::make('newSession')
                    ->label(__('Новый сеанс'))
                    ->icon('heroicon-o-plus')
                    ->url(fn (): string => MedicalSessionResource::getUrl('create', shouldGuessMissingParameters: true))
                    ->visible(fn (): bool => MedicalSessionResource::canCreate()),
                ActionGroup::make([
                    $this->blockSelfBookingAction(),
                    $this->unblockSelfBookingAction(),
                ])
                    ->label(__('Доступ к записи'))
                    ->icon('heroicon-o-lock-closed'),
            ])
                ->label(__('Дополнительные действия'))
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
            ->label(__('Маркетинговые рассылки'))
            ->icon('heroicon-o-megaphone')
            ->button()
            ->color('gray')
            ->visible(fn (): bool => $this->canRecordMarketingConsent());
    }

    private function grantMarketingConsentAction(): Action
    {
        return Action::make('grantMarketingConsent')
            ->label(__('Зафиксировать согласие на рассылки'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading(__('Зафиксировать согласие на рассылки'))
            ->modalDescription(__('Укажите, как клиент подтвердил согласие, и подтвердите факт получения согласия.'))
            ->modalSubmitActionLabel(__('Зафиксировать согласие'))
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
            ->label(__('Отозвать согласие на рассылки'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading(__('Отозвать согласие на рассылки'))
            ->modalDescription(__('Укажите, как клиент сообщил об отзыве, и подтвердите факт получения отказа.'))
            ->modalSubmitActionLabel(__('Отозвать согласие'))
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
                ->label(__('Версия согласия'))
                ->required()
                ->maxLength(64)
                ->helperText(__('Укажите версию текста или условия, которые подтвердил клиент.')),
            Select::make('evidence')
                ->label(__('Источник подтверждения'))
                ->options([
                    'crm' => __('Зафиксировано оператором в CRM'),
                    'telegram' => __('Сообщение клиента в Telegram'),
                    'phone' => __('Телефонный разговор'),
                    'written' => __('Письменное согласие'),
                ])
                ->native(false)
                ->required(),
            Checkbox::make('confirmed')
                ->label($granted
                    ? __('Подтверждаю, что клиент действительно согласился на маркетинговые рассылки.')
                    : __('Подтверждаю, что клиент действительно отозвал согласие на маркетинговые рассылки.'))
                ->accepted(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function recordMarketingConsent(bool $granted, array $data): void
    {
        if (! in_array($data['confirmed'] ?? null, [true, 1, '1', 'on', 'yes'], true)) {
            throw ValidationException::withMessages([
                'confirmed' => __('Подтвердите, что сообщение клиента действительно получено.'),
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
            ->title($granted ? __('Согласие на рассылки зафиксировано') : __('Согласие на рассылки отозвано'))
            ->body(__('Версия: :version', ['version' => $consent->version]))
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
            ->label(__('Запретить самостоятельную запись'))
            ->color('danger')
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')
                    ->label(__('Причина ограничения'))
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

    private function activatePartnerAction(): Action
    {
        return Action::make('activatePartner')
            ->label(__('Сделать партнёром'))
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->authorize(fn (): bool => $this->canManageClients())
            ->visible(fn (): bool => ! $this->clientRecord()->referralPartnerProfile?->isActive()
                && $this->canManageClients())
            ->action(function (): void {
                app(ActivateReferralPartner::class)->handle($this->clientRecord(), 'crm', $this->actor());
                $this->clientRecord()->load('referralPartnerProfile');
                Notification::make()->title(__('Клиент стал партнёром'))->success()->send();
            });
    }

    private function openPartnerWorkspaceAction(): Action
    {
        return Action::make('openPartnerWorkspace')
            ->label(__('Открыть партнёрский кабинет'))
            ->icon('heroicon-o-user-group')
            ->url(fn (): string => ReferralPartnerProfileResource::getUrl('view', [
                'record' => $this->clientRecord()->referralPartnerProfile,
            ]))
            ->visible(fn (): bool => $this->clientRecord()->referralPartnerProfile?->isActive() === true
                && $this->canViewClients());
    }

    private function deactivatePartnerAction(): Action
    {
        return Action::make('deactivatePartner')
            ->label(__('Отключить партнёрскую программу'))
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->requiresConfirmation()
            ->authorize(fn (): bool => $this->canManageClients())
            ->visible(fn (): bool => $this->clientRecord()->referralPartnerProfile?->isActive() === true
                && $this->canManageClients())
            ->action(function (): void {
                app(DeactivateReferralPartner::class)->handle($this->clientRecord(), $this->actor());
                $this->clientRecord()->load('referralPartnerProfile');
                Notification::make()->title(__('Партнёрская программа отключена'))->success()->send();
            });
    }

    private function assignPartnerAction(): Action
    {
        return Action::make('assignPartner')
            ->label(__('Указать, кто пригласил'))
            ->icon('heroicon-o-user-plus')
            ->schema([
                Select::make('referrer_client_id')
                    ->label(__('Реферер'))
                    ->searchable()
                    ->native(false)
                    ->options([])
                    ->optionsLimit(50)
                    ->getSearchResultsUsing(function (string $search): array {
                        $actor = auth()->user();
                        $client = $this->clientRecord();

                        return $actor instanceof User
                            ? app(SearchActivePartnersForReferralAssignment::class)->handle($actor, $search, (int) $client->getKey())
                            : [];
                    })
                    ->getOptionLabelUsing(function (mixed $value): ?string {
                        $actor = auth()->user();

                        return $actor instanceof User
                            ? app(SearchActivePartnersForReferralAssignment::class)->optionLabel($actor, $value)
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

                try {
                    app(EstablishManualReferralRelationship::class)->handle(
                        actor: $actor,
                        referrerClientId: (int) $data['referrer_client_id'],
                        referredClientId: (int) $this->clientRecord()->getKey(),
                    );
                    $this->clientRecord()->load('referralRelationship.referrer');
                    Notification::make()->title(__('Реферер указан'))->success()->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Не удалось указать реферера'))
                        ->body(implode(' ', array_map(
                            static fn (array $messages): string => implode(' ', $messages),
                            $exception->errors(),
                        )))
                        ->danger()
                        ->send();
                }
            });
    }

    private function canViewClients(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ViewClients,
        );
    }

    private function grantTrackerAccessAction(): Action
    {
        return Action::make('grantTrackerAccess')
            ->label(__('Выдать доступ'))
            ->icon('heroicon-o-check-circle')
            ->schema([
                Select::make('plan_id')->label(__('Тариф'))->options(fn (): array => TrackerPlan::query()->where('organization_id', app(OrganizationContext::class)->id())->where('is_active', true)->with('currentVersion')->get()->filter(fn (TrackerPlan $plan): bool => $plan->currentVersion !== null)->mapWithKeys(fn (TrackerPlan $plan): array => [$plan->getKey() => $plan->name])->all())->placeholder(__('Без тарифа')),
                DateTimePicker::make('starts_at')->label(__('Начало доступа'))->default(now())->seconds(false)->required()->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone()),
                DateTimePicker::make('ends_at')->label(__('Доступ до'))->default(now()->addDays(30))->seconds(false)->required()->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone()),
                Textarea::make('reason')->label(__('Причина'))->required()->maxLength(500),
            ])
            ->action(function (array $data): void {
                $plan = filled($data['plan_id'] ?? null) ? TrackerPlan::query()->where('organization_id', app(OrganizationContext::class)->id())->findOrFail((int) $data['plan_id']) : null;
                app(GrantTrackerAccess::class)->handle($this->actor(), $this->clientRecord(), $plan, CarbonImmutable::parse((string) $data['starts_at'], app(OrganizationContext::class)->defaultTimezone()), CarbonImmutable::parse((string) $data['ends_at'], app(OrganizationContext::class)->defaultTimezone()), (string) $data['reason']);
                Notification::make()->title(__('Доступ к трекеру выдан'))->success()->send();
            });
    }

    private function assignTrackerTaskAction(): Action
    {
        return Action::make('assignTrackerTask')
            ->label(__('Назначить задачу'))
            ->icon('heroicon-o-clipboard-document-check')
            ->schema([
                TextInput::make('title')
                    ->label(__('Название задачи'))
                    ->required()
                    ->maxLength(160),
                Select::make('task_type')
                    ->label(__('Тип'))
                    ->options([
                        TrackerTaskType::Exercise->value => __('Задача'),
                        TrackerTaskType::Hydration->value => __('Гидратация'),
                        TrackerTaskType::Practice->value => __('Практика'),
                        TrackerTaskType::Other->value => __('Другое'),
                    ])
                    ->default(TrackerTaskType::Other->value)
                    ->native(false)
                    ->required(),
                Select::make('frequency')
                    ->label(__('Повторение'))
                    ->options([
                        TrackerTaskFrequency::Daily->value => __('Каждый день'),
                        TrackerTaskFrequency::Weekly->value => __('Раз в неделю'),
                    ])
                    ->default(TrackerTaskFrequency::Daily->value)
                    ->native(false)
                    ->live()
                    ->required(),
                Select::make('week_day')
                    ->label(__('День недели'))
                    ->options([
                        1 => __('Понедельник'),
                        2 => __('Вторник'),
                        3 => __('Среда'),
                        4 => __('Четверг'),
                        5 => __('Пятница'),
                        6 => __('Суббота'),
                        7 => __('Воскресенье'),
                    ])
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('frequency') === TrackerTaskFrequency::Weekly->value)
                    ->required(fn (Get $get): bool => $get('frequency') === TrackerTaskFrequency::Weekly->value),
                DatePicker::make('starts_on')
                    ->label(__('Начало'))
                    ->default(today())
                    ->native(false)
                    ->required(),
                DatePicker::make('ends_on')
                    ->label(__('Окончание'))
                    ->native(false)
                    ->nullable()
                    ->afterOrEqual('starts_on'),
                TextInput::make('display_order')
                    ->label(__('Порядок'))
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
            ])
            ->visible(fn (): bool => $this->canManageClients())
            ->action(function (array $data): void {
                $timezone = app(OrganizationContext::class)->defaultTimezone();
                app(AssignTrackerTask::class)->handle(
                    actor: $this->actor(),
                    client: $this->clientRecord(),
                    title: (string) $data['title'],
                    type: TrackerTaskType::from((string) $data['task_type']),
                    frequency: TrackerTaskFrequency::from((string) $data['frequency']),
                    startsOn: CarbonImmutable::parse((string) $data['starts_on'], $timezone)->startOfDay(),
                    endsOn: filled($data['ends_on'] ?? null)
                        ? CarbonImmutable::parse((string) $data['ends_on'], $timezone)->startOfDay()
                        : null,
                    weekDay: filled($data['week_day'] ?? null) ? (int) $data['week_day'] : null,
                    displayOrder: (int) $data['display_order'],
                );
                Notification::make()->title(__('Задача назначена'))->success()->send();
            });
    }

    private function extendTrackerAccessAction(): Action
    {
        return Action::make('extendTrackerAccess')
            ->label(__('Продлить доступ'))
            ->icon('heroicon-o-arrow-path')
            ->schema([
                DateTimePicker::make('ends_at')->label(__('Новая дата окончания'))->required()->seconds(false)->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone()),
                Textarea::make('reason')->label(__('Причина'))->required()->maxLength(500),
            ])
            ->visible(fn (): bool => app(ResolveTrackerAccess::class)->handle($this->clientRecord())->entitlement !== null)
            ->action(function (array $data): void {
                app(ExtendTrackerAccess::class)->handle($this->actor(), $this->clientRecord(), CarbonImmutable::parse((string) $data['ends_at'], app(OrganizationContext::class)->defaultTimezone()), (string) $data['reason']);
                Notification::make()->title(__('Доступ к трекеру продлён'))->success()->send();
            });
    }

    private function endTrackerAccessAction(): Action
    {
        return Action::make('endTrackerAccess')
            ->label(__('Завершить доступ'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->label(__('Причина'))->required()->maxLength(500)])
            ->visible(fn (): bool => app(ResolveTrackerAccess::class)->handle($this->clientRecord())->entitlement !== null)
            ->action(function (array $data): void {
                app(EndTrackerAccess::class)->handle($this->actor(), $this->clientRecord(), (string) $data['reason']);
                Notification::make()->title(__('Доступ к трекеру завершён'))->success()->send();
            });
    }

    private function canManageClients(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageClients,
        );
    }

    private function resetStagingAccountAction(): Action
    {
        return Action::make('resetStagingAccount')
            ->label(__('Сбросить аккаунт для теста'))
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Сбросить аккаунт для теста?'))
            ->modalDescription(__('Будут удалены профиль клиента и все связанные с ним данные, включая записи, историю сообщений, диагностику и финансовые операции. Следующий вход через Telegram создаст новый аккаунт. Это действие доступно только на staging и не отменяется в приложении.'))
            ->modalSubmitActionLabel(__('Сбросить аккаунт'))
            ->authorize(fn (): bool => $this->canResetStagingAccount())
            ->visible(fn (): bool => $this->canResetStagingAccount())
            ->action(function (): void {
                try {
                    app(ResetStagingClientAccount::class)->handle($this->actor(), $this->clientRecord());
                    Notification::make()->title(__('Аккаунт сброшен для повторного теста'))->success()->send();
                    $this->redirect(ClientResource::getUrl('index'));
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Аккаунт не сброшен'))
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                }
            });
    }

    private function canResetStagingAccount(): bool
    {
        return app()->environment(['local', 'staging', 'testing']) && $this->canManageClients();
    }

    private function unblockSelfBookingAction(): Action
    {
        return Action::make('unblockSelfBooking')
            ->label(__('Разрешить самостоятельную запись'))
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

    /** @return array<int, Hidden|Textarea> */
    private static function medicalProfileSchema(): array
    {
        return [
            Hidden::make('expected_snapshot')->dehydrated()->nullable()->string(),
            Textarea::make('anamnesis')
                ->label(__('Анамнез'))
                ->rows(3)
                ->maxLength(10000),
            Textarea::make('complaints_goals')
                ->label(__('Жалобы и цели'))
                ->rows(3)
                ->maxLength(10000),
            Textarea::make('operations_injuries')
                ->label(__('Операции и травмы'))
                ->rows(3)
                ->maxLength(10000),
            Textarea::make('medicines')
                ->label(__('Лекарственные препараты'))
                ->rows(3)
                ->maxLength(10000),
            Textarea::make('supplements')
                ->label(__('Биологически активные добавки'))
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
