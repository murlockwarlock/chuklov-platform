<?php

namespace App\Filament\Pages;

use App\Filament\Support\KnowledgeSourcePresentation;
use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Tracker\Application\GetTrackerSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** @property-read Schema $form */
final class TrackerSettings extends Page
{
    protected static ?string $title = 'Настройки трекера';

    protected static ?string $navigationLabel = 'Настройки трекера';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.tracker-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows($actor, app(OrganizationContext::class)->organization(), OrganizationPermission::ManageSettings);
    }

    public function mount(): void
    {
        $settings = app(GetTrackerSettings::class);
        $this->form->fill(['enabled' => $settings->enabled(), 'free_mode' => $settings->freeMode()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Трекер')
                ->description('Бесплатный режим относится только к трекеру и не меняет цены, записи или финансовые операции.')
                ->schema([
                    Toggle::make('enabled')->label('Трекер включён')->inline(),
                    Toggle::make('free_mode')->label('Бесплатный режим трекера')->helperText('Все подходящие клиенты получают доступ без оплаты и без создания финансовых записей.')->inline(),
                ])
                ->columns(1)
                ->columnSpanFull(),
            Section::make('Готовность функций')
                ->schema([
                    Placeholder::make('tracker_ai_status')
                        ->label('AI-помощник трекера')
                        ->content(fn (): string => $this->aiStatus()),
                    Placeholder::make('tracker_search_status')
                        ->label('Семантический поиск')
                        ->content(fn (): string => app(KnowledgeSourcePresentation::class)->semanticSearchStatus()),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('tracker-settings-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label('Сохранить настройки')->submit('save')])]);
    }

    public function save(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();
        $setter = app(SetOrganizationSetting::class);
        $setter->handle($actor, OrganizationSettingKey::TrackerEnabled, (bool) ($data['enabled'] ?? false));
        $setter->handle($actor, OrganizationSettingKey::TrackerFreeMode, (bool) ($data['free_mode'] ?? false));
        Notification::make()->success()->title('Настройки трекера сохранены')->send();
    }

    private function aiStatus(): string
    {
        $configured = AiPrompt::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('capability', AiCapability::ClientCompanion)
            ->whereHas('activeVersion', static fn ($query) => $query->where('status', 'active'))
            ->exists();

        return $configured
            ? 'Настроен через клиентский AI-компаньон.'
            : 'Не настроен. Доступ к трекеру и отметкам не зависит от AI.';
    }
}
