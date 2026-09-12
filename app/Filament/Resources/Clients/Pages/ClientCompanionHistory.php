<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\MessageComposer;
use App\Models\User;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationMedia;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\ClientCompanion\Application\Actions\ReplyToCompanion;
use App\Modules\ClientCompanion\Application\Actions\UploadCompanionCommunicationAttachment;
use App\Modules\ClientCompanion\Application\Services\CompanionExportService;
use App\Modules\ClientCompanion\Application\Services\ListCompanionCommunicationAttachments;
use App\Modules\ClientCompanion\Application\Services\ReadCompanionConversation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Support\RichText\RichTextDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ClientCompanionHistory extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected string $view = 'filament.pages.client-companion-history';

    /** @var array<string, mixed> */
    public ?array $data = [
        'body' => null,
        'delivery_mode' => NotificationMessageMode::Text->value,
        'caption_position' => 'below',
        'existing_attachment_id' => null,
        'new_attachment' => null,
        'remove_media' => false,
    ];

    public function getTitle(): string
    {
        return 'Общение с клиентом';
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
        $client = $this->getRecord();

        return $schema->components(MessageComposer::make(
            bodyField: 'body',
            deliveryModeField: 'delivery_mode',
            mediaField: 'new_attachment',
            mediaUrlField: 'media_url',
            variables: null,
            preview: fn (Get $get, ?Model $record): NotificationMessage => $this->companionPreview($get, $actor, $client),
            bodyLabel: 'Сообщение',
            bodyHelper: 'Можно использовать жирный, курсив, ссылки, списки и эмодзи. Без вложения — до 4096 символов; с фото или файлом — подпись до 1024.',
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
            mediaSectionDescription: 'Можно добавить один безопасный файл. Защищённые медицинские файлы здесь не показываются.',
            mediaUploadLabel: 'Загрузить файл для сообщения',
            mediaHelperText: 'Только PDF, TXT, JPG, PNG или WebP. Один файл до 20 МБ.',
            additionalMediaComponents: [
                Select::make('existing_attachment_id')
                    ->label('Или выбрать уже разрешённый файл')
                    ->options(fn (): array => $actor instanceof User && $client instanceof Client
                        ? app(ListCompanionCommunicationAttachments::class)->options($actor, $client)
                        : [])
                    ->placeholder('Без файла')
                    ->native(false)
                    ->searchable()
                    ->helperText('Защищённые медицинские файлы здесь не показываются.'),
            ],
        ))->statePath('data');
    }

    public function composer(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('companion-composer-form')
                ->livewireSubmitHandler('sendReply')
                ->footer([
                    Actions::make([
                        Action::make('sendReply')->label('Отправить сообщение')->submit('sendReply'),
                    ]),
                ]),
        ]);
    }

    public function sendReply(): void
    {
        $actor = Auth::user();
        $client = $this->getRecord();
        abort_unless($actor instanceof User && $client instanceof Client, 403);

        $data = $this->form->getState();
        $body = RichTextDocument::canonicalHtmlFromState($data['body'] ?? null);
        $attachmentIds = [];
        if (filled($data['existing_attachment_id'] ?? null)) {
            $attachmentIds[] = (int) $data['existing_attachment_id'];
        }
        if (($data['new_attachment'] ?? null) instanceof UploadedFile) {
            $attachment = app(UploadCompanionCommunicationAttachment::class)->handle($actor, $client, $data['new_attachment']);
            $attachmentIds[] = (int) $attachment->getKey();
        }

        app(ReplyToCompanion::class)->handle($actor, $client, $body, $attachmentIds);
        $this->form->fill();
        Notification::make()->success()->title('Сообщение отправлено')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Скачать историю')
                ->color('gray')
                ->visible(fn (): bool => $this->canExport())
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
                    $client = $this->getRecord();
                    abort_unless($actor instanceof User && $client instanceof Client, 403);
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
                ->label('Дополнительные сведения')
                ->color('gray')
                ->visible(fn (): bool => $this->canExportMetadata())
                ->modalHeading('Дополнительные сведения')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть')
                ->slideOver()
                ->modalContent(function (): View {
                    $actor = Auth::user();
                    $client = $this->getRecord();
                    abort_unless($actor instanceof User && $client instanceof Client, 403);
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

    private function companionPreview(Get $get, ?User $actor, ?Client $client): NotificationMessage
    {
        $body = RichTextDocument::canonicalHtmlFromState($get('body'));
        $mediaItems = $this->previewMedia($get, $actor, $client);
        $mode = $mediaItems === []
            ? NotificationMessageMode::Text
            : ($body === '' ? NotificationMessageMode::Image : NotificationMessageMode::ImageWithCaption);

        return new NotificationMessage(
            recipientExternalId: 'preview',
            body: $mode->includesText() ? $body : '',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'companion-preview',
            mode: $mode,
            mediaItems: $mediaItems,
        );
    }

    /** @return list<NotificationMedia> */
    private function previewMedia(Get $get, ?User $actor, ?Client $client): array
    {
        $upload = $get('new_attachment');
        if ($upload instanceof UploadedFile) {
            $type = $this->mediaType($upload->getMimeType());
            $url = null;
            if (method_exists($upload, 'temporaryUrl')) {
                try {
                    $temporaryUrl = $upload->temporaryUrl();
                    $url = is_string($temporaryUrl) && trim($temporaryUrl) !== '' ? trim($temporaryUrl) : null;
                } catch (\Throwable) {
                    $url = null;
                }
            }

            return [new NotificationMedia(
                type: $type,
                url: $url,
                fileName: $upload->getClientOriginalName() ?: null,
            )];
        }

        $attachmentId = $get('existing_attachment_id');
        if (! $actor instanceof User || ! $client instanceof Client || ! filled($attachmentId)) {
            return [];
        }

        $attachment = app(ListCompanionCommunicationAttachments::class)
            ->query($actor, $client)
            ->whereKey((int) $attachmentId)
            ->first();
        if (! $attachment instanceof MedicalAttachment) {
            return [];
        }

        return [new NotificationMedia(
            type: $attachment->attachment_type === AttachmentType::CompanionImage ? 'photo' : 'document',
            url: app(GetTemporaryAttachmentUrl::class)->handle($actor, $attachment),
            fileName: $attachment->original_filename,
        )];
    }

    private function mediaType(?string $mimeType): string
    {
        return str_starts_with(strtolower((string) $mimeType), 'image/') ? 'photo' : 'document';
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

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $actor = Auth::user();
        $client = $this->getRecord();
        abort_unless($actor instanceof User && $client instanceof Client, 404);

        return [
            'client' => $client,
            'companion' => app(ReadCompanionConversation::class)->forStaff($actor, $client, beforeMessageId: request()->integer('before') ?: null),
            'canManage' => app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ManageCompanionHandoff,
            ),
            'canExport' => app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ExportCompanionHistory,
            ),
            'canExportMetadata' => app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ExportCompanionMetadata,
            ),
            'urls' => [
                'reply' => route('admin.clients.companion.reply', ['client' => $client]),
                'resolve' => route('admin.clients.companion.resolve', ['client' => $client]),
                'resolveAndResume' => route('admin.clients.companion.resolve-and-resume', ['client' => $client]),
                'resume' => route('admin.clients.companion.resume', ['client' => $client]),
                'reset' => route('admin.clients.companion.reset', ['client' => $client]),
                'history' => ClientResource::getUrl('companion', ['record' => $client]),
            ],
        ];
    }
}
