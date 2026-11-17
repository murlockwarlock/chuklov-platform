<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Support\LocalizedRelationManager;
use App\Models\User;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Application\DeleteMedicalAttachment;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\ListClientSessionPhotos;
use App\Modules\Sessions\Domain\Models\MedicalSessionAttachment;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;

final class ClientSessionPhotosRelationManager extends LocalizedRelationManager
{
    /** @var array<int, int>|null */
    private ?array $sessionNumbers = null;

    protected static string $relationship = 'medicalAttachments';

    protected static ?string $title = 'Фото';

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
            ->heading(__('Фото сеансов'))
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query): Builder => app(ListClientSessionPhotos::class)->query($actor, $client))
            ->filters([
                SelectFilter::make('plane')
                    ->label(__('Направление'))
                    ->options(ListClientSessionPhotos::planeOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $filtered): Builder => $filtered->whereHas(
                            'sessionLinks',
                            fn (Builder $links): Builder => $links->where('plane', (string) $data['value']),
                        ),
                    )),
            ])
            ->columns([
                TextColumn::make('presentation_name')
                    ->label(__('Фото'))
                    ->limit(42)
                    ->wrap(),
                TextColumn::make('session_area')
                    ->label(__('Сеанс и зона'))
                    ->state(fn (MedicalAttachment $record): string => $this->sessionAreas($record))
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
                Action::make('downloadArchive')
                    ->label(__('Скачать все фото'))
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->url(fn (): string => route('admin.clients.session-photo-archive', ['client' => $client->getKey()]))
                    ->openUrlInNewTab(),
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
                    ->label(__('Скачать оригинал'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (MedicalAttachment $record): mixed => redirect()->to(
                        app(GetTemporaryAttachmentUrl::class)->handle($actor, $record),
                    )),
                Action::make('compare')
                    ->label(__('Сравнить до/после'))
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Закрыть'))
                    ->modalWidth('7xl')
                    ->modalContent(function (MedicalAttachment $record) use ($actor): View {
                        $comparison = $this->comparison($record);

                        return view('filament.resources.clients.session-photo-comparison', [
                            'photos' => [
                                'before' => [
                                    'preview_url' => app(GetTemporaryAttachmentUrl::class)->handlePreview($actor, $comparison['before']),
                                    'download_url' => app(GetTemporaryAttachmentUrl::class)->handle($actor, $comparison['before']),
                                ],
                                'after' => [
                                    'preview_url' => app(GetTemporaryAttachmentUrl::class)->handlePreview($actor, $comparison['after']),
                                    'download_url' => app(GetTemporaryAttachmentUrl::class)->handle($actor, $comparison['after']),
                                ],
                            ],
                        ]);
                    })
                    ->visible(fn (MedicalAttachment $record): bool => $this->hasComparison($record)),
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
                    }),
            ])
            ->paginated([10, 25])
            ->emptyStateHeading(__('Фотографий пока нет'))
            ->emptyStateDescription(__('Фотографии появятся после загрузки в сеанс. Они доступны только сотрудникам CRM.'));
    }

    private function sessionAreas(MedicalAttachment $record): string
    {
        $values = $record->sessionLinks
            ->map(function (MedicalSessionAttachment $link): string {
                $date = $link->session?->occurred_at?->format('d.m.Y') ?? '—';
                $moment = $link->gallery?->label() ?? 'Фото';
                $sessionNumber = $this->sessionNumber($link->medical_session_id);
                $plane = $link->plane?->label() ?? 'Направление не указано';

                return $moment.' '.($sessionNumber ?? '—').' · '.$plane.' · '.$date;
            })
            ->filter()
            ->values()
            ->all();

        return $values === [] ? '—' : implode(' | ', $values);
    }

    private function sessionNumber(int $sessionId): ?int
    {
        if ($this->sessionNumbers === null) {
            $client = $this->getOwnerRecord();
            if (! $client instanceof Client) {
                return null;
            }

            $this->sessionNumbers = MedicalSession::query()
                ->where('organization_id', $client->organization_id)
                ->where('client_id', $client->getKey())
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->pluck('id')
                ->mapWithKeys(static fn (int|string $id, int $index): array => [(int) $id => $index + 1])
                ->all();
        }

        return $this->sessionNumbers[$sessionId] ?? null;
    }

    /** @return array{before: MedicalAttachment, after: MedicalAttachment} */
    private function comparison(MedicalAttachment $record): array
    {
        $link = MedicalSessionAttachment::query()
            ->where('organization_id', $record->organization_id)
            ->where('client_id', $record->client_id)
            ->where('medical_attachment_id', $record->getKey())
            ->whereIn('gallery', ['before', 'after'])
            ->whereNotNull('plane')
            ->firstOrFail();
        $opposite = $link->gallery->value === 'before' ? 'after' : 'before';
        $counterpartLink = MedicalSessionAttachment::query()
            ->where('organization_id', $record->organization_id)
            ->where('client_id', $record->client_id)
            ->where('medical_session_id', $link->medical_session_id)
            ->where('plane', $link->plane->value)
            ->where('gallery', $opposite)
            ->firstOrFail();
        $attachments = MedicalAttachment::query()
            ->where('organization_id', $record->organization_id)
            ->whereIn('id', [$link->medical_attachment_id, $counterpartLink->medical_attachment_id])
            ->get()
            ->keyBy('id');

        $before = $attachments->get($link->gallery->value === 'before'
            ? $link->medical_attachment_id
            : $counterpartLink->medical_attachment_id);
        $after = $attachments->get($link->gallery->value === 'before'
            ? $counterpartLink->medical_attachment_id
            : $link->medical_attachment_id);
        if (! $before instanceof MedicalAttachment || ! $after instanceof MedicalAttachment) {
            throw new \RuntimeException('The before/after photo pair is incomplete.');
        }

        return ['before' => $before, 'after' => $after];
    }

    private function hasComparison(MedicalAttachment $record): bool
    {
        try {
            $this->comparison($record);

            return true;
        } catch (\Throwable) {
            return false;
        }
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
