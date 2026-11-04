<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Attachments\Application\MedicalAudioPolicy;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
final class MedicalAudioSettings extends LocalizedPage
{
    protected static ?string $title = 'Аудиозаписи';

    protected static ?string $navigationLabel = 'Аудиозаписи';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 37;

    protected string $view = 'filament.pages.medical-audio-settings';

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
        $policy = app(MedicalAudioPolicy::class);
        $this->form->fill([
            'mime_types' => $policy->mimeTypes($organizationId),
            'max_mb' => (int) ceil($policy->maxBytes($organizationId) / 1024 / 1024),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $policy = app(MedicalAudioPolicy::class);
        $hardCapMb = (int) ceil((int) config('medical.audio_attachment_max_bytes', 67_108_864) / 1024 / 1024);

        return $schema->components([
            Section::make(__('Политика аудиозаписей'))
                ->description(__('Настройка действует без публикации новой версии программы. Серверный hard cap остаётся верхней границей.'))
                ->schema([
                    Select::make('mime_types')
                        ->label(__('Разрешённые форматы'))
                        ->options($policy->labels())
                        ->multiple()
                        ->required(),
                    TextInput::make('max_mb')
                        ->label(__('Максимальный размер, МБ'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue($hardCapMb)
                        ->helperText(__('Не больше :limit МБ — серверного ограничения.', ['limit' => $hardCapMb]))
                        ->required(),
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
            ->id('medical-audio-settings-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label(__('Сохранить настройки'))->submit('save')])]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();
        $setter = app(SetOrganizationSetting::class);
        $setter->handle($actor, OrganizationSettingKey::MedicalAudioAllowedMimeTypes, json_encode(array_values($data['mime_types'] ?? []), JSON_THROW_ON_ERROR));
        $setter->handle($actor, OrganizationSettingKey::MedicalAudioBusinessMaxBytes, (int) $data['max_mb'] * 1024 * 1024);

        Notification::make()->success()->title(__('Политика аудиозаписей сохранена'))->send();
    }
}
