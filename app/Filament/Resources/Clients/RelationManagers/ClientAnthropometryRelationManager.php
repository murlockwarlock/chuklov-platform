<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Support\LocalizedRelationManager;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Application\ListClientAnthropometry;
use App\Modules\Sessions\Application\MedicalAnthropometryReader;
use App\Modules\Sessions\Application\SaveMedicalAnthropometryMeasurement;
use App\Modules\Sessions\Domain\Models\MedicalAnthropometricMeasurement;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ClientAnthropometryRelationManager extends LocalizedRelationManager
{
    protected static string $relationship = 'medicalAnthropometricMeasurements';

    protected static ?string $title = 'Антропометрия';

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
        $reader = app(MedicalAnthropometryReader::class);

        return $table
            ->heading(__('Антропометрия'))
            ->stackedOnMobile()
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->where('organization_id', $organization->getKey())
                    ->where('client_id', $client->getKey()),
            )
            ->columns([
                TextColumn::make('measured_at')
                    ->label(__('Дата замера'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('height_cm')
                    ->label(__('Рост'))
                    ->state(fn (MedicalAnthropometricMeasurement $record): string => self::displayValue($reader->values($record), 'height_cm', 'см')),
                TextColumn::make('weight_kg')
                    ->label(__('Вес'))
                    ->state(fn (MedicalAnthropometricMeasurement $record): string => self::displayValue($reader->values($record), 'weight_kg', 'кг'))
                    ->visibleFrom('sm'),
                TextColumn::make('bmi')
                    ->label(__('ИМТ'))
                    ->state(fn (MedicalAnthropometricMeasurement $record): string => self::displayValue($reader->values($record), 'bmi'))
                    ->visibleFrom('sm'),
                TextColumn::make('body')
                    ->label(__('Объёмы'))
                    ->state(fn (MedicalAnthropometricMeasurement $record): string => self::bodySummary($reader->values($record)))
                    ->wrap()
                    ->visibleFrom('md'),
            ])
            ->headerActions([
                Action::make('add')
                    ->label(__('Добавить замер'))
                    ->icon('heroicon-o-plus')
                    ->modalHeading(__('Новый антропометрический замер'))
                    ->modalSubmitActionLabel(__('Сохранить замер'))
                    ->schema(self::formSchema())
                    ->fillForm(function () use ($actor, $client): array {
                        $last = app(ListClientAnthropometry::class)->handle($actor, $client)->first();

                        return [
                            'measured_at' => now(),
                            'height_cm' => $last['values']['height_cm'] ?? null,
                        ];
                    })
                    ->visible($canManage)
                    ->authorize($canManage)
                    ->action(function (array $data) use ($actor, $client): void {
                        app(SaveMedicalAnthropometryMeasurement::class)->handle(
                            actor: $actor,
                            client: $client,
                            values: $data,
                            measuredAt: filled($data['measured_at'] ?? null)
                                ? CarbonImmutable::parse((string) $data['measured_at'])
                                : null,
                        );

                        $this->flushCachedTableRecords();
                        Notification::make()->title(__('Замер сохранён'))->success()->send();
                    }),
            ])
            ->paginated([10, 25])
            ->defaultSort('measured_at', 'desc')
            ->emptyStateHeading(__('Замеров пока нет'))
            ->emptyStateDescription(__('Добавьте первый замер, чтобы видеть историю роста, веса и объёмов.'));
    }

    /** @return array<int, TextInput|DateTimePicker> */
    private static function formSchema(): array
    {
        return [
            DateTimePicker::make('measured_at')
                ->label(__('Дата и время'))
                ->seconds(false)
                ->required(),
            TextInput::make('height_cm')->label(__('Рост, см'))->numeric()->inputMode('decimal'),
            TextInput::make('weight_kg')->label(__('Вес, кг'))->numeric()->inputMode('decimal'),
            TextInput::make('impedance')->label(__('Импеданс'))->numeric()->inputMode('decimal'),
            TextInput::make('waist_cm')->label(__('Талия, см'))->numeric()->inputMode('decimal'),
            TextInput::make('hips_cm')->label(__('Бёдра, см'))->numeric()->inputMode('decimal'),
            TextInput::make('chest_cm')->label(__('Грудь, см'))->numeric()->inputMode('decimal'),
            TextInput::make('right_thigh_cm')->label(__('Правое бедро, см'))->numeric()->inputMode('decimal'),
            TextInput::make('left_thigh_cm')->label(__('Левое бедро, см'))->numeric()->inputMode('decimal'),
            TextInput::make('right_shoulder_cm')->label(__('Правое плечо, см'))->numeric()->inputMode('decimal'),
            TextInput::make('left_shoulder_cm')->label(__('Левое плечо, см'))->numeric()->inputMode('decimal'),
        ];
    }

    /** @param array<string, mixed> $values */
    private static function displayValue(array $values, string $key, string $unit = ''): string
    {
        $value = $values[$key] ?? null;

        return $value === null || $value === '' ? '—' : (string) $value.($unit !== '' ? ' '.$unit : '');
    }

    /** @param array<string, mixed> $values */
    private static function bodySummary(array $values): string
    {
        $parts = [];
        foreach ([
            'waist_cm' => 'Талия',
            'hips_cm' => 'Бёдра',
            'chest_cm' => 'Грудь',
            'right_thigh_cm' => 'Правое бедро',
            'left_thigh_cm' => 'Левое бедро',
            'right_shoulder_cm' => 'Правое плечо',
            'left_shoulder_cm' => 'Левое плечо',
        ] as $key => $label) {
            if (($values[$key] ?? null) !== null) {
                $parts[] = $label.': '.$values[$key].' см';
            }
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
