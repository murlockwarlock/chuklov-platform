<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\RichTextEditor;
use App\Models\User;
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
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ClientCompanionHistory extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected string $view = 'filament.pages.client-companion-history';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public function getTitle(): string
    {
        return 'Общение с клиентом';
    }

    public function form(Schema $schema): Schema
    {
        $actor = Auth::user();
        $client = $this->getRecord();

        return $schema
            ->components([
                RichTextEditor::make('body')
                    ->label('Сообщение')
                    ->helperText('Можно использовать жирный, курсив, ссылки, списки и эмодзи.')
                    ->required()
                    ->columnSpanFull(),
                Select::make('existing_attachment_id')
                    ->label('Файл из разрешённых для отправки')
                    ->options(fn (): array => $actor instanceof User && $client instanceof Client
                        ? app(ListCompanionCommunicationAttachments::class)->options($actor, $client)
                        : [])
                    ->placeholder('Без файла')
                    ->native(false)
                    ->searchable()
                    ->helperText('Защищённые медицинские файлы здесь не показываются.'),
                FileUpload::make('new_attachment')
                    ->label('Загрузить файл для сообщения')
                    ->acceptedFileTypes([
                        'application/pdf',
                        'text/plain',
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                    ])
                    ->maxSize((int) ceil((int) config('medical.attachment_max_bytes', 20_971_520) / 1024))
                    ->storeFiles(false)
                    ->helperText('Только PDF, TXT, JPG, PNG или WebP. Один файл до 20 МБ.'),
            ])
            ->statePath('data');
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
        ];
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
                'export' => route('admin.clients.companion.export', ['client' => $client]),
                'metadataExport' => route('admin.clients.companion.metadata-export', ['client' => $client]),
            ],
        ];
    }
}
