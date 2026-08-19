<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Application\KnowledgeAuthorization;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/** @property-read Schema $form */
final class KnowledgeRetrievalInspector extends Page
{
    protected static ?string $navigationLabel = 'Поиск по знаниям';

    protected static ?string $title = 'Поиск по базе знаний';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Контент и знания';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.knowledge-retrieval-inspector';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    /** @var list<array<string, mixed>> */
    public array $results = [];

    public bool $hasSearched = false;

    public static function canAccess(): bool
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return false;
        }

        try {
            app(KnowledgeAuthorization::class)->authorizeView($actor, app(OrganizationContext::class)->organization());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function mount(): void
    {
        $this->form->fill(['top_k' => (int) config('rag.retrieval.default_top_k')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Поиск')->schema([
                TextInput::make('query')->label('Запрос')->required()->maxLength(4000)->columnSpanFull(),
            ]),
            Section::make('Фильтры')->schema([
                Select::make('source_ids')->label('Источники')->multiple()->maxItems(20)->searchable()->options(fn (): array => KnowledgeSource::query()
                    ->where('organization_id', app(OrganizationContext::class)->id())
                    ->where('status', 'active')
                    ->whereHas('activeRevision', fn (Builder $query): Builder => $query->where('status', 'ready'))
                    ->orderBy('title')
                    ->limit(100)
                    ->pluck('title', 'id')
                    ->all()),
                Select::make('top_k')->label('Показать результатов')->options([3 => '3', 5 => '5', 10 => '10', 20 => '20'])->required(),
            ])->columns(2),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('knowledge-search-form')
            ->livewireSubmitHandler('search')
            ->footer([Actions::make([Action::make('search')->label('Найти')->submit('search')])]);
    }

    public function search(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();

        $this->hasSearched = true;
        $this->results = [];
        try {
            $results = app(KnowledgeRetriever::class)->retrieve($actor, new RetrievalQuery(
                text: (string) $data['query'],
                topK: (int) $data['top_k'],
                sourceIds: array_values(array_map('intval', $data['source_ids'] ?? [])),
            ));
            $this->results = [];
            foreach ($results as $index => $result) {
                $this->results[] = [
                    'rank' => $index + 1,
                    'source_title' => $result->sourceTitle,
                    'content' => Str::limit($result->content, 1200),
                ];
            }
        } catch (\Throwable) {
            Notification::make()->title('Поиск недоступен')->body('Не удалось выполнить поиск по доступным материалам.')->danger()->send();
        }
    }
}
