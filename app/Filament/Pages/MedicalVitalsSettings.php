<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Sessions\Application\MedicalMeasurementCalculator;
use App\Modules\Sessions\Application\MedicalVitalsSettings as MedicalVitalsSettingsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class MedicalVitalsSettings extends LocalizedPage
{
    protected static ?string $title = 'Показатели сеанса';

    protected static ?string $navigationLabel = 'Показатели сеанса';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 38;

    protected string $view = 'filament.pages.medical-vitals-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public static function canAccess(): bool
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }

        try {
            return app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ManageSettings,
            );
        } catch (LogicException) {
            return false;
        }
    }

    public function mount(): void
    {
        $configured = app(MedicalVitalsSettingsService::class)->forOrganization(app(OrganizationContext::class)->id());
        $rows = [];
        foreach (MedicalMeasurementCalculator::VITAL_KEYS as $key) {
            $definition = is_array($configured[$key] ?? null)
                ? $configured[$key]
                : MedicalVitalsSettingsService::sourceExamples()[$key];
            $rows[] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'unit' => (string) ($definition['unit'] ?? ''),
                'red_low' => $definition['red_low'] ?? null,
                'red_high' => $definition['red_high'] ?? null,
                'red_low_inclusive' => (bool) ($definition['red_low_inclusive'] ?? false),
                'red_high_inclusive' => (bool) ($definition['red_high_inclusive'] ?? false),
                'attention_low' => $definition['attention_low'] ?? null,
                'attention_high' => $definition['attention_high'] ?? null,
            ];
        }

        $this->form->fill(['thresholds' => $rows]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Пороги показателей'))
                ->description(__('Настройте только подтверждённые вашей организацией значения. Если порог не задан, автоматическая оценка отключена.'))
                ->schema([
                    Repeater::make('thresholds')
                        ->label(__('Показатели'))
                        ->schema([
                            Hidden::make('key')->dehydrated(),
                            TextInput::make('label')->label(__('Название'))->required()->maxLength(100),
                            TextInput::make('unit')->label(__('Единица'))->required()->maxLength(40),
                            TextInput::make('red_low')->label(__('Красный: ниже'))->numeric()->inputMode('decimal')->nullable(),
                            TextInput::make('red_high')->label(__('Красный: выше'))->numeric()->inputMode('decimal')->nullable(),
                            Toggle::make('red_low_inclusive')->label(__('Нижняя граница включительно')),
                            Toggle::make('red_high_inclusive')->label(__('Верхняя граница включительно')),
                            TextInput::make('attention_low')->label(__('Жёлтый: от'))->numeric()->inputMode('decimal')->nullable(),
                            TextInput::make('attention_high')->label(__('Жёлтый: до'))->numeric()->inputMode('decimal')->nullable(),
                        ])
                        ->columns(2)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->itemLabel(fn (array $state): string => (string) ($state['label'] ?? __('Показатель')))
                        ->columnSpanFull(),
                ]),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('medical-vitals-settings-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label(__('Сохранить настройки'))->submit('save')])]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $rows = $this->form->getState()['thresholds'] ?? [];
        $allowed = array_fill_keys(MedicalMeasurementCalculator::VITAL_KEYS, true);
        $normalized = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($allowed[(string) ($row['key'] ?? '')])) {
                continue;
            }
            $normalized[(string) $row['key']] = [
                'label' => trim((string) ($row['label'] ?? '')),
                'unit' => trim((string) ($row['unit'] ?? '')),
                'red_low' => self::number($row['red_low'] ?? null),
                'red_high' => self::number($row['red_high'] ?? null),
                'red_low_inclusive' => (bool) ($row['red_low_inclusive'] ?? false),
                'red_high_inclusive' => (bool) ($row['red_high_inclusive'] ?? false),
                'attention_low' => self::number($row['attention_low'] ?? null),
                'attention_high' => self::number($row['attention_high'] ?? null),
            ];
        }

        app(SetOrganizationSetting::class)->handle(
            $actor,
            OrganizationSettingKey::MedicalVitalsThresholds,
            json_encode($normalized, JSON_THROW_ON_ERROR),
        );
        Notification::make()->success()->title(__('Настройки показателей сохранены'))->send();
    }

    private static function number(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number === (float) (int) $number ? (int) $number : $number;
    }
}
