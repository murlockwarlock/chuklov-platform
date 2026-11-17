<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Support\ClientAttachmentUploadFailureReporter;
use App\Filament\Support\LocalizedRelationManager;
use App\Models\User;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Application\DeleteMedicalAttachment;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Application\ListClientAttachments;
use App\Modules\Attachments\Application\MedicalFilePolicy;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Throwable;

final class ClientGeneralFilesRelationManager extends LocalizedRelationManager
{
    protected static string $relationship = 'medicalAttachments';

    protected static ?string $title = 'Файлы';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $ownerRecord instanceof Client
            && app(AttachmentAuthorization::class)->allowsView($actor, $ownerRecord);
    }

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $client = $this->getOwnerRecord();

        abort_unless($actor instanceof User, 403);
        abort_unless($client instanceof Client, 404);

        $canManage = app(AttachmentAuthorization::class)->allowsUpload($actor, $client);
        $policy = app(MedicalFilePolicy::class);

        return $table
            ->heading(__('Файлы'))
            ->stackedOnMobile()
            ->modifyQueryUsing(
                fn (Builder $query): Builder => app(ListClientAttachments::class)
                    ->query($actor, $client)
                    ->where('attachment_type', AttachmentType::GeneralFile),
            )
            ->columns([
                TextColumn::make('presentation_name')
                    ->label(__('Файл'))
                    ->limit(48)
                    ->wrap(),
                TextColumn::make('mime_type')
                    ->label(__('Формат'))
                    ->formatStateUsing(static fn (string $state): string => strtoupper((string) str($state)->afterLast('/')))
                    ->visibleFrom('sm'),
                TextColumn::make('size_bytes')
                    ->label(__('Размер'))
                    ->formatStateUsing(fn (int|string $state): string => self::formatBytes((int) $state))
                    ->visibleFrom('sm'),
                TextColumn::make('uploadedBy.name')
                    ->label(__('Загрузил'))
                    ->placeholder(__('Клиент'))
                    ->visibleFrom('md'),
                TextColumn::make('created_at')
                    ->label(__('Дата'))
                    ->dateTime('d.m.Y H:i')
                    ->visibleFrom('md'),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label(__('Загрузить файл'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->schema([
                        TextInput::make('display_name')
                            ->label(__('Понятное название'))
                            ->helperText(__('Например: «Видео осмотра 17.11.2026».'))
                            ->maxLength(255),
                        FileUpload::make('file')
                            ->label(__('Файл'))
                            ->acceptedFileTypes(MedicalFilePolicy::allowedMimeTypes())
                            ->maxSize((int) ceil($policy->maxBytes((int) $client->organization_id) / 1024))
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->visible($canManage)
                    ->authorize($canManage)
                    ->action(function (array $data) use ($actor, $client): void {
                        abort_unless(($data['file'] ?? null) instanceof UploadedFile, 422);

                        try {
                            app(UploadMedicalAttachment::class)->handle(
                                actor: $actor,
                                command: new AttachmentUploadCommand(
                                    file: $data['file'],
                                    clientId: (int) $client->getKey(),
                                    attachmentType: AttachmentType::GeneralFile,
                                    displayName: filled($data['display_name'] ?? null) ? (string) $data['display_name'] : null,
                                ),
                            );
                        } catch (Throwable $exception) {
                            app(ClientAttachmentUploadFailureReporter::class)->handle($exception, $actor, $client);

                            return;
                        }

                        $this->flushCachedTableRecords();
                        Notification::make()->title(__('Файл загружен'))->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('Просмотр'))
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Закрыть'))
                    ->modalWidth('7xl')
                    ->modalContent(fn (MedicalAttachment $record): View => view('filament.resources.clients.attachment-preview', [
                        'url' => app(GetTemporaryAttachmentUrl::class)->handlePreview($actor, $record),
                        'filename' => $record->original_filename,
                        'mimeType' => strtolower($record->mime_type),
                    ]))
                    ->visible(fn (MedicalAttachment $record): bool => GetTemporaryAttachmentUrl::supportsPreview($record->mime_type)),
                Action::make('download')
                    ->label(__('Скачать'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (MedicalAttachment $record): mixed => redirect()->to(
                        app(GetTemporaryAttachmentUrl::class)->handle($actor, $record),
                    )),
                Action::make('delete')
                    ->label(__('Удалить'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible($canManage)
                    ->authorize($canManage)
                    ->action(function (MedicalAttachment $record) use ($actor): void {
                        app(DeleteMedicalAttachment::class)->handle($actor, $record);
                        $this->flushCachedTableRecords();
                        Notification::make()->title(__('Файл удалён'))->success()->send();
                    }),
            ])
            ->paginated([10, 25])
            ->emptyStateHeading(__('Файлов пока нет'))
            ->emptyStateDescription(__('Загрузите дополнительные медицинские файлы или короткие видео.'));
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' Б';
        }
        if ($bytes < 1024 * 1024) {
            return (string) round($bytes / 1024).' КБ';
        }

        return number_format($bytes / 1024 / 1024, 1, ',', ' ').' МБ';
    }
}
