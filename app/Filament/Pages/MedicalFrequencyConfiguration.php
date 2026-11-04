<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\MedicalProfiles\Application\MedicalFrequencyPeriodCatalog;
use App\Modules\MedicalProfiles\Application\SaveMedicalFrequencyPeriods;
use App\Modules\MedicalProfiles\Domain\Models\MedicalFrequencyPeriodUnit;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class MedicalFrequencyConfiguration extends LocalizedPage
{
    protected static ?string $title = 'Периоды частоты боли';

    protected static ?string $navigationLabel = 'Периоды частоты боли';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 35;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    protected string $view = 'filament.pages.medical-frequency-configuration';

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
        $organizationId = app(OrganizationContext::class)->id();
        app(MedicalFrequencyPeriodCatalog::class)->ensureDefaults($organizationId);
        $rows = MedicalFrequencyPeriodUnit::query()
            ->where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalFrequencyPeriodUnit $unit): array => [
                'key' => $unit->key,
                'label' => $unit->label,
                'is_active' => $unit->is_active,
                'sort_order' => $unit->sort_order,
            ])->all();
        $this->form->fill(['periods' => $rows]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Периоды частоты'))
                    ->description(__('Названия и порядок видит специалист при заполнении боли. Новые значения можно добавить без изменения программы.'))
                    ->schema([
                        Repeater::make('periods')
                            ->label(__('Периоды'))
                            ->schema([
                                Hidden::make('key')->default(fn (): string => 'period_'.Str::lower(Str::random(12))),
                                TextInput::make('label')->label(__('Название периода'))->required()->maxLength(120),
                                Toggle::make('is_active')->label(__('Использовать в новых записях'))->default(true),
                                TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                            ])
                            ->columns(['default' => 1, 'sm' => 3])
                            ->defaultItems(0)
                            ->reorderable()
                            ->itemLabel(fn (array $state): string => (string) ($state['label'] ?? __('Новый период')))
                            ->addActionLabel(__('Добавить период'))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('medical-frequency-configuration-form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')->label(__('Сохранить периоды'))->submit('save'),
                ]),
            ]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $state = $this->form->getState();
        app(SaveMedicalFrequencyPeriods::class)->handle($actor, is_array($state['periods'] ?? null) ? $state['periods'] : []);
        Notification::make()->success()->title(__('Периоды сохранены'))->send();
    }
}
