<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Filament\Support\LocalizedRelationManager;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\ListClientSessions;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ClientVitalsRelationManager extends LocalizedRelationManager
{
    protected static string $relationship = 'sessions';

    protected static ?string $title = 'Показатели по сеансам';

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

        $getSession = app(GetSession::class);

        return $table
            ->heading(__('Показатели по сеансам'))
            ->stackedOnMobile()
            ->modifyQueryUsing(
                fn (Builder $query): Builder => app(ListClientSessions::class)
                    ->apply($actor, $client, $query)
                    ->whereNotNull('measurements'),
            )
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('Дата сеанса'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('before')
                    ->label(__('До сеанса'))
                    ->state(fn (MedicalSession $record): string => self::phaseSummary(
                        $getSession->handle($actor, $record, $client)?->measurements,
                        'before',
                    ))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('after')
                    ->label(__('После сеанса'))
                    ->state(fn (MedicalSession $record): string => self::phaseSummary(
                        $getSession->handle($actor, $record, $client)?->measurements,
                        'after',
                    ))
                    ->placeholder('—')
                    ->wrap()
                    ->visibleFrom('md'),
                TextColumn::make('specialist.display_name')
                    ->label(__('Специалист'))
                    ->placeholder('—')
                    ->visibleFrom('lg'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('Открыть сеанс'))
                    ->url(fn (MedicalSession $record): string => MedicalSessionResource::getUrl(
                        'view',
                        ['record' => $record],
                        shouldGuessMissingParameters: true,
                    )),
            ])
            ->paginated([10, 25])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading(__('Показателей пока нет'))
            ->emptyStateDescription(__('Заполненные показатели до или после сеанса появятся здесь автоматически.'));
    }

    /** @param array<string, mixed>|null $measurements */
    private static function phaseSummary(?array $measurements, string $phase): string
    {
        if (! is_array($measurements) || ($phase === 'after' && ! ($measurements['after_enabled'] ?? false))) {
            return '—';
        }

        $vitals = $measurements[$phase]['vitals'] ?? null;
        if (! is_array($vitals)) {
            return '—';
        }

        $items = [];
        foreach ([
            'sys' => 'SYS',
            'dia' => 'DIA',
            'pd' => 'PD',
            'map' => 'MAP',
            'pulse' => 'Пульс',
            'pi' => 'ИП',
            'spo2' => 'SpO2',
        ] as $key => $label) {
            if (! is_numeric($vitals[$key] ?? null)) {
                continue;
            }

            $unit = match ($key) {
                'sys', 'dia', 'pd', 'map' => ' мм рт. ст.',
                'pulse' => ' уд/мин',
                'spo2' => '%',
                default => '',
            };
            $items[] = $label.': '.self::formatNumber($vitals[$key]).$unit;
        }

        return $items === [] ? '—' : implode(' · ', $items);
    }

    private static function formatNumber(mixed $value): string
    {
        $number = (float) $value;
        if (fmod($number, 1.0) === 0.0) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(number_format($number, 2, ',', ' '), '0'), ',');
    }
}
