<?php

namespace App\Filament\Resources\LegalDocuments;

use App\Filament\Resources\LegalDocuments\Pages\CreateLegalDocument;
use App\Filament\Resources\LegalDocuments\Pages\EditLegalDocument;
use App\Filament\Resources\LegalDocuments\Pages\ListLegalDocuments;
use App\Filament\Resources\LegalDocuments\Pages\ViewLegalDocument;
use App\Filament\Support\RichTextEditor;
use App\Models\User;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Support\RichText\RichTextDocument;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class LegalDocumentResource extends Resource
{
    protected static ?string $model = LegalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $navigationLabel = 'Документы и согласия';

    protected static string|\UnitEnum|null $navigationGroup = 'Контент и знания';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'юридический документ';

    protected static ?string $pluralModelLabel = 'юридические документы';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Документ')->schema([
                Select::make('document_type')
                    ->label('Тип документа')
                    ->options(self::subjectOptions())
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->native(false),
                TextInput::make('purpose')
                    ->label('Внутреннее назначение')
                    ->helperText('Техническая метка документа. Юридический текст задаёт владелец организации.')
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->maxLength(120),
                Select::make('locale')
                    ->label('Язык')
                    ->options(['ru' => 'Русский', 'en' => 'Английский'])
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->native(false),
                TextInput::make('version')
                    ->label('Версия')
                    ->helperText('Опубликованная версия неизменяема. Для изменения создайте новую draft-версию.')
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->maxLength(64),
                Placeholder::make('required_state')
                    ->label('Подтверждение при записи')
                    ->content(fn (Get $get): string => self::requiredLabel($get('document_type'))),
            ])->columns(2)->columnSpanFull(),
            Section::make('Текст документа')->schema([
                RichTextEditor::make('content')
                    ->label('Текст')
                    ->required()
                    ->helperText('Не добавляйте сюда данные клиентов. Текст должен быть подготовлен владельцем организации.')
                    ->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('document_type')
                ->label('Тип документа')
                ->formatStateUsing(fn (string $state): string => self::subjectOptions()[$state] ?? $state),
            TextEntry::make('purpose')->label('Внутреннее назначение'),
            TextEntry::make('locale')->label('Язык')->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский'),
            TextEntry::make('version')->label('Версия'),
            TextEntry::make('status')->label('Состояние')->badge()->formatStateUsing(fn ($state): string => self::statusLabel($state instanceof LegalDocumentStatus ? $state : LegalDocumentStatus::from((string) $state))),
            TextEntry::make('is_required')
                ->label('Обязателен при записи')
                ->state(fn (LegalDocument $record): string => self::requiredLabel($record->document_type)),
            TextEntry::make('content')
                ->label('Текст')
                ->formatStateUsing(fn (string $state): string => RichTextDocument::plainText($state))
                ->columnSpanFull()
                ->prose(),
            TextEntry::make('published_at')->label('Опубликован')->dateTime('d.m.Y H:i')->placeholder('Не опубликован'),
            TextEntry::make('archived_at')->label('Архивирован')->dateTime('d.m.Y H:i')->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_type')->label('Документ')->formatStateUsing(fn (string $state): string => self::subjectOptions()[$state] ?? $state)->searchable(),
                TextColumn::make('locale')->label('Язык')->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский'),
                TextColumn::make('version')->label('Версия'),
                TextColumn::make('status')->label('Состояние')->badge()->formatStateUsing(fn ($state): string => self::statusLabel($state instanceof LegalDocumentStatus ? $state : LegalDocumentStatus::from((string) $state))),
                TextColumn::make('is_required')
                    ->label('Обязателен')
                    ->state(fn (LegalDocument $record): string => ConsentSubject::tryFrom((string) $record->document_type)?->isRequired() === true ? 'Да' : 'Нет'),
                TextColumn::make('updated_at')->label('Изменён')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageSettings,
        );
    }

    public static function canCreate(): bool
    {
        return self::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canAccess() && $record instanceof LegalDocument && $record->status === LegalDocumentStatus::Draft;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->orderByDesc('updated_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegalDocuments::route('/'),
            'create' => CreateLegalDocument::route('/create'),
            'view' => ViewLegalDocument::route('/{record}'),
            'edit' => EditLegalDocument::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    public static function subjectOptions(): array
    {
        return collect(ConsentSubject::cases())
            ->mapWithKeys(fn (ConsentSubject $subject): array => [$subject->value => $subject->label('ru')])
            ->all();
    }

    private static function requiredLabel(mixed $documentType): string
    {
        $subject = is_string($documentType) ? ConsentSubject::tryFrom($documentType) : null;

        return $subject?->isRequired() === true ? 'Да — требуется при создании записи' : 'Нет — отдельное необязательное согласие';
    }

    private static function statusLabel(LegalDocumentStatus $status): string
    {
        return match ($status) {
            LegalDocumentStatus::Draft => 'Черновик',
            LegalDocumentStatus::Published => 'Опубликован',
            LegalDocumentStatus::Archived => 'Архив',
        };
    }
}
