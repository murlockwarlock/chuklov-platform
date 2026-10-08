<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Support\LocalizedRelationManager;
use App\Models\User;
use App\Modules\Identity\Application\CreateClientNote;
use App\Modules\Identity\Application\ListClientNotesForCrm;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ClientNotesRelationManager extends LocalizedRelationManager
{
    protected static string $relationship = 'clientNotes';

    protected static ?string $title = 'Заметки';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! $ownerRecord instanceof Client) {
            return false;
        }

        $organization = app(OrganizationContext::class)->organization();

        return (int) $ownerRecord->organization_id === (int) $organization->getKey()
            && app(OrganizationAuthorizer::class)->allows(
                $actor,
                $organization,
                OrganizationPermission::ViewClients,
            );
    }

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $client = $this->getOwnerRecord();

        abort_unless($actor instanceof User, 403);
        abort_unless($client instanceof Client, 404);

        $organization = app(OrganizationContext::class)->organization();
        $canManage = app(OrganizationAuthorizer::class)->allows(
            $actor,
            $organization,
            OrganizationPermission::ManageClients,
        );

        return $table
            ->heading(__('Заметки'))
            ->stackedOnMobile()
            ->modifyQueryUsing(
                fn (Builder $query): Builder => app(ListClientNotesForCrm::class)->apply($actor, $client, $query),
            )
            ->columns([
                TextColumn::make('body')
                    ->label(__('Текст'))
                    ->limit(240)
                    ->wrap(),
                TextColumn::make('author.name')
                    ->label(__('Автор'))
                    ->placeholder(__('Не указан'))
                    ->visibleFrom('sm')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label(__('Дата и время'))
                    ->dateTime('d.m.Y H:i')
                    ->visibleFrom('md'),
            ])
            ->headerActions([
                Action::make('add')
                    ->label(__('Добавить заметку'))
                    ->icon('heroicon-o-plus')
                    ->schema([
                        Textarea::make('body')
                            ->label(__('Текст заметки'))
                            ->rows(5)
                            ->required()
                            ->maxLength(5000),
                    ])
                    ->visible($canManage)
                    ->authorize($canManage)
                    ->action(function (array $data) use ($actor, $client): void {
                        app(CreateClientNote::class)->handle($actor, $client, (string) $data['body']);
                        Notification::make()
                            ->title(__('Заметка добавлена'))
                            ->success()
                            ->send();
                    }),
            ])
            ->paginated([10, 25])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('Заметок пока нет'))
            ->emptyStateDescription(__('Добавьте внутреннюю заметку по клиенту.'));
    }
}
