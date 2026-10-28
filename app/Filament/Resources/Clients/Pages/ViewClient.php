<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Pages\Messages;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Filament\Resources\Clients\Schemas\MedicalProfileForm;
use App\Filament\Resources\ReferralPartnerProfiles\ReferralPartnerProfileResource;
use App\Filament\Support\LocalizedViewRecord;
use App\Models\User;
use App\Modules\Analytics\Application\AcceptAnalyticsClassification;
use App\Modules\Analytics\Application\SuggestAnalyticsTags;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Attribution\Application\ManageAttributionSourceDetail;
use App\Modules\Feedback\Application\FeedbackThemeCatalog;
use App\Modules\Feedback\Application\RecordNpsSubmission;
use App\Modules\Identity\Application\BlockClientBlacklist;
use App\Modules\Identity\Application\BlockClientSelfBooking;
use App\Modules\Identity\Application\GetLatestClientMarketingConsent;
use App\Modules\Identity\Application\RecordClientConsent;
use App\Modules\Identity\Application\ResetStagingClientAccount;
use App\Modules\Identity\Application\UnblockClientBlacklist;
use App\Modules\Identity\Application\UnblockClientSelfBooking;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\MedicalProfiles\Application\DTOs\MedicalPainData;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\MedicalProfiles\Application\MedicalProfileSnapshotHasher;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfilePain;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\DeactivateReferralPartner;
use App\Modules\Referrals\Application\EstablishManualReferralRelationship;
use App\Modules\Referrals\Application\ReplaceReferralRelationship;
use App\Modules\Referrals\Application\SearchActivePartnersForReferralAssignment;
use App\Modules\Referrals\Domain\Enums\ReferralEstablishmentMethod;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Tracker\Application\AssignTrackerTask;
use App\Modules\Tracker\Application\EndTrackerAccess;
use App\Modules\Tracker\Application\ExtendTrackerAccess;
use App\Modules\Tracker\Application\GrantTrackerAccess;
use App\Modules\Tracker\Application\ResolveTrackerAccess;
use App\Modules\Tracker\Application\TrackerGoalTagCatalog;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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
                        ...MedicalProfileForm::fill($profile),
                        'expected_snapshot' => app(MedicalProfileSnapshotHasher::class)->forProfile($profileRecord),
                    ];
                })
                ->successNotificationTitle(__('Медицинский профиль сохранён'))
                ->modalSubmitAction(fn (Action $action): Action => $action->extraAttributes(['formnovalidate' => true]))
                ->schema(MedicalProfileForm::components())
                ->authorize(fn (): bool => ClientResource::canEdit($this->clientRecord()))
                ->visible(fn (): bool => ClientResource::canEdit($this->clientRecord()))
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $client = $this->clientRecord();

                    abort_unless($actor instanceof User, 403);

                    app(UpdateMedicalProfile::class)->handle(
                        $actor,
                        $client,
                        MedicalProfileForm::command($data),
                    );
                }),
            $this->recordFeedbackAction(),
            $this->activatePartnerAction(),
            $this->openPartnerWorkspaceAction(),
            $this->deactivatePartnerAction(),
            $this->assignPartnerAction(),
            Action::make('newSession')
                ->label(__('Новый сеанс'))
                ->icon('heroicon-o-plus')
                ->url(fn (): string => MedicalSessionResource::getUrl('create', ['client' => $this->clientRecord()]))
                ->visible(fn (): bool => MedicalSessionResource::canCreate()),
            ActionGroup::make([
                $this->assignTrackerTaskAction(),
                $this->suggestTrackerGoalsAction(),
                $this->acceptTrackerGoalsAction(),
                $this->suggestProfilePainTagsAction(),
                $this->acceptProfilePainTagsAction(),
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
                    ->label(__('Уточнить источник'))
                    ->modalHeading(__('Уточнить источник'))
                    ->modalSubmitActionLabel(__('Сохранить'))
                    ->authorize(fn (): bool => ClientResource::canEdit($this->clientRecord()))
                    ->visible(fn (): bool => ClientResource::canEdit($this->clientRecord()))
                    ->fillForm(fn (): array => ['source_detail' => app(ManageAttributionSourceDetail::class)->read($this->actor(), $this->clientRecord())])
                    ->schema([
                        Textarea::make('source_detail')
                            ->label(__('Комментарий к источнику'))
                            ->helperText(__('Например: знакомые, Telegram, сайт или другое уточнение. Это поле не назначает того, кто пригласил клиента. Для реферальной связи используйте «Кто пригласил».'))
                            ->maxLength(500)
                            ->rows(3),
                    ])
                    ->action(function (array $data): void {
                        app(ManageAttributionSourceDetail::class)->update($this->actor(), $this->clientRecord(), $data['source_detail'] ?? null);
                    }),
                ActionGroup::make([
                    $this->blockSelfBookingAction(),
                    $this->unblockSelfBookingAction(),
                ])
                    ->label(__('Доступ к записи'))
                    ->icon('heroicon-o-lock-closed'),
                ActionGroup::make([
                    $this->blockBlacklistAction(),
                    $this->unblockBlacklistAction(),
                ])
                    ->label(__('Чёрный список'))
                    ->icon('heroicon-o-no-symbol'),
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
            ->label(fn (): string => $this->currentReferralRelationship() instanceof ReferralRelationship
                ? __('Заменить пригласившего')
                : __('Указать, кто пригласил'))
            ->icon('heroicon-o-user-plus')
            ->modalHeading(fn (): string => $this->currentReferralRelationship() instanceof ReferralRelationship
                ? __('Заменить пригласившего')
                : __('Кто пригласил'))
            ->modalSubmitActionLabel(fn (): string => $this->currentReferralRelationship() instanceof ReferralRelationship
                ? __('Заменить пригласившего')
                : __('Сохранить'))
            ->requiresConfirmation(fn (): bool => $this->currentReferralRelationship() instanceof ReferralRelationship)
            ->fillForm(fn (): array => [
                'current_referrer' => $this->currentReferrerSummary(),
                'replacement_warning' => $this->currentReferralRelationship() instanceof ReferralRelationship
                    ? __('У клиента уже указан пригласивший. Изменение заменит текущую реферальную связь. Начисленные ранее вознаграждения останутся в истории за прежним пригласившим.')
                    : null,
            ])
            ->schema([
                TextInput::make('current_referrer')
                    ->label(__('Текущий пригласивший'))
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (): bool => $this->currentReferralRelationship() instanceof ReferralRelationship),
                Textarea::make('replacement_warning')
                    ->label(__('Внимание'))
                    ->disabled()
                    ->dehydrated(false)
                    ->rows(3)
                    ->visible(fn (): bool => $this->currentReferralRelationship() instanceof ReferralRelationship),
                Select::make('referrer_client_id')
                    ->label(__('Кто пригласил'))
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
                Textarea::make('replacement_reason')
                    ->label(__('Причина замены'))
                    ->maxLength(500)
                    ->rows(3)
                    ->visible(fn (): bool => $this->currentReferralRelationship() instanceof ReferralRelationship),
            ])
            ->visible(function (): bool {
                $actor = auth()->user();
                if (! $actor instanceof User) {
                    return false;
                }

                $permission = $this->currentReferralRelationship() instanceof ReferralRelationship
                    ? OrganizationPermission::ManageReferralRelationships
                    : OrganizationPermission::ManageClients;

                return app(OrganizationAuthorizer::class)->allows(
                    $actor,
                    app(OrganizationContext::class)->organization(),
                    $permission,
                );
            })
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    $current = $this->currentReferralRelationship();
                    if ($current instanceof ReferralRelationship) {
                        app(ReplaceReferralRelationship::class)->handle(
                            actor: $actor,
                            referrerClientId: (int) $data['referrer_client_id'],
                            referredClientId: (int) $this->clientRecord()->getKey(),
                            reason: isset($data['replacement_reason']) ? (string) $data['replacement_reason'] : null,
                        );
                    } else {
                        app(EstablishManualReferralRelationship::class)->handle(
                            actor: $actor,
                            referrerClientId: (int) $data['referrer_client_id'],
                            referredClientId: (int) $this->clientRecord()->getKey(),
                        );
                    }
                    $this->clientRecord()->load('referralRelationship.referrer');
                    Notification::make()->title($current instanceof ReferralRelationship
                        ? __('Пригласивший заменён')
                        : __('Пригласивший указан'))->success()->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Не удалось сохранить реферальную связь'))
                        ->body(implode(' ', array_map(
                            static fn (array $messages): string => implode(' ', $messages),
                            $exception->errors(),
                        )))
                        ->danger()
                        ->send();
                }
            });
    }

    private function currentReferralRelationship(): ?ReferralRelationship
    {
        return ReferralRelationship::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('referred_client_id', $this->clientRecord()->getKey())
            ->whereNull('superseded_at')
            ->with('referrer')
            ->first();
    }

    private function currentReferrerSummary(): ?string
    {
        $relationship = $this->currentReferralRelationship();
        if (! $relationship instanceof ReferralRelationship) {
            return null;
        }

        $referrer = $relationship->referrer;
        $name = trim((string) $referrer?->full_name);
        $method = match ($relationship->establishment_method) {
            ReferralEstablishmentMethod::AutomaticReferralLink => $relationship->referral_campaign_link_id === null
                ? __('персональная ссылка')
                : __('кампания'),
            ReferralEstablishmentMethod::ManualCrm => __('указан в CRM'),
        };

        return trim(($name !== '' ? $name : __('Клиент')).' · '.$method);
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
                Select::make('goal_tag_ids')
                    ->label(__('Категории задачи'))
                    ->options(fn (): array => app(TrackerGoalTagCatalog::class)->options(app(OrganizationContext::class)->id()))
                    ->multiple()
                    ->searchable()
                    ->preload(),
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
                    goalTagIds: is_array($data['goal_tag_ids'] ?? null) ? $data['goal_tag_ids'] : [],
                );
                Notification::make()->title(__('Задача назначена'))->success()->send();
            });
    }

    private function suggestTrackerGoalsAction(): Action
    {
        return Action::make('suggestTrackerGoals')
            ->label(__('Предложить категории задач с ИИ'))
            ->icon('heroicon-o-sparkles')
            ->schema([
                Select::make('task_id')
                    ->label(__('Задача'))
                    ->options(fn (): array => $this->trackerTaskOptions())
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->visible(fn (): bool => $this->canManageClients())
            ->action(function (array $data): void {
                $task = $this->trackerTask((int) ($data['task_id'] ?? 0));
                $options = app(TrackerGoalTagCatalog::class)->options(app(OrganizationContext::class)->id());
                $result = app(SuggestAnalyticsTags::class)->handle(
                    actor: $this->actor(),
                    taxonomy: 'goals',
                    sourceType: 'tracker_task',
                    sourceId: (int) $task->getKey(),
                    clientId: (int) $task->client_id,
                    sourceText: (string) $task->title,
                    availableTags: array_map(
                        static fn (int|string $id, string $label): array => ['id' => (int) $id, 'label' => $label],
                        array_keys($options),
                        array_values($options),
                    ),
                );
                $labels = collect($result->suggestions)->pluck('label')->implode(', ');
                if ($labels !== '') {
                    Notification::make()->success()->title(__('Предложения категорий готовы'))->body($labels)->send();

                    return;
                }

                Notification::make()->warning()->title(__('Предложения категорий недоступны'))->body(__('Настройте prompt классификатора или добавьте категории задач.'))->send();
            });
    }

    private function acceptTrackerGoalsAction(): Action
    {
        return Action::make('acceptTrackerGoals')
            ->label(__('Принять категории задач'))
            ->icon('heroicon-o-check-circle')
            ->schema([
                Select::make('task_id')
                    ->label(__('Задача'))
                    ->options(fn (): array => $this->trackerTaskOptionsWithSuggestions())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $run = $this->latestGoalClassificationRun((int) $state);
                        $set('goal_tag_ids', $this->classificationSuggestionIds($run));
                    })
                    ->required(),
                Select::make('goal_tag_ids')
                    ->label(__('Категории задачи'))
                    ->options(fn (): array => app(TrackerGoalTagCatalog::class)->options(app(OrganizationContext::class)->id()))
                    ->multiple()
                    ->searchable()
                    ->preload(),
            ])
            ->visible(fn (): bool => $this->canManageClients() && $this->trackerTaskWithSuggestionsExists())
            ->action(function (array $data): void {
                $taskId = (int) ($data['task_id'] ?? 0);
                $run = $this->latestGoalClassificationRun($taskId);
                abort_unless($run instanceof AnalyticsClassificationRun, 422);
                app(AcceptAnalyticsClassification::class)->handle(
                    actor: $this->actor(),
                    runId: (int) $run->getKey(),
                    themeIds: is_array($data['goal_tag_ids'] ?? null) ? $data['goal_tag_ids'] : [],
                );
                Notification::make()->success()->title(__('Категории задачи сохранены'))->send();
            });
    }

    private function suggestProfilePainTagsAction(): Action
    {
        return Action::make('suggestProfilePainTags')
            ->label(__('Предложить категории боли с ИИ'))
            ->icon('heroicon-o-sparkles')
            ->schema([
                Select::make('pain_id')
                    ->label(__('Боль клиента'))
                    ->options(fn (): array => $this->medicalProfilePainOptions())
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->visible(fn (): bool => $this->canManageClients())
            ->action(function (array $data): void {
                $pain = $this->medicalProfilePain((int) ($data['pain_id'] ?? 0));
                $profile = app(GetMedicalProfile::class)->handle($this->actor(), $this->clientRecord());
                $sourceText = null;
                foreach ($profile !== null ? $profile->pains : [] as $profilePain) {
                    if ((int) $profilePain->id === (int) $pain->getKey()) {
                        $sourceText = $profilePain->name;
                        break;
                    }
                }
                if (! is_string($sourceText) || trim($sourceText) === '') {
                    Notification::make()->warning()->title(__('Не удалось прочитать боль'))->send();

                    return;
                }
                $options = app(MedicalProblemTagCatalog::class)->options(app(OrganizationContext::class)->id());
                $result = app(SuggestAnalyticsTags::class)->handle(
                    actor: $this->actor(),
                    taxonomy: 'complaints',
                    sourceType: 'medical_profile_pain',
                    sourceId: (int) $pain->getKey(),
                    clientId: (int) $pain->client_id,
                    sourceText: $sourceText,
                    availableTags: array_map(
                        static fn (int|string $id, string $label): array => ['id' => (int) $id, 'label' => $label],
                        array_keys($options),
                        array_values($options),
                    ),
                );
                $labels = collect($result->suggestions)->pluck('label')->implode(', ');
                if ($labels !== '') {
                    Notification::make()->success()->title(__('Предложения категорий готовы'))->body($labels)->send();

                    return;
                }

                Notification::make()->warning()->title(__('Предложения категорий недоступны'))->body(__('Настройте prompt классификатора или добавьте категории проблем.'))->send();
            });
    }

    private function acceptProfilePainTagsAction(): Action
    {
        return Action::make('acceptProfilePainTags')
            ->label(__('Принять категории боли'))
            ->icon('heroicon-o-check-circle')
            ->schema([
                Select::make('pain_id')
                    ->label(__('Боль клиента'))
                    ->options(fn (): array => $this->medicalProfilePainOptionsWithSuggestions())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $run = $this->latestComplaintClassificationRun((int) $state);
                        $set('tag_ids', $this->classificationSuggestionIds($run));
                    })
                    ->required(),
                Select::make('tag_ids')
                    ->label(__('Категории проблемы'))
                    ->options(fn (): array => app(MedicalProblemTagCatalog::class)->options(app(OrganizationContext::class)->id()))
                    ->multiple()
                    ->searchable()
                    ->preload(),
            ])
            ->visible(fn (): bool => $this->canManageClients() && $this->medicalProfilePainWithSuggestionsExists())
            ->action(function (array $data): void {
                $painId = (int) ($data['pain_id'] ?? 0);
                $run = $this->latestComplaintClassificationRun($painId);
                abort_unless($run instanceof AnalyticsClassificationRun, 422);
                app(AcceptAnalyticsClassification::class)->handle(
                    actor: $this->actor(),
                    runId: (int) $run->getKey(),
                    themeIds: is_array($data['tag_ids'] ?? null) ? $data['tag_ids'] : [],
                );
                Notification::make()->success()->title(__('Категории боли сохранены'))->send();
            });
    }

    /** @return array<int, string> */
    private function trackerTaskOptions(): array
    {
        return TrackerTask::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('client_id', $this->clientRecord()->getKey())
            ->orderByDesc('active')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get(['id', 'title', 'active'])
            ->mapWithKeys(static fn (TrackerTask $task): array => [
                $task->getKey() => (string) $task->title.($task->active ? '' : ' · '.__('неактивна')),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function trackerTaskOptionsWithSuggestions(): array
    {
        return TrackerTask::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('client_id', $this->clientRecord()->getKey())
            ->whereIn('id', AnalyticsClassificationRun::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->where('taxonomy', 'goals')
                ->where('source_type', 'tracker_task')
                ->where('status', ClassificationRunStatus::NeedsReview)
                ->pluck('source_id'))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get(['id', 'title'])
            ->mapWithKeys(static fn (TrackerTask $task): array => [$task->getKey() => (string) $task->title])
            ->all();
    }

    private function trackerTaskWithSuggestionsExists(): bool
    {
        return $this->trackerTaskOptionsWithSuggestions() !== [];
    }

    private function trackerTask(int $taskId): TrackerTask
    {
        return TrackerTask::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('client_id', $this->clientRecord()->getKey())
            ->whereKey($taskId)
            ->firstOrFail();
    }

    private function latestGoalClassificationRun(int $taskId): ?AnalyticsClassificationRun
    {
        $this->trackerTask($taskId);

        return AnalyticsClassificationRun::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('taxonomy', 'goals')
            ->where('source_type', 'tracker_task')
            ->where('source_id', $taskId)
            ->where('status', ClassificationRunStatus::NeedsReview)
            ->latest('id')
            ->first();
    }

    /** @return array<int, string> */
    private function medicalProfilePainOptions(): array
    {
        $profile = app(GetMedicalProfile::class)->handle($this->actor(), $this->clientRecord());

        $pains = $profile !== null ? $profile->pains : [];

        return array_reduce($pains, static function (array $options, MedicalPainData $pain): array {
            $options[(int) $pain->id] = $pain->name;

            return $options;
        }, []);
    }

    /** @return array<int, string> */
    private function medicalProfilePainOptionsWithSuggestions(): array
    {
        $profilePainIds = AnalyticsClassificationRun::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('taxonomy', 'complaints')
            ->where('source_type', 'medical_profile_pain')
            ->where('status', ClassificationRunStatus::NeedsReview)
            ->pluck('source_id');
        $profile = app(GetMedicalProfile::class)->handle($this->actor(), $this->clientRecord());
        $pains = $profile !== null ? $profile->pains : [];

        return array_reduce($pains, static function (array $options, MedicalPainData $pain) use ($profilePainIds): array {
            if ($profilePainIds->contains($pain->id)) {
                $options[(int) $pain->id] = $pain->name;
            }

            return $options;
        }, []);
    }

    private function medicalProfilePainWithSuggestionsExists(): bool
    {
        return $this->medicalProfilePainOptionsWithSuggestions() !== [];
    }

    private function medicalProfilePain(int $painId): MedicalProfilePain
    {
        return MedicalProfilePain::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('client_id', $this->clientRecord()->getKey())
            ->whereKey($painId)
            ->firstOrFail();
    }

    private function latestComplaintClassificationRun(int $painId): ?AnalyticsClassificationRun
    {
        $this->medicalProfilePain($painId);

        return AnalyticsClassificationRun::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('taxonomy', 'complaints')
            ->where('source_type', 'medical_profile_pain')
            ->where('source_id', $painId)
            ->where('status', ClassificationRunStatus::NeedsReview)
            ->latest('id')
            ->first();
    }

    /** @return list<int> */
    private function classificationSuggestionIds(?AnalyticsClassificationRun $run): array
    {
        if (! $run instanceof AnalyticsClassificationRun || ! is_array($run->suggestions)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_column($run->suggestions, 'id'),
        ));
    }

    private function recordFeedbackAction(): Action
    {
        return Action::make('recordFeedback')
            ->label(__('Записать обратную связь'))
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->schema([
                Select::make('score')
                    ->label(__('Оценка клиента'))
                    ->options(array_combine(range(0, 10), range(0, 10)))
                    ->native(false)
                    ->required(),
                Textarea::make('internal_feedback')
                    ->label(__('Что можно улучшить'))
                    ->maxLength(4000)
                    ->rows(4),
                Select::make('theme_ids')
                    ->label(__('Темы комментария'))
                    ->options(fn (): array => app(FeedbackThemeCatalog::class)->options(app(OrganizationContext::class)->id()))
                    ->multiple()
                    ->searchable()
                    ->preload(),
            ])
            ->visible(fn (): bool => $this->canManageClients())
            ->authorize(fn (): bool => $this->canManageClients())
            ->action(function (array $data): void {
                $submission = app(RecordNpsSubmission::class)->handle(
                    client: $this->clientRecord(),
                    score: (int) $data['score'],
                    internalFeedback: is_string($data['internal_feedback'] ?? null) ? $data['internal_feedback'] : null,
                    idempotencyKey: 'crm-feedback-'.Str::uuid()->toString(),
                    source: 'crm',
                    themeIds: is_array($data['theme_ids'] ?? null) ? $data['theme_ids'] : [],
                );
                Notification::make()
                    ->title(__('Обратная связь сохранена'))
                    ->body(__('Запись :date добавлена в историю клиента.', ['date' => $submission->submitted_at?->format('d.m.Y H:i')]))
                    ->success()
                    ->send();
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
                $actor = $this->actor();
                $client = $this->clientRecord();

                try {
                    app(ResetStagingClientAccount::class)->handle($actor, $client);
                    Notification::make()->title(__('Аккаунт сброшен для повторного теста'))->success()->send();
                    $this->redirect(ClientResource::getUrl('index'));
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Аккаунт не сброшен'))
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                } catch (Throwable $exception) {
                    $referenceId = (string) Str::uuid();
                    $clientStillExists = null;

                    try {
                        $clientStillExists = Client::query()
                            ->where('organization_id', $client->organization_id)
                            ->whereKey($client->getKey())
                            ->exists();
                    } catch (Throwable) {
                    }

                    $outcome = match ($clientStillExists) {
                        true => 'failed',
                        false => 'partial',
                        null => 'unknown',
                    };
                    $errorCode = match ($clientStillExists) {
                        true => 'reset_failed',
                        false => 'storage_cleanup_failed',
                        null => 'reset_outcome_unknown',
                    };

                    Log::error('client.staging_account.reset_failed', [
                        'event_name' => 'client.staging_account.reset_failed',
                        'organization_id' => $client->organization_id,
                        'actor_user_id' => $actor->getKey(),
                        'entity_type' => 'client',
                        'entity_id' => $client->getKey(),
                        'safe_error_code' => $errorCode,
                        'exception_class' => $exception::class,
                        'outcome' => $outcome,
                        'reference_id' => $referenceId,
                        'occurred_at_utc' => now()->utc()->toIso8601String(),
                    ]);

                    $body = match ($clientStillExists) {
                        true => __('Сброс не выполнен, данные клиента сохранены. Исправьте причину и повторите действие. Код обращения: :reference', ['reference' => $referenceId]),
                        false => __('Аккаунт удалён, но не удалось полностью очистить связанные файлы. Передайте администратору код обращения: :reference', ['reference' => $referenceId]),
                        null => __('Не удалось подтвердить результат. Обновите список клиентов: если аккаунт остался, повторите сброс; если удалён, передайте администратору код обращения: :reference', ['reference' => $referenceId]),
                    };

                    Notification::make()
                        ->title($clientStillExists === false ? __('Аккаунт удалён не полностью') : __('Не удалось завершить сброс'))
                        ->body($body)
                        ->danger()
                        ->send();

                    if ($clientStillExists === false) {
                        $this->redirect(ClientResource::getUrl('index'));
                    }
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

    private function blockBlacklistAction(): Action
    {
        return Action::make('blockBlacklist')
            ->label(__('Добавить в чёрный список'))
            ->color('danger')
            ->modalHeading(__('Добавить клиента в чёрный список'))
            ->modalDescription(__('Укажите причину. После добавления клиент не сможет записаться самостоятельно.'))
            ->modalSubmitActionLabel(__('Добавить в чёрный список'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Причина'))
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (): bool => $this->clientRecord()->activeBlacklistRestriction === null
                && ClientResource::canEdit($this->clientRecord()))
            ->action(function (array $data): void {
                app(BlockClientBlacklist::class)->handle(
                    $this->actor(),
                    $this->clientRecord(),
                    (string) $data['reason'],
                );
                $this->clientRecord()->load('activeBlacklistRestriction');
                Notification::make()
                    ->success()
                    ->title(__('Клиент добавлен в чёрный список'))
                    ->send();
            });
    }

    private function unblockBlacklistAction(): Action
    {
        return Action::make('unblockBlacklist')
            ->label(__('Убрать из чёрного списка'))
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Убрать клиента из чёрного списка?'))
            ->modalDescription(__('После снятия ограничения клиент снова сможет записаться самостоятельно.'))
            ->modalSubmitActionLabel(__('Убрать из чёрного списка'))
            ->visible(fn (): bool => $this->clientRecord()->activeBlacklistRestriction !== null
                && ClientResource::canEdit($this->clientRecord()))
            ->action(function (): void {
                app(UnblockClientBlacklist::class)->handle($this->actor(), $this->clientRecord());
                $this->clientRecord()->load('activeBlacklistRestriction');
                Notification::make()
                    ->success()
                    ->title(__('Клиент убран из чёрного списка'))
                    ->send();
            });
    }

    private function clientRecord(): Client
    {
        $record = $this->getRecord();

        abort_unless($record instanceof Client, 404);

        return $record;
    }
}
