<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Models\User;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Application\ListClientAttachments;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentScanStatus;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

final class ClientAttachmentsRelationManager extends RelationManager
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

        return $table
            ->heading('Файлы и МРТ')
            ->stackedOnMobile()
            ->modifyQueryUsing(
                fn (Builder $query): Builder => app(ListClientAttachments::class)->query($actor, $client),
            )
            ->columns([
                TextColumn::make('original_filename')->label('Файл')->limit(48)->wrap(),
                TextColumn::make('attachment_type')
                    ->label('Тип')
                    ->formatStateUsing(fn (AttachmentType|string $state): string => $state instanceof AttachmentType
                        ? $state->label()
                        : (AttachmentType::tryFrom($state)?->label() ?? 'Файл'))
                    ->wrap(),
                TextColumn::make('mime_type')->label('Формат')->visibleFrom('sm'),
                TextColumn::make('size_bytes')
                    ->label('Размер')
                    ->formatStateUsing(fn (int|string $state): string => self::formatBytes((int) $state))
                    ->visibleFrom('sm'),
                TextColumn::make('scan_status')
                    ->label('Проверка')
                    ->badge()
                    ->formatStateUsing(fn (AttachmentScanStatus|string $state): string => $state instanceof AttachmentScanStatus
                        ? $state->label()
                        : (AttachmentScanStatus::tryFrom($state)?->label() ?? 'Неизвестно'))
                    ->color(fn (AttachmentScanStatus|string $state): string => self::scanStatusColor($state))
                    ->description(fn (AttachmentScanStatus|string $state): string => self::scanStatusDescription($state))
                    ->wrap(),
                TextColumn::make('created_at')->label('Загружен')->dateTime('d.m.Y H:i')->visibleFrom('sm'),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label('Загрузить файл')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->schema([
                        Select::make('attachment_type')
                            ->label('Тип файла')
                            ->options([
                                AttachmentType::MedicalReport->value => AttachmentType::MedicalReport->label(),
                                AttachmentType::PosturePhoto->value => AttachmentType::PosturePhoto->label(),
                            ])
                            ->required(),
                        FileUpload::make('file')
                            ->label('Файл')
                            ->acceptedFileTypes([
                                'application/pdf',
                                'text/plain',
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                            ])
                            ->maxSize((int) ceil((int) config('medical.attachment_max_bytes', 20_971_520) / 1024))
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (array $data) use ($actor, $client): void {
                        abort_unless($data['file'] instanceof UploadedFile, 422);

                        $attachment = app(UploadMedicalAttachment::class)->handle(
                            actor: $actor,
                            command: new AttachmentUploadCommand(
                                file: $data['file'],
                                attachmentType: AttachmentType::from((string) $data['attachment_type']),
                                clientId: (int) $client->getKey(),
                            ),
                        );

                        self::sendUploadNotification($attachment);
                    })
                    ->visible(fn (): bool => app(AttachmentAuthorization::class)->allowsUpload($actor, $client)),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Открыть')
                    ->visible(fn (MedicalAttachment $record): bool => $record->isAvailable())
                    ->action(function (MedicalAttachment $record) use ($actor): mixed {
                        return redirect()->to(app(GetTemporaryAttachmentUrl::class)->handle($actor, $record));
                    }),
            ])
            ->paginated([10, 25])
            ->emptyStateHeading('Файлов пока нет')
            ->emptyStateDescription('Загрузите медицинское заключение или фото осанки.');
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' Б';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' КБ';
        }

        return round($bytes / (1024 * 1024), 1).' МБ';
    }

    private static function scanStatus(AttachmentScanStatus|string $status): AttachmentScanStatus
    {
        return $status instanceof AttachmentScanStatus
            ? $status
            : (AttachmentScanStatus::tryFrom($status) ?? AttachmentScanStatus::Pending);
    }

    private static function scanStatusColor(AttachmentScanStatus|string $status): string
    {
        return match (self::scanStatus($status)) {
            AttachmentScanStatus::Cleared => 'success',
            AttachmentScanStatus::Rejected => 'danger',
            AttachmentScanStatus::Pending, AttachmentScanStatus::Quarantined => 'warning',
        };
    }

    private static function scanStatusDescription(AttachmentScanStatus|string $status): string
    {
        return match (self::scanStatus($status)) {
            AttachmentScanStatus::Cleared => 'Доступен для открытия.',
            AttachmentScanStatus::Pending => 'Ожидает проверки.',
            AttachmentScanStatus::Quarantined => 'Доступ закрыт до проверки.',
            AttachmentScanStatus::Rejected => 'Заблокирован после проверки.',
        };
    }

    private static function sendUploadNotification(MedicalAttachment $attachment): void
    {
        $notification = Notification::make()
            ->title(match ($attachment->scan_status) {
                AttachmentScanStatus::Cleared => 'Файл загружен',
                AttachmentScanStatus::Pending => 'Файл принят на проверку',
                AttachmentScanStatus::Quarantined => 'Файл помещён на карантин',
                AttachmentScanStatus::Rejected => 'Файл отклонён',
            })
            ->body(self::scanStatusDescription($attachment->scan_status));

        match ($attachment->scan_status) {
            AttachmentScanStatus::Cleared => $notification->success(),
            AttachmentScanStatus::Rejected => $notification->danger(),
            AttachmentScanStatus::Pending, AttachmentScanStatus::Quarantined => $notification->warning(),
        };

        $notification->send();
    }
}
