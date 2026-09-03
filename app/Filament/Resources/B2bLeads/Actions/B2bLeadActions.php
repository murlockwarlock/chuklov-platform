<?php

namespace App\Filament\Resources\B2bLeads\Actions;

use App\Models\User;
use App\Modules\B2B\Application\CancelB2bSalesCall;
use App\Modules\B2B\Application\GetB2bSalesCallReadiness;
use App\Modules\B2B\Application\RecreateB2bSalesCallMeeting;
use App\Modules\B2B\Application\RescheduleB2bSalesCall;
use App\Modules\B2B\Application\RetryB2bSalesCallProvider;
use App\Modules\B2B\Application\SetB2bSalesCallMeetingMode;
use App\Modules\B2B\Application\UpdateB2bLeadStatus;
use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

final class B2bLeadActions
{
    /** @param Closure(): void $refresh */
    /** @return list<Action|ActionGroup> */
    public static function for(B2bLead $lead, Closure $refresh): array
    {
        $actor = auth()->user();
        $canManage = $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageB2bLeads,
        );

        $actions = [
            Action::make('contacted')
                ->label('Отметить: связались')
                ->visible($canManage && $lead->status !== B2bLeadStatus::Closed)
                ->successNotificationTitle('Лид отмечен как обработанный')
                ->action(function () use ($actor, $lead, $refresh): void {
                    abort_unless($actor instanceof User, 403);
                    app(UpdateB2bLeadStatus::class)->handle($actor, $lead, B2bLeadStatus::Contacted, $lead->event_version);
                    $refresh();
                }),
            Action::make('closed')
                ->label('Закрыть лид')
                ->color('gray')
                ->visible($canManage && $lead->status !== B2bLeadStatus::Closed)
                ->requiresConfirmation()
                ->modalHeading('Закрыть лид')
                ->modalDescription('Лид будет закрыт. Запланированный разговор не отменяется этим действием.')
                ->successNotificationTitle('Лид закрыт')
                ->action(function () use ($actor, $lead, $refresh): void {
                    abort_unless($actor instanceof User, 403);
                    app(UpdateB2bLeadStatus::class)->handle($actor, $lead, B2bLeadStatus::Closed, $lead->event_version);
                    $refresh();
                }),
            Action::make('reschedule')
                ->label('Перенести разговор')
                ->visible($canManage && $lead->salesCall->status === B2bSalesCallStatus::Scheduled)
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label('Новое время')
                        ->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone())
                        ->seconds(false)
                        ->required(),
                ])
                ->successNotificationTitle('Разговор перенесён')
                ->action(function (array $data) use ($actor, $lead, $refresh): void {
                    abort_unless($actor instanceof User, 403);
                    $call = self::call($lead);
                    $startsAt = $data['starts_at'] instanceof \DateTimeInterface
                        ? $data['starts_at']
                        : CarbonImmutable::parse((string) $data['starts_at'], app(OrganizationContext::class)->organization()->defaultTimezone());
                    app(RescheduleB2bSalesCall::class)->handle($actor, $call, $startsAt, $call->requested_timezone, $call->event_version);
                    $refresh();
                }),
            Action::make('cancel')
                ->label('Отменить разговор')
                ->color('danger')
                ->visible($canManage && $lead->salesCall->status === B2bSalesCallStatus::Scheduled)
                ->requiresConfirmation()
                ->modalHeading('Отменить разговор')
                ->modalDescription('Будет отменён только запланированный разговор. Статус лида останется отдельным.')
                ->successNotificationTitle('Разговор отменён')
                ->action(function () use ($actor, $lead, $refresh): void {
                    abort_unless($actor instanceof User, 403);
                    $call = self::call($lead);
                    app(CancelB2bSalesCall::class)->handle($actor, $call, $call->event_version);
                    $refresh();
                }),
            Action::make('meetingMode')
                ->label('Режим и ссылка')
                ->visible($canManage && $lead->salesCall->status === B2bSalesCallStatus::Scheduled)
                ->schema([
                    Select::make('meeting_mode')->label('Режим')->options([
                        VideoMeetingMode::Automatic->value => 'Zoom автоматически',
                        VideoMeetingMode::Manual->value => 'Ручная ссылка',
                    ])->required()->live(),
                    Placeholder::make('automatic_readiness')
                        ->label('Готовность автоматического Zoom')
                        ->content(function (): string {
                            $readiness = app(GetB2bSalesCallReadiness::class)->handle();

                            return 'Zoom: '.($readiness['automaticZoomConfigured'] ? 'подключён' : 'не подключён')
                                .' · длительность: '.($readiness['durationConfigured'] ? 'настроена' : 'не настроена')
                                .' · календарь: '.($readiness['calendarConfigured'] ? 'настроен' : 'не настроен');
                        })
                        ->visible(fn (Get $get): bool => $get('meeting_mode') === VideoMeetingMode::Automatic),
                    TextInput::make('manual_meeting_url')
                        ->label('HTTPS-ссылка')
                        ->url()
                        ->maxLength(2000)
                        ->visible(fn (Get $get): bool => $get('meeting_mode') === VideoMeetingMode::Manual)
                        ->required(fn (Get $get): bool => $get('meeting_mode') === VideoMeetingMode::Manual)
                        ->helperText('Вставьте ссылку, по которой клиент присоединится к разговору.'),
                ])
                ->fillForm(fn (B2bLead $record): array => [
                    'meeting_mode' => $record->salesCall->meeting_mode->value,
                    'manual_meeting_url' => $record->salesCall->manual_meeting_url,
                ])
                ->successNotificationTitle('Режим и ссылка сохранены')
                ->action(function (array $data) use ($actor, $lead, $refresh): void {
                    abort_unless($actor instanceof User, 403);
                    $call = self::call($lead);
                    app(SetB2bSalesCallMeetingMode::class)->handle($actor, $call, VideoMeetingMode::from((string) $data['meeting_mode']), isset($data['manual_meeting_url']) ? (string) $data['manual_meeting_url'] : null, $call->event_version);
                    $refresh();
                }),
            Action::make('hostLaunch')
                ->label('Открыть как ведущий')
                ->visible($canManage
                    && $lead->salesCall->status === B2bSalesCallStatus::Scheduled
                    && $lead->salesCall->meeting_mode === VideoMeetingMode::Automatic
                    && $lead->salesCall->provider_sync_status === VideoMeetingSyncStatus::Ready
                    && $lead->salesCall->providerIdentity() !== null)
                ->url(fn (): string => route('admin.b2b.sales-call.host-launch', ['salesCallId' => self::call($lead)->getKey()]))
                ->openUrlInNewTab(),
            Action::make('retryProvider')
                ->label('Повторить синхронизацию')
                ->visible($canManage && in_array($lead->salesCall->provider_sync_status, [VideoMeetingSyncStatus::Failed, VideoMeetingSyncStatus::ReconciliationRequired, VideoMeetingSyncStatus::CancellationPending], true))
                ->successNotificationTitle('Синхронизация поставлена в очередь')
                ->action(function () use ($actor, $lead, $refresh): void {
                    abort_unless($actor instanceof User, 403);
                    $call = self::call($lead);
                    app(RetryB2bSalesCallProvider::class)->handle($actor, $call, $call->event_version);
                    $refresh();
                }),
            Action::make('recreateMeeting')
                ->label('Создать Zoom заново')
                ->visible($canManage && $lead->salesCall->status === B2bSalesCallStatus::Scheduled && $lead->salesCall->meeting_mode === VideoMeetingMode::Automatic)
                ->requiresConfirmation()
                ->successNotificationTitle('Создание Zoom поставлено в очередь')
                ->action(function () use ($actor, $lead, $refresh): void {
                    abort_unless($actor instanceof User, 403);
                    $call = self::call($lead);
                    app(RecreateB2bSalesCallMeeting::class)->handle($actor, $call, $call->event_version);
                    $refresh();
                }),
        ];

        return [
            ActionGroup::make($actions)
                ->label('Действия')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button()
                ->dropdownAutoPlacement(),
        ];
    }

    private static function call(B2bLead $lead): B2bSalesCall
    {
        return B2bSalesCall::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('lead_id', $lead->getKey())
            ->firstOrFail();
    }
}
