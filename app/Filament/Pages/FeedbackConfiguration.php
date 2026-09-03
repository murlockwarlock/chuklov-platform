<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Modules\Feedback\Application\GetFeedbackConfiguration;
use App\Modules\Feedback\Application\SaveFeedbackConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Support\Facades\Auth;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class FeedbackConfiguration extends Page
{
    protected static ?string $title = 'Настройки обратной связи';

    protected static ?string $navigationLabel = 'Настройки обратной связи';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Коммуникации';

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    protected string $view = 'filament.pages.feedback-configuration';

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
        $settings = app(GetFeedbackConfiguration::class)->handle();
        $this->form->fill([
            'enabled' => $settings['enabled'],
            'positive_threshold' => $settings['positiveThreshold'],
            'low_score_feedback_required' => $settings['lowScoreFeedbackRequired'],
            'review_url_ru' => $settings['reviewLinks']['ru'],
            'review_url_en' => $settings['reviewLinks']['en'],
            'review_destinations' => $settings['reviewDestinations'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Правила оценки')
                    ->schema([
                        Toggle::make('enabled')->label('Включить NPS/обратную связь')->required()->columnSpanFull(),
                        TextInput::make('positive_threshold')
                            ->label('Порог положительной оценки')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10)
                            ->required()
                            ->columnSpanFull(),
                        Toggle::make('low_score_feedback_required')
                            ->label('Требовать текст для низкой оценки')
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
                Section::make('Внешние площадки')
                    ->description('Ссылки только показываются клиенту после положительной оценки. Сервер их не запрашивает. Текст сообщения и отправка после завершения визита: Коммуникации → Шаблоны сообщений и Коммуникации → Авто-сообщения.')
                    ->schema([
                        Placeholder::make('feedback_template_path')
                            ->label('Где изменить сообщение после визита')
                            ->content('Откройте «Шаблоны сообщений» и найдите сценарий оценки визита. Затем проверьте «Авто-сообщения» для события «После визита».')
                            ->columnSpanFull(),
                        TextInput::make('review_url_ru')->label('Ссылка на отзыв (RU)')->url()->maxLength(2048),
                        TextInput::make('review_url_en')->label('Ссылка на отзыв (EN)')->url()->maxLength(2048),
                        Repeater::make('review_destinations')
                            ->label('Площадки для оценки 8–10')
                            ->helperText('Добавьте 2GIS, Google, Яндекс или другие площадки. Показываются только активные HTTPS-ссылки; запросов к площадкам система не делает.')
                            ->schema([
                                TextInput::make('label')->label('Название площадки')->required()->maxLength(160),
                                TextInput::make('url')->label('HTTPS-ссылка')->url()->required()->maxLength(2048),
                                Toggle::make('isActive')->label('Показывать клиенту')->default(true),
                                TextInput::make('sortOrder')->label('Порядок')->integer()->minValue(0)->default(0),
                            ])
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(['default' => 1, 'sm' => 2]),
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
            ->id('feedback-configuration-form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')->label('Сохранить настройки')->submit('save'),
                ]),
            ]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();
        app(SaveFeedbackConfiguration::class)->handle(
            actor: $actor,
            enabled: (bool) ($data['enabled'] ?? false),
            positiveThreshold: (int) $data['positive_threshold'],
            lowScoreFeedbackRequired: (bool) ($data['low_score_feedback_required'] ?? false),
            reviewUrlRu: is_string($data['review_url_ru'] ?? null) ? $data['review_url_ru'] : null,
            reviewUrlEn: is_string($data['review_url_en'] ?? null) ? $data['review_url_en'] : null,
            reviewDestinations: is_array($data['review_destinations'] ?? null) ? $data['review_destinations'] : [],
        );

        Notification::make()->success()->title('Настройки обратной связи сохранены')->send();
    }
}
