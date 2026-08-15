<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Identity\Application\BlockClientSelfBooking;
use App\Modules\Identity\Application\UnblockClientSelfBooking;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->label('Имя')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable()->placeholder('—'),
                TextColumn::make('phone')->label('Телефон')->placeholder('—'),
                TextColumn::make('language')
                    ->label('Язык')
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский')
                    ->sortable(),
                TextColumn::make('timezone')
                    ->label('Часовой пояс')
                    ->formatStateUsing(fn (?string $state): string => TimezoneOptions::label($state))
                    ->sortable(),
                TextColumn::make('channel_identities_count')->label('Способы связи')->sortable(),
                IconColumn::make('activeBookingRestriction')
                    ->label('Самостоятельная запись заблокирована')
                    ->boolean()
                    ->state(fn (Client $record): bool => $record->activeBookingRestriction !== null),
            ])
            ->filters([
                TernaryFilter::make('activeBookingRestriction')
                    ->label('Самостоятельная запись заблокирована')
                    ->queries(
                        true: fn ($query) => $query->whereHas('activeBookingRestriction'),
                        false: fn ($query) => $query->whereDoesntHave('activeBookingRestriction'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('editMedicalProfile')
                    ->label('Медицинский профиль')
                    ->icon('heroicon-o-heart')
                    ->fillForm(function (Client $record): array {
                        $actor = auth()->user();
                        if (! $actor instanceof User) {
                            return [];
                        }

                        $profile = app(GetMedicalProfile::class)->handle($actor, $record);

                        return [
                            'anamnesis' => $profile?->anamnesis,
                            'complaints_goals' => $profile?->complaintsGoals,
                            'operations_injuries' => $profile?->operationsInjuries,
                            'medicines' => $profile?->medicines,
                            'supplements' => $profile?->supplements,
                        ];
                    })
                    ->schema([
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
                    ])
                    ->action(function (Client $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        $command = UpdateMedicalProfileCommand::fromArray($data);
                        app(UpdateMedicalProfile::class)->handle($actor, $record, $command);
                    }),
                Action::make('uploadAttachment')
                    ->label('Загрузить документ')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->schema([
                        Select::make('attachment_type')
                            ->label('Тип документа')
                            ->options([
                                AttachmentType::MedicalReport->value => AttachmentType::MedicalReport->label(),
                                AttachmentType::PosturePhoto->value => AttachmentType::PosturePhoto->label(),
                            ])
                            ->required(),
                        FileUpload::make('file')
                            ->label('Файл документа')
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (Client $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(UploadMedicalAttachment::class)->handle(
                            actor: $actor,
                            command: new AttachmentUploadCommand(
                                file: $data['file'],
                                attachmentType: AttachmentType::from($data['attachment_type']),
                                clientId: (int) $record->getKey(),
                            ),
                        );
                    }),
                Action::make('blockSelfBooking')
                    ->label('Запретить самостоятельную запись')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->visible(fn (Client $record): bool => $record->activeBookingRestriction === null)
                    ->action(function (Client $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(BlockClientSelfBooking::class)->handle($actor, $record, $data['reason']);
                    }),
                Action::make('unblockSelfBooking')
                    ->label('Разрешить самостоятельную запись')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Client $record): bool => $record->activeBookingRestriction !== null)
                    ->action(function (Client $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(UnblockClientSelfBooking::class)->handle($actor, $record);
                    }),
            ]);
    }
}
