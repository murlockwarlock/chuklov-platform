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

final class ClientAudioRecordingsRelationManager extends LocalizedRelationManager
{
    protected static string $relationship = 'medicalAttachments';

    protected static ?string $title = 'Аудиозаписи';

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

        return $table
            ->heading(__('Аудиозаписи'))
            ->stackedOnMobile()
            ->modifyQueryUsing(
                fn (Builder $query): Builder => app(ListClientAttachments::class)
                    ->query($actor, $client)
                    ->where('attachment_type', AttachmentType::AudioRecording),
            )
            ->columns([
                TextColumn::make('presentation_name')
                    ->label(__('Название'))
                    ->limit(48)
                    ->wrap(),
                TextColumn::make('size_bytes')
                    ->label(__('Размер'))
                    ->formatStateUsing(fn (int|string $state): string => self::formatBytes((int) $state))
                    ->visibleFrom('sm'),
                TextColumn::make('created_at')
                    ->label(__('Загружено'))
                    ->dateTime('d.m.Y H:i')
                    ->visibleFrom('md'),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label(__('Загрузить аудиозапись'))
                    ->icon('heroicon-o-microphone')
                    ->schema([
                        TextInput::make('display_name')
                            ->label(__('Название записи'))
                            ->helperText(__('Например: «Сеанс 12.10.2026».'))
                            ->maxLength(255)
                            ->required(),
                        FileUpload::make('file')
                            ->label(__('Аудиофайл'))
                            ->acceptedFileTypes(self::audioMimeTypes())
                            ->maxSize((int) ceil((int) config('medical.audio_attachment_max_bytes', 536_870_912) / 1024))
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->visible($canManage)
                    ->authorize($canManage)
                    ->action(function (array $data) use ($actor, $client): void {
                        abort_unless($data['file'] instanceof UploadedFile, 422);

                        try {
                            app(UploadMedicalAttachment::class)->handle(
                                actor: $actor,
                                command: new AttachmentUploadCommand(
                                    file: $data['file'],
                                    clientId: (int) $client->getKey(),
                                    attachmentType: AttachmentType::AudioRecording,
                                    displayName: (string) $data['display_name'],
                                ),
                            );
                        } catch (Throwable $exception) {
                            app(ClientAttachmentUploadFailureReporter::class)->handle($exception, $actor, $client);

                            return;
                        }

                        $this->flushCachedTableRecords();
                        Notification::make()->title(__('Аудиозапись загружена'))->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('play')
                    ->label(__('Воспроизвести'))
                    ->icon('heroicon-o-play-circle')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Закрыть'))
                    ->modalWidth('xl')
                    ->modalContent(fn (MedicalAttachment $record): View => view('filament.resources.clients.audio-preview', [
                        'url' => app(GetTemporaryAttachmentUrl::class)->handlePreview($actor, $record),
                        'filename' => $record->display_name ?: $record->original_filename,
                    ])),
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
                        Notification::make()->title(__('Аудиозапись удалена'))->success()->send();
                    }),
            ])
            ->paginated([10, 25])
            ->emptyStateHeading(__('Аудиозаписей пока нет'))
            ->emptyStateDescription(__('Загрузите запись сеанса для внутреннего использования.'));
    }

    /** @return list<string> */
    private static function audioMimeTypes(): array
    {
        return [
            'audio/mpeg',
            'audio/mp4',
            'audio/x-m4a',
            'audio/wav',
            'audio/x-wav',
            'audio/ogg',
            'audio/webm',
        ];
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
