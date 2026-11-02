<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsTagCandidateCatalog;
use App\Modules\Analytics\Application\ReviewAnalyticsTagCandidate;
use App\Modules\Feedback\Application\FeedbackThemeCatalog;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Tracker\Application\TrackerGoalTagCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
final class AnalyticsTagCandidates extends LocalizedPage
{
    protected static ?string $title = 'Кандидаты тегов';

    protected static ?string $navigationLabel = 'Кандидаты тегов';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.analytics-tag-candidates';

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
        $organizationId = app(OrganizationContext::class)->id();
        $enabled = app(OrganizationContext::class)->organization()->settings()
            ->where('setting_key', OrganizationSettingKey::AnalyticsAiNewTagCandidatesEnabled->value)
            ->value('boolean_value') === true;
        $this->form->fill([
            'allow_new_candidates' => $enabled,
            'candidates' => array_map(static fn (array $candidate): array => $candidate + [
                'taxonomy_label' => self::taxonomyLabel((string) ($candidate['taxonomy'] ?? '')),
                'decision' => null,
                'reviewed_label' => null,
                'target_tag_id' => null,
            ], app(AnalyticsTagCandidateCatalog::class)->pending($organizationId)),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Настройки предложений'))
                ->description(__('Новые категории не создаются автоматически. Включение разрешает только сохранять неизвестное предложение как кандидата на ручную проверку.'))
                ->schema([
                    Toggle::make('allow_new_candidates')->label(__('Сохранять кандидатов новых категорий')),
                ]),
            Section::make(__('Ожидают проверки'))
                ->schema([
                    Repeater::make('candidates')
                        ->label(__('Кандидаты'))
                        ->schema([
                            TextInput::make('label')->label(__('Предложение'))->disabled()->dehydrated(false),
                            Hidden::make('taxonomy')->dehydrated(false),
                            TextInput::make('taxonomy_label')->label(__('Категория'))->disabled()->dehydrated(false),
                            Placeholder::make('source_context')
                                ->label(__('Контекст источника'))
                                ->content(fn (Get $get): string => trim(implode(' · ', array_filter([
                                    (string) $get('source_type'),
                                    filled($get('source_id')) ? 'ID '.(string) $get('source_id') : null,
                                    filled($get('client_label')) ? (string) $get('client_label') : null,
                                ]))))
                                ->columnSpanFull(),
                            Hidden::make('source_type')->dehydrated(false),
                            Hidden::make('source_id')->dehydrated(false),
                            Hidden::make('client_label')->dehydrated(false),
                            Hidden::make('candidate_type')->dehydrated(false),
                            Select::make('decision')
                                ->label(__('Решение'))
                                ->options(['accept' => 'Принять', 'rename' => 'Переименовать', 'rename_existing' => 'Переименовать существующую категорию', 'merge' => 'Объединить', 'reject' => 'Отклонить'])
                                ->live(),
                            TextInput::make('reviewed_label')
                                ->label(__('Итоговое название'))
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => in_array($get('decision'), ['rename', 'rename_existing'], true)
                                    || ($get('decision') === 'accept' && $get('candidate_type') === 'new')),
                            Select::make('target_tag_id')
                                ->label(__('Существующая категория'))
                                ->options(fn (Get $get): array => $this->tagOptions((string) $get('taxonomy')))
                                ->searchable()
                                ->visible(fn (Get $get): bool => in_array($get('decision'), ['rename_existing', 'merge'], true)),
                            Hidden::make('id')->dehydrated(true),
                        ])
                        ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ])->columnSpanFull(),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('analytics-tag-candidates-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label(__('Сохранить решения'))->submit('save')])]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();
        app(SetOrganizationSetting::class)->handle(
            $actor,
            OrganizationSettingKey::AnalyticsAiNewTagCandidatesEnabled,
            (bool) ($data['allow_new_candidates'] ?? false),
        );
        foreach (is_array($data['candidates'] ?? null) ? $data['candidates'] : [] as $candidate) {
            if (! is_array($candidate) || ! is_string($candidate['decision'] ?? null) || ! is_numeric($candidate['id'] ?? null)) {
                continue;
            }
            app(ReviewAnalyticsTagCandidate::class)->handle(
                actor: $actor,
                candidateId: (int) $candidate['id'],
                decision: $candidate['decision'],
                label: is_string($candidate['reviewed_label'] ?? null) ? $candidate['reviewed_label'] : null,
                targetTagId: is_numeric($candidate['target_tag_id'] ?? null) ? (int) $candidate['target_tag_id'] : null,
            );
        }
        $this->mount();
        Notification::make()->success()->title(__('Решения по кандидатам сохранены'))->send();
    }

    /** @return array<int, string> */
    private function tagOptions(string $taxonomy): array
    {
        $organizationId = app(OrganizationContext::class)->id();

        return match ($taxonomy) {
            'complaints' => app(MedicalProblemTagCatalog::class)->options($organizationId),
            'feedback' => app(FeedbackThemeCatalog::class)->options($organizationId),
            'goals' => app(TrackerGoalTagCatalog::class)->options($organizationId),
            default => [],
        };
    }

    private static function taxonomyLabel(string $taxonomy): string
    {
        return match ($taxonomy) {
            'complaints' => 'Жалобы и проблемы',
            'feedback' => 'Темы обратной связи',
            'goals' => 'Цели и задачи',
            default => 'Категория',
        };
    }
}
