<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Models\User;
use App\Modules\Identity\Application\BlockClientSelfBooking;
use App\Modules\Identity\Application\UnblockClientSelfBooking;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Клинический профиль';
    }

    public function infolist(Schema $schema): Schema
    {
        return ClientResource::infolist($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Изменить данные клиента'),
            Action::make('editMedicalProfile')
                ->label('Изменить медицинский профиль')
                ->icon('heroicon-o-heart')
                ->fillForm(function (): array {
                    $actor = auth()->user();
                    $client = $this->clientRecord();

                    if (! $actor instanceof User) {
                        return [];
                    }

                    $profile = app(GetMedicalProfile::class)->handle($actor, $client);

                    return [
                        'anamnesis' => $profile?->anamnesis,
                        'complaints_goals' => $profile?->complaintsGoals,
                        'operations_injuries' => $profile?->operationsInjuries,
                        'medicines' => $profile?->medicines,
                        'supplements' => $profile?->supplements,
                    ];
                })
                ->schema(self::medicalProfileSchema())
                ->visible(fn (): bool => ClientResource::canEdit($this->clientRecord()))
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $client = $this->clientRecord();

                    abort_unless($actor instanceof User, 403);

                    app(UpdateMedicalProfile::class)->handle(
                        $actor,
                        $client,
                        UpdateMedicalProfileCommand::fromArray($data),
                    );
                }),
            Action::make('newSession')
                ->label('Новый сеанс')
                ->icon('heroicon-o-plus')
                ->url(fn (): string => MedicalSessionResource::getUrl('create', shouldGuessMissingParameters: true))
                ->visible(fn (): bool => MedicalSessionResource::canCreate()),
            Action::make('blockSelfBooking')
                ->label('Запретить самостоятельную запись')
                ->color('danger')
                ->requiresConfirmation()
                ->schema([
                    Textarea::make('reason')
                        ->label('Причина ограничения')
                        ->required()
                        ->maxLength(500),
                ])
                ->visible(fn (): bool => $this->clientRecord()->activeBookingRestriction === null
                    && ClientResource::canEdit($this->clientRecord()))
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $client = $this->clientRecord();

                    abort_unless($actor instanceof User, 403);

                    app(BlockClientSelfBooking::class)->handle($actor, $client, (string) $data['reason']);
                    $client->load('activeBookingRestriction');
                }),
            Action::make('unblockSelfBooking')
                ->label('Разрешить самостоятельную запись')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->clientRecord()->activeBookingRestriction !== null
                    && ClientResource::canEdit($this->clientRecord()))
                ->action(function (): void {
                    $actor = auth()->user();
                    $client = $this->clientRecord();

                    abort_unless($actor instanceof User, 403);

                    app(UnblockClientSelfBooking::class)->handle($actor, $client);
                    $client->load('activeBookingRestriction');
                }),
        ];
    }

    /** @return array<Textarea> */
    private static function medicalProfileSchema(): array
    {
        return [
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
        ];
    }

    private function clientRecord(): Client
    {
        $record = $this->getRecord();

        abort_unless($record instanceof Client, 404);

        return $record;
    }
}
