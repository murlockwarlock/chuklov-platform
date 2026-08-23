<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Modules\ClientCompanion\Application\Services\GetCompanionContextSettings;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class CompanionSettings extends Page
{
    protected static ?string $title = 'Настройки AI-компаньона';

    protected static ?string $navigationLabel = 'Настройки AI-компаньона';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Искусственный интеллект';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    protected string $view = 'filament.pages.companion-settings';

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
        $settings = app(GetCompanionContextSettings::class)->handle(app(OrganizationContext::class)->organization());
        $this->form->fill($settings);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_exchanges')
                    ->label('Сохранять начало диалога')
                    ->helperText('Количество первых обменов текущего диалога, которые сохраняются в контексте.')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(20)
                    ->required(),
                TextInput::make('recent_exchanges')
                    ->label('Использовать последние обмены')
                    ->helperText('При длинной переписке середина перестаёт передаваться модели. Платформа также ограничивает общий объём контекста.')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(20)
                    ->required(),
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
            ->id('form')
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
        $action = app(SetOrganizationSetting::class);
        $action->handle($actor, OrganizationSettingKey::CompanionContextFirstExchanges, (int) $data['first_exchanges']);
        $action->handle($actor, OrganizationSettingKey::CompanionContextRecentExchanges, (int) $data['recent_exchanges']);

        Notification::make()->success()->title('Настройки AI-компаньона сохранены')->send();
    }
}
