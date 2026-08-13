<?php

namespace App\Filament\Resources\NotificationTemplates;

use App\Filament\Resources\NotificationTemplates\Pages\CreateNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Resources\NotificationTemplates\Pages\ViewNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Schemas\NotificationTemplateForm;
use App\Filament\Resources\NotificationTemplates\Tables\NotificationTemplatesTable;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Notification templates';

    public static function form(Schema $schema): Schema
    {
        return NotificationTemplateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('template_key')->label('Template key'),
                TextEntry::make('name'),
                TextEntry::make('locale'),
                TextEntry::make('purpose'),
                TextEntry::make('is_active')->label('Active'),
                TextEntry::make('version_summary')
                    ->label('Versions')
                    ->state(fn (NotificationTemplate $record): string => $record->versions
                        ->sortByDesc('version')
                        ->map(fn ($version): string => 'v'.$version->version.' — '.$version->status->value)
                        ->implode(', ')),
                TextEntry::make('latest_subject')
                    ->label('Latest subject')
                    ->state(fn (NotificationTemplate $record): ?string => $record->versions->sortByDesc('version')->first()?->subject),
                TextEntry::make('latest_body')
                    ->label('Latest body')
                    ->state(fn (NotificationTemplate $record): ?string => $record->versions->sortByDesc('version')->first()?->body)
                    ->columnSpanFull(),
                TextEntry::make('latest_variables')
                    ->label('Latest declared variables')
                    ->state(function (NotificationTemplate $record): string {
                        $latest = $record->versions->sortByDesc('version')->first();

                        return $latest === null ? '' : implode(', ', $latest->variables);
                    })
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return NotificationTemplatesTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ViewScenarios,
        );
    }

    public static function canCreate(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageScenarios,
        );
    }

    public static function canEdit(mixed $record): bool
    {
        return self::canCreate();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with('versions');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'create' => CreateNotificationTemplate::route('/create'),
            'view' => ViewNotificationTemplate::route('/{record}'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
