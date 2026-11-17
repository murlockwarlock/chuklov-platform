<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Attachments\Application\MedicalFilePolicy;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
final class MedicalFileSettings extends LocalizedPage
{
    protected static ?string $title = 'Медицинские файлы';

    protected static ?string $navigationLabel = 'Медицинские файлы';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocument;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 39;

    protected string $view = 'filament.pages.medical-file-settings';

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
        $bytes = app(MedicalFilePolicy::class)->maxBytes(app(OrganizationContext::class)->id());
        $this->form->fill(['max_mb' => (int) ceil($bytes / 1024 / 1024)]);
    }

    public function form(Schema $schema): Schema
    {
        $hardCapMb = (int) ceil((int) config('medical.attachment_max_bytes', 20_971_520) / 1024 / 1024);

        return $schema->components([
            Section::make(__('Политика дополнительных файлов'))
                ->description(__('Предел действует для раздела «Файлы» и не меняет системный предел. Утверждённые бизнес-лимиты задаются здесь, а не в коде.'))
                ->schema([
                    TextInput::make('max_mb')
                        ->label(__('Максимальный размер файла, МБ'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue($hardCapMb)
                        ->helperText(__('Системный предел: не более :limit МБ.', ['limit' => $hardCapMb]))
                        ->required(),
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
            ->id('medical-file-settings-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label(__('Сохранить настройки'))->submit('save')])]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();
        app(SetOrganizationSetting::class)->handle(
            $actor,
            OrganizationSettingKey::MedicalGeneralFileBusinessMaxBytes,
            (int) ($data['max_mb'] ?? 0) * 1024 * 1024,
        );
        Notification::make()->success()->title(__('Настройки медицинских файлов сохранены'))->send();
    }
}
