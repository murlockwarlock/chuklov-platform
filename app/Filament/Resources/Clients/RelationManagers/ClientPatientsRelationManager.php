<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Clients\Schemas\PatientIdentityForm;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\LocalizedRelationManager;
use App\Models\User;
use App\Modules\Identity\Application\CreatePatientProfile;
use App\Modules\Identity\Application\ListPatientProfilesForCrm;
use App\Modules\Identity\Application\RevokePatientRelationship;
use App\Modules\Identity\Application\UpdatePatientProfile;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ClientPatientsRelationManager extends LocalizedRelationManager
{
    protected static string $relationship = 'patientProfiles';

    protected static ?string $title = 'Семья';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $ownerRecord instanceof Client
            && (int) $ownerRecord->organization_id === app(OrganizationContext::class)->id()
            && app(OrganizationAuthorizer::class)->allows($actor, app(OrganizationContext::class)->organization(), OrganizationPermission::ViewClients);
    }

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $client = $this->getOwnerRecord();
        abort_unless($actor instanceof User && $client instanceof Client, 403);
        $canManage = app(OrganizationAuthorizer::class)->allows($actor, app(OrganizationContext::class)->organization(), OrganizationPermission::ManageClients);

        return $table->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => app(ListPatientProfilesForCrm::class)->apply($actor, $client, $query))
            ->columns([
                TextColumn::make('clinicalClient.full_name')->label(__('Пациент'))->wrap()
                    ->url(fn (PatientProfile $record): ?string => CrmEntityLinks::clientUrl($record->clinicalClient)),
                TextColumn::make('relationship')->label(__('Родство'))->formatStateUsing(fn (string $state): string => match ($state) {
                    'SELF' => __('Сам клиент'), 'SPOUSE' => __('Супруг / супруга'), 'CHILD' => __('Ребёнок'), 'PARENT' => __('Родитель'), default => __('Не указано'),
                })->wrap(),
                TextColumn::make('birth_date')->label(__('Дата рождения'))->date('d.m.Y'),
                TextColumn::make('owner_revoked_at')->label(__('Отвязано'))->dateTime('d.m.Y H:i')->placeholder(__('Связь активна')),
            ])
            ->headerActions([
                Action::make('addPatient')->label(__('Добавить члена семьи'))->icon('heroicon-o-plus')
                    ->visible($canManage)->authorize($canManage)->schema(PatientIdentityForm::components())
                    ->action(fn (array $data) => app(CreatePatientProfile::class)->handle($client, $data, (string) $data['creation_key'], $actor)),
            ])
            ->recordActions([
                Action::make('editPatient')->label(__('Изменить'))->schema(PatientIdentityForm::components())
                    ->visible(fn (PatientProfile $record): bool => $canManage && $record->relationship !== 'SELF' && $record->owner_revoked_at === null)
                    ->authorize($canManage)
                    ->fillForm(fn (PatientProfile $record): array => [
                        'first_name' => $record->first_name, 'last_name' => $record->last_name, 'middle_name' => $record->middle_name,
                        'gender' => $record->gender, 'birth_date' => $record->birth_date?->toDateString(), 'relationship' => $record->relationship,
                        'creation_key' => $record->creation_key,
                    ])
                    ->action(fn (PatientProfile $record, array $data) => app(UpdatePatientProfile::class)->handle($actor, $record, $data)),
                Action::make('revokePatient')->label(__('Отвязать'))->color('danger')->requiresConfirmation()
                    ->visible(fn (PatientProfile $record): bool => $canManage && $record->relationship !== 'SELF' && $record->owner_revoked_at === null)
                    ->authorize($canManage)->action(fn (PatientProfile $record) => app(RevokePatientRelationship::class)->handle($actor, $record)),
            ])
            ->paginated([10, 25])->defaultSort('id', 'desc')->emptyStateHeading(__('Члены семьи пока не добавлены'));
    }
}
