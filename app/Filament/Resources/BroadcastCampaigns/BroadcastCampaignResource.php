<?php

namespace App\Filament\Resources\BroadcastCampaigns;

use App\Filament\Resources\BroadcastCampaigns\Pages\CreateBroadcastCampaign;
use App\Filament\Resources\BroadcastCampaigns\Pages\EditBroadcastCampaign;
use App\Filament\Resources\BroadcastCampaigns\Pages\ListBroadcastCampaigns;
use App\Filament\Resources\BroadcastCampaigns\Pages\ViewBroadcastCampaign;
use App\Filament\Resources\BroadcastCampaigns\RelationManagers\RecipientsRelationManager;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Support\BroadcastFailurePresentation;
use App\Filament\Support\RichTextEditor;
use App\Filament\Support\RichTextPresentation;
use App\Models\User;
use App\Modules\Broadcasts\Application\BroadcastCampaignMedia;
use App\Modules\Broadcasts\Application\BroadcastCampaignName;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientTag;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Content\Domain\ValueObjects\ContentExternalImageUrl;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Support\RichText\RichTextDocument;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Image as SchemaImage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class BroadcastCampaignResource extends Resource
{
    protected static ?string $model = BroadcastCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Рассылки';

    protected static string|\UnitEnum|null $navigationGroup = 'Коммуникации';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'рассылка';

    protected static ?string $pluralModelLabel = 'рассылки';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Рассылка')->schema([
                TextInput::make('name')->label('Название рассылки')->required()->maxLength(160),
                Hidden::make('channel_priority')->default(['telegram']),
            ])->columns(1)->columnSpanFull(),
            Section::make('Кому отправить')->description('Согласие на маркетинговые сообщения и доступный Telegram проверяются перед отправкой.')->schema([
                Radio::make('audience_type')
                    ->label('Выбор клиентов')
                    ->options([
                        'selected' => 'Выбрать клиентов',
                        'all' => 'Всем клиентам с согласием',
                        'segment' => 'По группе или метке',
                    ])
                    ->default('selected')
                    ->live()
                    ->required()
                    ->columns(1),
                Select::make('selected_client_ids')
                    ->label('Клиенты')
                    ->placeholder('Начните вводить имя, телефон или Telegram')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::clientOptions())
                    ->getSearchResultsUsing(fn (string $search): array => self::clientSearch($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::clientLabel($value))
                    ->optionsLimit(50)
                    ->live()
                    ->visible(fn (Get $get): bool => $get('audience_type') === 'selected')
                    ->required(fn (Get $get): bool => $get('audience_type') === 'selected'),
                Placeholder::make('recipient_preview')
                    ->label('Получателей')
                    ->content(fn (Get $get): string => self::recipientPreview($get)),
                Section::make('Расширенный выбор')
                    ->description('Выберите понятные условия. Все условия применяются одновременно. Медицинские и клинические данные недоступны для маркетингового отбора.')
                    ->schema([
                        Repeater::make('segment_definition')
                            ->label('Условия')
                            ->schema([
                                Select::make('key')->label('Что проверить')->options(self::filterOptions())->required()->live(),
                                Select::make('operator')->label('Проверка')->options(fn (Get $get): array => self::operatorOptions((string) $get('key')))->required()->live(),
                                Select::make('value_bool')->label('Ответ')->options(['1' => 'Да', '0' => 'Нет'])->live()->visible(fn (Get $get): bool => self::isBooleanFilter((string) $get('key')))->required(fn (Get $get): bool => self::isBooleanFilter((string) $get('key'))),
                                Select::make('value_select')
                                    ->label(fn (Get $get): string => self::conditionValueLabel((string) $get('key')))
                                    ->options(fn (Get $get): array => self::controlledValueOptions((string) $get('key')))
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::isControlledFilter((string) $get('key')) && $get('operator') !== 'in')
                                    ->required(fn (Get $get): bool => self::isControlledFilter((string) $get('key')) && $get('operator') !== 'in'),
                                Select::make('value_select_list')
                                    ->label(fn (Get $get): string => self::conditionValueLabel((string) $get('key'), true))
                                    ->options(fn (Get $get): array => self::controlledValueOptions((string) $get('key')))
                                    ->multiple()
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::isControlledFilter((string) $get('key')) && $get('operator') === 'in')
                                    ->required(fn (Get $get): bool => self::isControlledFilter((string) $get('key')) && $get('operator') === 'in'),
                                TagsInput::make('value_list')
                                    ->label(fn (Get $get): string => self::conditionValueLabel((string) $get('key'), true))
                                    ->visible(fn (Get $get): bool => $get('operator') === 'in' && ! self::isControlledFilter((string) $get('key')))
                                    ->required(fn (Get $get): bool => $get('operator') === 'in' && ! self::isControlledFilter((string) $get('key')))
                                    ->live()
                                    ->nestedRecursiveRules(['string', 'max:120']),
                                TextInput::make('value_text')
                                    ->label(fn (Get $get): string => self::conditionValueLabel((string) $get('key')))
                                    ->visible(fn (Get $get): bool => $get('operator') !== 'in' && ! self::isBooleanFilter((string) $get('key')) && ! self::isControlledFilter((string) $get('key')))
                                    ->required(fn (Get $get): bool => $get('operator') !== 'in' && ! self::isBooleanFilter((string) $get('key')) && ! self::isControlledFilter((string) $get('key')))
                                    ->live()
                                    ->maxLength(120),
                                Placeholder::make('condition_preview')
                                    ->label('Понятное описание')
                                    ->content(fn (Get $get): string => self::conditionPreview($get))
                                    ->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel('Добавить условие')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (Get $get): bool => $get('audience_type') === 'segment')
                    ->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Сообщение')->schema([
                Radio::make('delivery_mode')
                    ->label('Формат отправки')
                    ->options([
                        NotificationMessageMode::Text->value => 'Только текст',
                        NotificationMessageMode::Image->value => 'Только изображение',
                        NotificationMessageMode::ImageThenText->value => 'Изображение, затем текст',
                        NotificationMessageMode::TextThenImage->value => 'Текст, затем изображение',
                        NotificationMessageMode::ImageWithCaption->value => 'Изображение с подписью',
                    ])
                    ->default(NotificationMessageMode::Text->value)
                    ->live()
                    ->required()
                    ->columns(1)
                    ->columnSpanFull(),
                Radio::make('caption_position')
                    ->label('Положение подписи')
                    ->options(['above' => 'Над изображением', 'below' => 'Под изображением'])
                    ->default('below')
                    ->inline()
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => self::deliveryUsesCaption($get))
                    ->required(fn (Get $get): bool => self::deliveryUsesCaption($get)),
                Radio::make('message_mode')
                    ->label('Источник текста')
                    ->options([
                        'compose' => 'Написать сообщение',
                        'saved_template' => 'Использовать сохранённый шаблон',
                    ])
                    ->default('compose')
                    ->live()
                    ->inline()
                    ->helperText('Для разовой рассылки оставьте «Написать сообщение».')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => self::deliveryIncludesText($get))
                    ->required(fn (Get $get): bool => self::deliveryIncludesText($get)),
                RichTextEditor::make('message_body', ScenarioTemplateVariableCatalog::labelsForPurpose(ScenarioRulePurpose::Marketing))
                    ->label('Текст сообщения в Telegram')
                    ->maxLength(100000)
                    ->live()
                    ->helperText('Для рассылки доступны имя и язык клиента. Нажмите «Добавить данные» в редакторе, чтобы вставить поле в место курсора.')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('message_mode') === 'compose' && self::deliveryIncludesText($get))
                    ->required(fn (Get $get): bool => $get('message_mode') === 'compose' && self::deliveryIncludesText($get)),
                Placeholder::make('message_counter')
                    ->label('Лимит Telegram')
                    ->content(fn (Get $get): string => self::messageCounter($get))
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('message_mode') === 'compose' && self::deliveryIncludesText($get)),
                Placeholder::make('message_preview')
                    ->label('Предпросмотр')
                    ->content(fn (Get $get): string => RichTextPresentation::html((string) $get('message_body')) ?: 'Текст появится здесь.')
                    ->prose()
                    ->html()
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('message_mode') === 'compose' && self::deliveryIncludesText($get)),
                Select::make('template_version_ru_id')
                    ->label('Сохранённый шаблон')
                    ->options(fn (): array => self::templateOptions('ru'))
                    ->placeholder('Нет опубликованных сообщений')
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('message_mode') === 'saved_template' && self::deliveryIncludesText($get)),
                Select::make('template_version_en_id')
                    ->label('Шаблон для английского текста')
                    ->options(fn (): array => self::templateOptions('en'))
                    ->placeholder('Нет опубликованных сообщений')
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('message_mode') === 'saved_template' && self::deliveryIncludesText($get)),
                Placeholder::make('template_empty')
                    ->label('Готовые сообщения')
                    ->content('Нет готовых шаблонов для этого типа сообщения.')
                    ->visible(fn (Get $get): bool => $get('message_mode') === 'saved_template' && self::deliveryIncludesText($get) && self::templateOptions('ru') === [] && self::templateOptions('en') === []),
                Actions::make([
                    Action::make('createMessage')
                        ->label('Создать сообщение')
                        ->icon(Heroicon::OutlinedPlus)
                        ->url(fn (): string => NotificationTemplateResource::getUrl('create')),
                ])->visible(fn (Get $get): bool => $get('message_mode') === 'saved_template' && self::deliveryIncludesText($get)),
            ])->columns(1)->columnSpanFull(),
            Section::make('Изображение')->description('Для одной отправки можно добавить одно изображение. Видео и несколько файлов пока не поддерживаются. Новая загрузка или ссылка заменит текущее изображение после сохранения.')->schema([
                FileUpload::make('media_image')
                    ->label('Загрузить изображение')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(self::imageUploadKilobytes())
                    ->storeFiles(false)
                    ->helperText('JPG, PNG или WebP размером до 5 МБ. Второй файл заменит текущий только после сохранения.')
                    ->columnSpanFull(),
                TextInput::make('media_url')
                    ->label('HTTPS-ссылка на изображение')
                    ->url()
                    ->maxLength(2000)
                    ->dehydrated(fn (mixed $state): bool => filled($state))
                    ->helperText('Заполните только если не загружаете файл. Видео и несколько файлов не поддерживаются.')
                    ->columnSpanFull(),
                TextInput::make('media_alt')
                    ->label('Описание изображения')
                    ->maxLength(255)
                    ->columnSpanFull(),
                SchemaImage::make(
                    fn (?BroadcastCampaign $record): string => self::mediaPreviewUrl($record) ?? '',
                    fn (?BroadcastCampaign $record): string => self::mediaAlt($record),
                )
                    ->imageHeight('16rem')
                    ->imageWidth('24rem')
                    ->extraAttributes(['class' => 'max-w-full rounded-xl object-contain'])
                    ->visible(fn (?BroadcastCampaign $record): bool => self::mediaPreviewUrl($record) !== null)
                    ->columnSpanFull(),
                Placeholder::make('current_media_status')
                    ->label('Текущее изображение')
                    ->content(fn (?BroadcastCampaign $record): string => self::mediaStatus($record))
                    ->visible(fn (?BroadcastCampaign $record): bool => self::hasMedia($record))
                    ->columnSpanFull(),
                Toggle::make('remove_media')
                    ->label('Удалить текущее изображение')
                    ->visible(fn (?BroadcastCampaign $record): bool => self::hasMedia($record))
                    ->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Запуск')->schema([
                Select::make('send_mode')->label('Когда отправить')->options(['immediate' => 'Сейчас', 'scheduled' => 'Запланировать'])->default('immediate')->required()->live(),
                DateTimePicker::make('scheduled_at')
                    ->label(fn (): string => 'Дата и время ('.app(OrganizationContext::class)->defaultTimezone().')')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                    ->seconds(false)
                    ->helperText(fn (): string => 'Время указывается по часовому поясу организации: '.app(OrganizationContext::class)->defaultTimezone().'.')
                    ->visible(fn (Get $get): bool => $get('send_mode') === 'scheduled')
                    ->required(fn (Get $get): bool => $get('send_mode') === 'scheduled'),
            ])->columns(2)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Рассылка'),
            TextEntry::make('state')->label('Состояние')->formatStateUsing(fn ($state): string => self::stateLabel($state instanceof BroadcastCampaignState ? $state : BroadcastCampaignState::from((string) $state)))->badge(),
            TextEntry::make('segment_summary')->label('Получатели')->columnSpanFull(),
            TextEntry::make('message_preview')
                ->label('Сообщение')
                ->state(function (BroadcastCampaign $record): string {
                    $body = trim((string) ($record->message_body ?: $record->russianTemplateVersion?->body ?: $record->englishTemplateVersion?->body));

                    return $body === '' ? 'Сообщение не выбрано' : RichTextPresentation::html($body);
                })
                ->columnSpanFull()
                ->prose()
                ->html()
                ->wrap(),
            TextEntry::make('media_summary')
                ->label('Медиа')
                ->state(fn (BroadcastCampaign $record): string => self::mediaSummary($record)),
            TextEntry::make('scheduled_at')->label(fn (): string => 'Запланировано ('.app(OrganizationContext::class)->defaultTimezone().')')->dateTime('d.m.Y H:i')->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())->placeholder('Сразу'),
            TextEntry::make('audience_count')->label('Найдено'),
            TextEntry::make('delivered_count')->label('Доставлено'),
            TextEntry::make('failed_count')->label('Ошибки'),
            TextEntry::make('suppressed_count')->label('Исключено (нет согласия или Telegram)'),
            TextEntry::make('audienceSnapshot.materialized_at')->label('Список получателей зафиксирован')->dateTime('d.m.Y H:i')->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())->placeholder('Ещё не зафиксирован'),
            TextEntry::make('failure_summary')
                ->label('Последние ошибки')
                ->state(fn (BroadcastCampaign $record): string => $record->recipients()
                    ->whereNotNull('last_error_code')
                    ->latest('updated_at')
                    ->limit(10)
                    ->pluck('last_error_code')
                    ->map(fn (string $code): string => self::failureLabel($code))
                    ->unique()
                    ->implode(', ') ?: 'Нет')
                ->columnSpanFull(),
            TextEntry::make('dispatch_issue')
                ->label('Проблема запуска')
                ->state(fn (BroadcastCampaign $record): string => BroadcastFailurePresentation::label($record->last_dispatch_error_code))
                ->visible(fn (BroadcastCampaign $record): bool => filled($record->last_dispatch_error_code))
                ->columnSpanFull()
                ->wrap(),
            TextEntry::make('creator.name')->label('Создал')->placeholder('Сотрудник удалён'),
            TextEntry::make('created_at')->label('Создано')->dateTime('d.m.Y H:i'),
            TextEntry::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')
                    ->label('Рассылка')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => app(BroadcastCampaignName::class)->displayName($state))
                    ->limit(48)
                    ->tooltip(fn (BroadcastCampaign $record): string => app(BroadcastCampaignName::class)->displayName($record->name))
                    ->wrap()
                    ->width('16rem'),
                TextColumn::make('state')
                    ->label('Состояние')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::stateLabel($state instanceof BroadcastCampaignState ? $state : BroadcastCampaignState::from((string) $state)))
                    ->width('9rem'),
                TextColumn::make('scheduled_at')
                    ->label(fn (): string => 'Запуск ('.app(OrganizationContext::class)->defaultTimezone().')')
                    ->dateTime('d.m.Y H:i')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                    ->placeholder('Сразу')
                    ->sortable()
                    ->width('12rem'),
                TextColumn::make('audience_count')->label('Получатели')->numeric()->sortable()->width('7rem'),
                TextColumn::make('delivery_summary')
                    ->label('Результат')
                    ->state(fn (BroadcastCampaign $record): string => "Доставлено {$record->delivered_count} · ошибок {$record->failed_count} · исключено {$record->suppressed_count}")
                    ->limit(48)
                    ->tooltip(fn (BroadcastCampaign $record): string => "Доставлено {$record->delivered_count} · ошибок {$record->failed_count} · исключено {$record->suppressed_count}")
                    ->wrap()
                    ->width('14rem'),
                TextColumn::make('creator.name')->label('Создал')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('updated_at')->orderByDesc('id'))
            ->recordActions([
                ViewAction::make()
                    ->label('Открыть')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip('Открыть рассылку'),
                EditAction::make()
                    ->label('Редактировать')
                    ->icon(Heroicon::OutlinedPencil)
                    ->iconButton()
                    ->tooltip('Редактировать рассылку')
                    ->visible(fn (BroadcastCampaign $record): bool => $record->state === BroadcastCampaignState::Draft),
            ]);
    }

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows($actor, app(OrganizationContext::class)->organization(), OrganizationPermission::ViewScenarios);
    }

    public static function canCreate(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows($actor, app(OrganizationContext::class)->organization(), OrganizationPermission::ManageScenarios);
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate() && $record instanceof BroadcastCampaign && $record->state === BroadcastCampaignState::Draft;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('organization_id', app(OrganizationContext::class)->id())->with(['creator', 'audienceSnapshot', 'russianTemplateVersion', 'englishTemplateVersion']);
    }

    public static function getPages(): array
    {
        return ['index' => ListBroadcastCampaigns::route('/'), 'create' => CreateBroadcastCampaign::route('/create'), 'view' => ViewBroadcastCampaign::route('/{record}'), 'edit' => EditBroadcastCampaign::route('/{record}/edit')];
    }

    public static function getRelations(): array
    {
        return [RecipientsRelationManager::class];
    }

    /** @return array<string, string> */
    private static function templateOptions(string $locale): array
    {
        $organizationId = app(OrganizationContext::class)->id();

        return NotificationTemplateVersion::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'published')
            ->whereHas('template', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('locale', $locale)
                ->where('purpose', 'marketing')
                ->where('is_active', true)
                ->where('template_key', 'not like', 'broadcast-campaign-%'))
            ->with('template')
            ->latest('id')
            ->get()
            ->filter(static function (NotificationTemplateVersion $version): bool {
                if (array_diff($version->variables, ScenarioTemplateVariableCatalog::allowedForPurpose(ScenarioRulePurpose::Marketing)) !== []) {
                    return false;
                }

                try {
                    return array_diff(
                        ScenarioTemplateVariableCatalog::used($version->body, (string) $version->subject),
                        $version->variables,
                    ) === [];
                } catch (\InvalidArgumentException) {
                    return false;
                }
            })
            ->mapWithKeys(fn (NotificationTemplateVersion $version): array => [$version->getKey() => ($version->template?->name ?: 'Сообщение').' · '.Str::limit(RichTextPresentation::text($version->body), 80).' · '.self::localeLabel($locale)])
            ->all();
    }

    /** @return array<string, string> */
    private static function filterOptions(): array
    {
        return ['tag' => 'Метка клиента', 'b2b_role' => 'B2B-роль', 'b2b_specialist_answer' => 'B2B-сегмент специалиста', 'survey_completed' => 'Завершён тест', 'visit_count' => 'Количество завершённых визитов', 'booking_status' => 'Статус записи', 'last_visit' => 'Дата последнего визита', 'no_future_booking' => 'Нет будущей записи', 'referral_relationship' => 'Пришёл по рекомендации', 'attribution_source' => 'Источник привлечения', 'language' => 'Язык', 'verified_channel' => 'Подтверждённый канал'];
    }

    /** @return array<string, string> */
    private static function operatorOptions(string $key): array
    {
        return match ($key) {
            'tag', 'b2b_role', 'b2b_specialist_answer', 'booking_status', 'attribution_source', 'language' => ['equals' => 'Равно', 'in' => 'Одно из'], 'visit_count' => ['gte' => 'Не меньше'], 'last_visit' => ['before' => 'Раньше', 'after' => 'Позже'], default => ['equals' => 'Равно']
        };
    }

    private static function isBooleanFilter(string $key): bool
    {
        return in_array($key, ['survey_completed', 'no_future_booking', 'referral_relationship'], true);
    }

    private static function isControlledFilter(string $key): bool
    {
        return in_array($key, ['tag', 'b2b_specialist_answer', 'language', 'verified_channel', 'booking_status'], true);
    }

    private static function conditionValueLabel(string $key, bool $multiple = false): string
    {
        return match ($key) {
            'tag' => 'Метка клиента',
            'b2b_specialist_answer' => 'Ответ по B2B',
            'language' => 'Язык',
            'verified_channel' => 'Канал',
            'booking_status' => 'Статус записи',
            'b2b_role' => 'B2B-роль',
            'visit_count' => 'Количество визитов',
            'last_visit' => 'Дата последнего визита',
            'attribution_source' => 'Источник привлечения',
            default => $multiple ? 'Значения условия' : 'Значение условия',
        };
    }

    /** @return array<string, string> */
    private static function controlledValueOptions(string $key): array
    {
        return match ($key) {
            'tag' => BroadcastClientTag::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->orderBy('tag')
                ->distinct()
                ->pluck('tag', 'tag')
                ->all(),
            'b2b_specialist_answer' => ['yes' => 'Да', 'no' => 'Нет'],
            'language' => ['ru' => 'Русский', 'en' => 'Английский'],
            'verified_channel' => ['telegram' => 'Telegram'],
            'booking_status' => collect(BookingStatus::cases())->mapWithKeys(fn (BookingStatus $status): array => [
                $status->value => self::bookingStatusLabel($status),
            ])->all(),
            default => [],
        };
    }

    private static function bookingStatusLabel(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Requested => 'Ожидает подтверждения',
            BookingStatus::PendingReview => 'На рассмотрении',
            BookingStatus::Confirmed => 'Подтверждена',
            BookingStatus::Rejected => 'Отклонена',
            BookingStatus::Cancelled => 'Отменена',
            BookingStatus::Completed => 'Завершена',
            BookingStatus::NoShow => 'Не состоялась',
        };
    }

    private static function recipientPreview(Get $get): string
    {
        return match ($get('audience_type')) {
            'selected' => 'Получателей: '.(is_array($get('selected_client_ids')) ? count($get('selected_client_ids')) : 0),
            'all' => 'Получателей: все клиенты с согласием',
            'segment' => 'Получателей: количество будет рассчитано после сохранения',
            default => 'Выберите клиентов',
        };
    }

    private static function conditionPreview(Get $get): string
    {
        $key = (string) $get('key');
        if ($key === '') {
            return 'Выберите, что проверить.';
        }

        $values = self::conditionValues($get);
        if ($values === '') {
            return 'Укажите значение условия.';
        }

        $operator = (string) $get('operator');
        $comparison = $operator === 'in' ? ' — один из: ' : ' — ';

        return 'Будут выбраны клиенты, у которых '.mb_strtolower(self::filterOptions()[$key] ?? 'условие').$comparison.$values.'.';
    }

    private static function conditionValues(Get $get): string
    {
        $key = (string) $get('key');
        $operator = (string) $get('operator');
        $value = self::isBooleanFilter($key)
            ? (($get('value_bool') === '1' || $get('value_bool') === 1) ? 'Да' : (($get('value_bool') === '0' || $get('value_bool') === 0) ? 'Нет' : ''))
            : ($operator === 'in' ? ($get('value_select_list') ?: $get('value_list')) : ($get('value_select') ?: $get('value_text')));
        $values = is_array($value) ? $value : [$value];

        return collect($values)->filter(fn (mixed $item): bool => $item !== null && $item !== '')->map(function (mixed $item) use ($key): string {
            return self::controlledValueOptions($key)[$item] ?? (string) $item;
        })->implode(', ');
    }

    /** @return array<string, string> */
    private static function clientSearch(string $search): array
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return [];
        }
        if (! app(OrganizationFeatureGate::class)->isEnabled(app(OrganizationContext::class)->organization(), OrganizationFeature::ClientRecords)) {
            return [];
        }

        return app(ClientSearch::class)
            ->query($actor, $search)
            ->with('channelIdentities')
            ->orderBy('full_name')
            ->get()
            ->mapWithKeys(fn (Client $client): array => [$client->getKey() => self::clientDisplayLabel($client)])
            ->all();
    }

    /** @return array<string, string> */
    private static function clientOptions(): array
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return [];
        }
        if (! app(OrganizationFeatureGate::class)->isEnabled(app(OrganizationContext::class)->organization(), OrganizationFeature::ClientRecords)) {
            return [];
        }

        return app(ClientSearch::class)
            ->selectionQuery($actor)
            ->with('channelIdentities')
            ->get()
            ->mapWithKeys(fn (Client $client): array => [$client->getKey() => self::clientDisplayLabel($client)])
            ->all();
    }

    private static function clientLabel(mixed $value): ?string
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $client = Client::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with('channelIdentities')
            ->find((int) $value);

        return $client instanceof Client ? self::clientDisplayLabel($client) : null;
    }

    private static function clientDisplayLabel(Client $client): string
    {
        $parts = [trim((string) $client->full_name) ?: 'Клиент #'.$client->getKey()];
        if (filled($client->phone)) {
            $parts[] = (string) $client->phone;
        }
        $username = $client->channelIdentities
            ->first(fn ($identity): bool => $identity->channel === 'telegram')?->external_username;
        if (filled($username)) {
            $parts[] = '@'.ltrim((string) $username, '@');
        }

        $telegramId = $client->channelIdentities
            ->first(fn ($identity): bool => $identity->channel === 'telegram')?->external_id;
        if (filled($telegramId)) {
            $parts[] = 'Telegram ID: '.(string) $telegramId;
        }

        return implode(' · ', $parts);
    }

    private static function deliveryIncludesText(Get $get): bool
    {
        return NotificationMessageMode::tryFrom((string) $get('delivery_mode'))?->includesText() ?? true;
    }

    private static function deliveryUsesCaption(Get $get): bool
    {
        return NotificationMessageMode::tryFrom((string) $get('delivery_mode'))?->usesCaption() ?? false;
    }

    private static function messageCounter(Get $get): string
    {
        $mode = NotificationMessageMode::tryFrom((string) $get('delivery_mode'));
        $limit = $mode?->usesCaption() === true
            ? RichTextDocument::TELEGRAM_CAPTION_LIMIT
            : RichTextDocument::TELEGRAM_TEXT_LIMIT;
        $body = $get('message_body');

        if (! is_string($body) || trim($body) === '') {
            return '0 / '.$limit;
        }

        try {
            $length = RichTextDocument::telegramLength($body);
        } catch (\InvalidArgumentException) {
            return 'Проверьте формат текста · лимит '.$limit;
        }

        return $length.' / '.$limit;
    }

    private static function imageUploadKilobytes(): int
    {
        $bytes = max(1, (int) config('content_media.max_bytes', 5_242_880));

        return intdiv($bytes + 1023, 1024);
    }

    private static function hasMedia(?BroadcastCampaign $campaign): bool
    {
        return self::mediaImage($campaign) !== null;
    }

    private static function mediaImage(?BroadcastCampaign $campaign): ?string
    {
        $media = $campaign?->media;
        $image = is_array($media) ? $media['image'] ?? null : null;

        return is_string($image) && trim($image) !== '' ? trim($image) : null;
    }

    private static function mediaPreviewUrl(?BroadcastCampaign $campaign): ?string
    {
        $image = self::mediaImage($campaign);

        if ($image === null || ! $campaign instanceof BroadcastCampaign) {
            return null;
        }

        $media = app(BroadcastCampaignMedia::class);

        if ($media->isManagedPath((int) $campaign->organization_id, $image)) {
            return $media->url($image);
        }

        try {
            return ContentExternalImageUrl::required($image)->value;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function mediaAlt(?BroadcastCampaign $campaign): string
    {
        $media = $campaign?->media;
        $alt = is_array($media) ? $media['alt'] ?? null : null;

        return is_string($alt) && trim($alt) !== '' ? trim($alt) : 'Изображение рассылки';
    }

    private static function mediaStatus(?BroadcastCampaign $campaign): string
    {
        $image = self::mediaImage($campaign);

        if ($image === null || ! $campaign instanceof BroadcastCampaign) {
            return 'Изображение не добавлено.';
        }

        $kind = app(BroadcastCampaignMedia::class)->isManagedPath((int) $campaign->organization_id, $image)
            ? 'Загруженный файл'
            : 'Внешняя HTTPS-ссылка';
        $alt = self::mediaAlt($campaign);

        return $kind.' · '.($alt === 'Изображение рассылки' ? 'описание не задано' : 'описание: '.$alt).'. Новая загрузка или ссылка заменит текущее изображение после сохранения.';
    }

    private static function mediaSummary(BroadcastCampaign $campaign): string
    {
        if (! self::hasMedia($campaign)) {
            return 'Не добавлено';
        }

        $alt = is_array($campaign->media) ? $campaign->media['alt'] ?? null : null;

        return is_string($alt) && trim($alt) !== ''
            ? 'Изображение добавлено · '.trim($alt)
            : 'Изображение добавлено';
    }

    private static function localeLabel(string $locale): string
    {
        return $locale === 'ru' ? 'Русский' : 'Английский';
    }

    private static function stateLabel(BroadcastCampaignState $state): string
    {
        return match ($state) {
            BroadcastCampaignState::Draft => 'Черновик', BroadcastCampaignState::Scheduled => 'Запланирована', BroadcastCampaignState::Dispatching => 'Отправляется', BroadcastCampaignState::Completed => 'Завершена', BroadcastCampaignState::Cancelled => 'Отменена'
        };
    }

    private static function failureLabel(string $code): string
    {
        return BroadcastFailurePresentation::label($code);
    }
}
