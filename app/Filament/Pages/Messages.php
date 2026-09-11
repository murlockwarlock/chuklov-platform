<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\MessageComposer;
use App\Models\User;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\ClientCompanion\Application\Actions\ReplyToCompanion;
use App\Modules\ClientCompanion\Application\Actions\ResolveCompanionHandoff;
use App\Modules\ClientCompanion\Application\Actions\ResumeCompanionAi;
use App\Modules\ClientCompanion\Application\Actions\UploadCompanionCommunicationAttachment;
use App\Modules\ClientCompanion\Application\Services\CompanionExportService;
use App\Modules\ClientCompanion\Application\Services\ListCompanionCommunicationAttachments;
use App\Modules\ClientCompanion\Application\Services\ReadCompanionConversation;
use App\Modules\ClientCompanion\Application\Services\ReadCompanionWorkspace;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Support\RichText\RichTextDocument;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @property-read Schema $form */
final class Messages extends Page
{
    protected static ?string $title = 'Сообщения';

    protected static ?string $navigationLabel = 'Сообщения';

    protected static string|\UnitEnum|null $navigationGroup = 'Коммуникации';

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected string $view = 'filament.pages.messages';

    public string $search = '';

    public ?int $selectedClientId = null;

    /** @var array<string, mixed> */
    public array $data = [
        'body' => null,
        'delivery_mode' => NotificationMessageMode::Text->value,
        'caption_position' => 'below',
        'existing_attachment_id' => null,
        'new_attachment' => null,
        'remove_media' => false,
    ];

    /** @var list<array<string, mixed>> */
    public array $historyMessages = [];

    /** @var array<string, mixed> */
    public array $historyState = [];

    public ?int $historyClientId = null;

    public bool $historyHasLoadedOlder = false;

    public bool $mobileChatOpen = false;

    public bool $showClientInfo = false;

    public static function canAccess(): bool
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }

        try {
            $organization = app(OrganizationContext::class)->organization();

            return app(OrganizationFeatureGate::class)->isEnabled($organization, OrganizationFeature::ClientRecords)
                && app(OrganizationAuthorizer::class)->allows(
                    $actor,
                    $organization,
                    OrganizationPermission::ViewClients,
                )
                && app(OrganizationAuthorizer::class)->allows(
                    $actor,
                    $organization,
                    OrganizationPermission::ViewCompanionHistory,
                );
        } catch (LogicException) {
            return false;
        }
    }

    public function mount(): void
    {
        $clientId = request()->query('client');
        if (! is_string($clientId) || ! ctype_digit($clientId)) {
            return;
        }

        $client = Client::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey((int) $clientId)
            ->first();
        if ($client instanceof Client) {
            $this->selectedClientId = (int) $client->getKey();
            $this->mobileChatOpen = true;
        }
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $actor = Auth::user();
        $client = $this->selectedClient();

        return $schema->components(MessageComposer::make(
            bodyField: 'body',
            deliveryModeField: 'delivery_mode',
            mediaField: 'new_attachment',
            mediaUrlField: 'media_url',
            variables: null,
            preview: fn (Get $get, ?Model $record): NotificationMessage => $this->previewMessage($get),
            bodyLabel: 'Сообщение',
            bodyHelper: 'Текст отправится выбранному клиенту в доступный канал.',
            showDeliveryMode: false,
            includeMediaUrl: false,
            mediaAcceptedFileTypes: [
                'application/pdf',
                'text/plain',
                'image/jpeg',
                'image/png',
                'image/webp',
            ],
            mediaMaxKilobytes: (int) ceil((int) config('medical.attachment_max_bytes', 20_971_520) / 1024),
            mediaMultiple: false,
            mediaSelectionField: 'existing_attachment_id',
            mediaUsesCaptionLimit: true,
            mediaSectionTitle: 'Вложение',
            mediaSectionDescription: 'Один безопасный файл. Защищённые медицинские файлы здесь не показываются.',
            mediaUploadLabel: 'Добавить вложение',
            mediaHelperText: 'PDF, TXT, JPG, PNG или WebP; до 20 МБ.',
            additionalMediaComponents: [
                Select::make('existing_attachment_id')
                    ->label('Выбрать ранее загруженный файл')
                    ->options(fn (): array => $actor instanceof User
                        && $client instanceof Client
                        && $this->canManage($actor)
                        ? app(ListCompanionCommunicationAttachments::class)->options($actor, $client)
                        : [])
                    ->placeholder('Без файла')
                    ->native(false)
                    ->searchable(),
            ],
            compact: true,
        ))->statePath('data');
    }

    public function composer(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('messages-composer-form')
                ->dense()
                ->extraAttributes(['class' => 'messages-composer'])
                ->livewireSubmitHandler('sendMessage')
                ->footer([
                    Actions::make([
                        Action::make('sendMessage')->label('Отправить')->submit('sendMessage'),
                    ]),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Скачать историю')
                ->color('gray')
                ->visible(fn (): bool => $this->selectedClient() instanceof Client && $this->canExport())
                ->schema([
                    Select::make('format')
                        ->label('Формат')
                        ->options(['txt' => 'TXT', 'json' => 'JSON'])
                        ->default('txt')
                        ->required(),
                    Select::make('identity')
                        ->label('Данные клиента')
                        ->options(['identified' => 'Идентифицированные', 'pseudonymized' => 'Без прямых идентификаторов'])
                        ->default('identified')
                        ->required(),
                ])
                ->action(function (array $data): StreamedResponse {
                    $actor = Auth::user();
                    $client = $this->selectedClient();
                    abort_unless($actor instanceof User && $client instanceof Client, 404);
                    $format = (string) $data['format'];
                    $content = app(CompanionExportService::class)->history(
                        $actor,
                        $client,
                        $format,
                        (string) $data['identity'],
                    );

                    return response()->streamDownload(
                        static function () use ($content): void {
                            echo $content;
                        },
                        'client-companion.'.($format === 'txt' ? 'txt' : 'json'),
                        ['Content-Type' => $format === 'txt' ? 'text/plain; charset=UTF-8' : 'application/json; charset=UTF-8'],
                    );
                }),
            Action::make('technicalMetadata')
                ->label('Расширенные технические метаданные')
                ->color('gray')
                ->visible(fn (): bool => $this->selectedClient() instanceof Client && $this->canExportMetadata())
                ->modalHeading('Расширенные технические метаданные')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть')
                ->slideOver()
                ->modalContent(function (): View {
                    $actor = Auth::user();
                    $client = $this->selectedClient();
                    abort_unless($actor instanceof User && $client instanceof Client, 404);
                    $metadata = json_decode(
                        app(CompanionExportService::class)->metadata($actor, $client),
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );

                    return view('filament.pages.client-companion-metadata', [
                        'metadata' => is_array($metadata) ? $metadata : [],
                    ]);
                }),
        ];
    }

    public function selectClient(int $clientId): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $client = $this->clientQuery($clientId)->firstOrFail();

        $this->selectedClientId = (int) $client->getKey();
        $this->mobileChatOpen = true;
        $this->showClientInfo = false;
        $this->resetHistory();
        $this->resetComposer();
    }

    public function openClientInfo(): void
    {
        $this->showClientInfo = true;
    }

    public function closeClientInfo(): void
    {
        $this->showClientInfo = false;
    }

    public function backToDialogs(): void
    {
        $this->mobileChatOpen = false;
        $this->showClientInfo = false;
    }

    public function refreshWorkspace(): void
    {
        $this->syncHistory();
    }

    public function loadOlderMessages(): void
    {
        $actor = Auth::user();
        $client = $this->selectedClient();
        abort_unless($actor instanceof User && $client instanceof Client, 404);

        $beforeMessageId = $this->historyState['nextBeforeMessageId'] ?? null;
        if (! is_int($beforeMessageId) && ! (is_string($beforeMessageId) && ctype_digit($beforeMessageId))) {
            return;
        }

        $history = app(ReadCompanionConversation::class)->forStaff(
            $actor,
            $client,
            beforeMessageId: (int) $beforeMessageId,
        );
        $this->historyMessages = $this->mergeMessages($this->historyMessages, $history['messages']);
        $this->historyHasLoadedOlder = (bool) $history['hasOlder'];
        $this->historyState = $this->historyMetadata($history);
    }

    public function sendMessage(): void
    {
        $actor = Auth::user();
        $client = $this->selectedClient();
        abort_unless($actor instanceof User && $client instanceof Client, 404);

        $data = $this->form->getState();
        $body = RichTextDocument::canonicalHtmlFromState($data['body'] ?? null);
        $attachmentIds = [];
        if (filled($data['existing_attachment_id'] ?? null)) {
            $attachmentIds[] = (int) $data['existing_attachment_id'];
        }
        if (($data['new_attachment'] ?? null) instanceof UploadedFile) {
            $attachment = app(UploadCompanionCommunicationAttachment::class)->handle(
                $actor,
                $client,
                $data['new_attachment'],
            );
            $attachmentIds[] = (int) $attachment->getKey();
        }

        $channel = app(ReplyToCompanion::class)->handle($actor, $client, $body, $attachmentIds);
        $this->resetComposer();
        $this->syncHistory();

        if ($channel === 'telegram') {
            Notification::make()
                ->success()
                ->title('Сообщение принято к отправке в Telegram')
                ->body('Состояние доставки обновится в истории.')
                ->send();

            return;
        }

        Notification::make()
            ->info()
            ->title('Сообщение сохранено в истории')
            ->body('Telegram не подключён.')
            ->send();
    }

    public function resolveAndResume(): void
    {
        $actor = Auth::user();
        $client = $this->selectedClient();
        abort_unless($actor instanceof User && $client instanceof Client, 404);

        app(ResolveCompanionHandoff::class)->handleAndResume($actor, $client);
        $this->syncHistory();
    }

    public function resolve(): void
    {
        $actor = Auth::user();
        $client = $this->selectedClient();
        abort_unless($actor instanceof User && $client instanceof Client, 404);

        app(ResolveCompanionHandoff::class)->handle($actor, $client);
        $this->syncHistory();
    }

    public function resumeAi(): void
    {
        $actor = Auth::user();
        $client = $this->selectedClient();
        abort_unless($actor instanceof User && $client instanceof Client, 404);

        app(ResumeCompanionAi::class)->handle($actor, $client);
        $this->syncHistory();
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        $workspace = app(ReadCompanionWorkspace::class);
        $dialogs = $workspace->dialogs($actor, $this->search, $this->selectedClientId);
        $client = $this->selectedClient();
        if ($client instanceof Client) {
            $this->syncHistory($actor, $client);
        } else {
            $this->resetHistory();
        }

        $selectedDialog = collect($dialogs)->first(
            static fn (array $dialog): bool => $dialog['selected'] === true,
        );
        $summary = null;
        if ($client instanceof Client) {
            $summary = $workspace->clientSummary($actor, $client);
            $summary['urls'] = [
                'client' => ClientResource::getUrl('view', ['record' => $client]),
                'bookings' => BookingResource::getUrl('index'),
                'sessions' => ClientResource::getUrl('sessions', ['record' => $client]),
                'upcomingBooking' => is_array($summary['upcomingBooking'] ?? null)
                    ? BookingResource::getUrl('view', ['record' => $summary['upcomingBooking']['id']])
                    : null,
            ];
        }

        return [
            'dialogs' => $dialogs,
            'selectedClient' => $client,
            'selectedDialog' => $selectedDialog,
            'history' => $this->decoratedHistory(),
            'historyState' => $this->historyState,
            'clientSummary' => $summary,
            'canManage' => $this->canManage($actor),
            'mobileChatOpen' => $this->mobileChatOpen,
            'showClientInfo' => $this->showClientInfo,
        ];
    }

    private function previewMessage(Get $get): NotificationMessage
    {
        $body = RichTextDocument::canonicalHtmlFromState($get('body'));

        return new NotificationMessage(
            recipientExternalId: 'preview',
            body: $body,
            subject: null,
            locale: 'ru',
            idempotencyKey: 'messages-preview',
            mode: NotificationMessageMode::Text,
            mediaItems: [],
        );
    }

    private function canManage(User $actor): bool
    {
        return app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageCompanionHandoff,
        );
    }

    private function selectedClient(): ?Client
    {
        if ($this->selectedClientId === null) {
            return null;
        }

        return $this->clientQuery($this->selectedClientId)->first();
    }

    /** @return Builder<Client> */
    private function clientQuery(int $clientId): Builder
    {
        return Client::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey($clientId);
    }

    private function resetComposer(): void
    {
        $this->form->fill([
            'body' => null,
            'delivery_mode' => NotificationMessageMode::Text->value,
            'caption_position' => 'below',
            'existing_attachment_id' => null,
            'new_attachment' => null,
            'remove_media' => false,
        ]);
    }

    private function resetHistory(): void
    {
        $this->historyMessages = [];
        $this->historyState = [];
        $this->historyClientId = null;
        $this->historyHasLoadedOlder = false;
    }

    private function syncHistory(?User $actor = null, ?Client $client = null): void
    {
        $actor ??= Auth::user();
        $client ??= $this->selectedClient();
        if (! $actor instanceof User || ! $client instanceof Client) {
            $this->resetHistory();

            return;
        }

        if ($this->historyClientId !== (int) $client->getKey()) {
            $this->resetHistory();
            $this->historyClientId = (int) $client->getKey();
        }

        $previousCursor = $this->historyState['nextBeforeMessageId'] ?? null;
        $history = app(ReadCompanionConversation::class)->forStaff($actor, $client);
        $this->historyMessages = $this->mergeMessages($this->historyMessages, $history['messages']);
        if (! $this->historyHasLoadedOlder) {
            $this->historyHasLoadedOlder = (bool) $history['hasOlder'];
        }
        $this->historyState = $this->historyMetadata($history);
        if ($this->historyHasLoadedOlder && $previousCursor !== null) {
            $this->historyState['nextBeforeMessageId'] = $previousCursor;
        }
    }

    private function canExport(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ExportCompanionHistory,
        );
    }

    private function canExportMetadata(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ExportCompanionMetadata,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeMessages(array $current, array $incoming): array
    {
        $messages = [];
        foreach (array_merge($current, $incoming) as $message) {
            $messages[(string) $message['id']] = $message;
        }

        $messages = array_values($messages);
        usort($messages, static fn (array $left, array $right): int => [
            (string) $left['occurredAt'],
            (string) $left['id'],
        ] <=> [
            (string) $right['occurredAt'],
            (string) $right['id'],
        ]);

        return $messages;
    }

    /** @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function historyMetadata(array $history): array
    {
        return [
            'state' => $history['state'] ?? 'ai_active',
            'stateLabel' => $history['stateLabel'] ?? 'AI отвечает',
            'mode' => $history['mode'] ?? 'ai_active',
            'pending' => (bool) ($history['pending'] ?? false),
            'hasOlder' => (bool) ($history['hasOlder'] ?? false),
            'nextBeforeMessageId' => $history['nextBeforeMessageId'] ?? null,
            'openEscalation' => $history['openEscalation'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function decoratedHistory(): array
    {
        $timezone = app(OrganizationContext::class)->defaultTimezone();
        $today = CarbonImmutable::now($timezone);
        $yesterday = $today->copy()->subDay();
        $previousDate = null;
        $messages = [];

        foreach ($this->historyMessages as $message) {
            $date = CarbonImmutable::parse((string) $message['occurredAt'])->setTimezone($timezone);
            $dateKey = $date->toDateString();
            $message['showDate'] = $previousDate !== $dateKey;
            $message['dateLabel'] = $this->dateLabel($date, $today, $yesterday);
            $message['timeLabel'] = $date->format('H:i');
            $messages[] = $message;
            $previousDate = $dateKey;
        }

        return $messages;
    }

    private function dateLabel(CarbonImmutable $date, CarbonImmutable $today, CarbonImmutable $yesterday): string
    {
        if ($date->isSameDay($today)) {
            return 'Сегодня';
        }
        if ($date->isSameDay($yesterday)) {
            return 'Вчера';
        }

        $months = [
            1 => 'января',
            2 => 'февраля',
            3 => 'марта',
            4 => 'апреля',
            5 => 'мая',
            6 => 'июня',
            7 => 'июля',
            8 => 'августа',
            9 => 'сентября',
            10 => 'октября',
            11 => 'ноября',
            12 => 'декабря',
        ];

        return $date->format('j').' '.$months[(int) $date->format('n')];
    }
}
