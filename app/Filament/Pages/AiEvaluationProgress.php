<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

final class AiEvaluationProgress extends Page
{
    protected static ?string $slug = 'ai-evaluation-progress/{progressKey}';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Проверка AI';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected string $view = 'filament.pages.ai-evaluation-progress';

    public string $progressKey;

    /** @var array<string, mixed> */
    public array $progress = [];

    public static function canAccess(): bool
    {
        $actor = Auth::user();
        $context = app(OrganizationContext::class);

        return $actor instanceof User
            && app(OrganizationAuthorizer::class)->allows(
                $actor,
                $context->organization(),
                OrganizationPermission::ViewAiRuns,
            );
    }

    public function mount(string $progressKey): void
    {
        $this->progressKey = $progressKey;
        $this->progress = $this->readProgress();
    }

    public function refreshProgress(): void
    {
        $this->progress = $this->readProgress();
    }

    public function getHeading(): string
    {
        return 'Проверка AI';
    }

    public function getSubheading(): string
    {
        return 'Результат обновляется автоматически.';
    }

    /** @return array<string, mixed> */
    private function readProgress(): array
    {
        $progress = (array) Cache::get('ai-evaluation-progress:'.$this->progressKey, []);
        if ((int) ($progress['organization_id'] ?? 0) !== app(OrganizationContext::class)->id()) {
            abort(404);
        }

        return $progress === []
            ? ['status' => 'expired', 'message' => 'Ссылка на прогресс больше недоступна.']
            : $progress;
    }
}
