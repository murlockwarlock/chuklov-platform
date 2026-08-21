<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\LinkSessionAttachment;
use App\Modules\Sessions\Application\ListSessionAttachments;
use App\Modules\Sessions\Application\SearchClientAttachments;
use App\Modules\Sessions\Application\UnlinkSessionAttachment;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewMedicalSession extends ViewRecord
{
    protected static string $resource = MedicalSessionResource::class;

    protected static ?string $title = 'Сеанс';

    protected static ?string $breadcrumb = 'Сеанс';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToSessions')
                ->label('К истории сеансов')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => ClientResource::getUrl('sessions', ['record' => $this->getParentRecord()])),
            Action::make('linkAttachment')
                ->label('Связать файл')
                ->icon('heroicon-o-paper-clip')
                ->schema([
                    Select::make('attachment_id')
                        ->label('Файл клиента')
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->preload()
                        ->optionsLimit(50)
                        ->options(fn (): array => app(SearchClientAttachments::class)
                            ->handle($this->actor(), $this->client(), ''))
                        ->getSearchResultsUsing(fn (string $search): array => app(SearchClientAttachments::class)
                            ->handle($this->actor(), $this->client(), $search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value)
                            ? app(SearchClientAttachments::class)->label($this->actor(), $this->client(), (int) $value)
                            : null),
                ])
                ->modalSubmitActionLabel('Связать')
                ->action(function (array $data): void {
                    app(LinkSessionAttachment::class)->handle(
                        $this->actor(),
                        $this->session(),
                        $this->client(),
                        (int) $data['attachment_id'],
                    );

                    Notification::make()->success()->title('Файл связан с сеансом')->send();
                })
                ->visible(fn (): bool => MedicalSessionResource::canEdit($this->getRecord())),
            Action::make('unlinkAttachment')
                ->label('Отвязать файл')
                ->icon('heroicon-o-link-slash')
                ->color('gray')
                ->schema([
                    Select::make('attachment_id')
                        ->label('Связанный файл')
                        ->required()
                        ->native(false)
                        ->options(fn (): array => collect(app(ListSessionAttachments::class)
                            ->handle($this->actor(), $this->session(), $this->client()))
                            ->mapWithKeys(fn ($attachment): array => [$attachment->attachmentId => $attachment->filename])
                            ->all()),
                ])
                ->modalSubmitActionLabel('Отвязать')
                ->action(function (array $data): void {
                    app(UnlinkSessionAttachment::class)->handle(
                        $this->actor(),
                        $this->session(),
                        $this->client(),
                        (int) $data['attachment_id'],
                    );

                    Notification::make()->success()->title('Связь с файлом удалена')->send();
                })
                ->visible(fn (): bool => MedicalSessionResource::canEdit($this->getRecord())
                    && app(ListSessionAttachments::class)->handle(
                        $this->actor(),
                        $this->session(),
                        $this->client(),
                    ) !== []),
            Action::make('edit')
                ->label('Редактировать')
                ->url(fn (): string => MedicalSessionResource::getUrl('edit', [
                    'client' => $this->getParentRecord(),
                    'record' => $this->getRecord(),
                ]))
                ->visible(fn (): bool => MedicalSessionResource::canEdit($this->getRecord())),
        ];
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function client(): Client
    {
        $client = $this->getParentRecord();
        abort_unless($client instanceof Client, 404);

        return $client;
    }

    private function session(): MedicalSession
    {
        $session = $this->getRecord();
        abort_unless($session instanceof MedicalSession, 404);

        return $session;
    }
}
