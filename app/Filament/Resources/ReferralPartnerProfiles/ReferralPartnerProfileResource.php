<?php

namespace App\Filament\Resources\ReferralPartnerProfiles;

use App\Filament\Resources\ReferralPartnerProfiles\Pages\ListReferralPartnerProfiles;
use App\Filament\Resources\ReferralPartnerProfiles\Pages\ViewReferralPartnerProfile;
use App\Filament\Resources\ReferralPartnerProfiles\Schemas\ReferralPartnerProfileInfolist;
use App\Filament\Resources\ReferralPartnerProfiles\Tables\ReferralPartnerProfilesTable;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Application\ListReferralPartnersForCrm;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<ReferralPartnerProfile> */
final class ReferralPartnerProfileResource extends Resource
{
    protected static ?string $model = ReferralPartnerProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Партнёры';

    protected static string|\UnitEnum|null $navigationGroup = 'Партнёры';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'партнёр';

    protected static ?string $pluralModelLabel = 'партнёры';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ViewClients,
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return ReferralPartnerProfilesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReferralPartnerProfileInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(ListReferralPartnersForCrm::class)->query($actor);
    }

    public static function getRecordTitle(?Model $record): string
    {
        if (! $record instanceof ReferralPartnerProfile) {
            return 'Партнёр';
        }

        return trim((string) $record->client?->full_name) ?: 'Партнёр';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferralPartnerProfiles::route('/'),
            'view' => ViewReferralPartnerProfile::route('/{record}'),
        ];
    }
}
