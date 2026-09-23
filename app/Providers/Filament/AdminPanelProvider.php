<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Livewire\DatabaseNotifications;
use App\Filament\Support\ConfigureCrmLocalization;
use App\Http\Controllers\RevokePrivilegedSessionsController;
use App\Http\Middleware\EnsurePrivilegedSessionIsCurrent;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\SetAdminLocale;
use App\Modules\Security\Infrastructure\Filament\AuditedAppAuthentication;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        ConfigureCrmLocalization::register();

        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->profile(EditProfile::class)
            ->multiFactorAuthentication([AuditedAppAuthentication::make()->recoverable()], isRequired: true)
            ->userMenuItems([
                'revoke-privileged-sessions' => Action::make('revokePrivilegedSessions')
                    ->label(__('Завершить все сеансы'))
                    ->icon(Heroicon::ArrowLeftEndOnRectangle)
                    ->url(fn (): string => route('filament.admin.security.revoke-sessions'))
                    ->postToUrl(),
            ])
            ->favicon(asset('brand/chuklov-designer-logo-en.jpg'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css');

        if (Vite::isRunningHot() || is_file(public_path('build/manifest.json'))) {
            $assets = [
                Js::make('chuklov-rich-text-editor', Vite::asset('resources/js/filament/rich-text-editor.ts'))->module(),
            ];
            $manifest = is_file(public_path('build/manifest.json'))
                ? json_decode((string) file_get_contents(public_path('build/manifest.json')), true)
                : [];

            if (Vite::isRunningHot() || is_array($manifest) && array_key_exists('resources/js/filament/database-notification-sound.ts', $manifest)) {
                $assets[] = Js::make('chuklov-database-notification-sound', Vite::asset('resources/js/filament/database-notification-sound.ts'))->module();
            }

            $panel->assets($assets);
        }

        return $panel
            ->spa()
            ->databaseNotifications(livewireComponent: DatabaseNotifications::class)
            ->databaseNotificationsPolling('30s')
            ->spaUrlExceptions([
                '*/admin/attachments/*',
                '*/admin/finance/receipts/*',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->authenticatedRoutes(function (): void {
                Route::post('/security/revoke-sessions', RevokePrivilegedSessionsController::class)
                    ->name('security.revoke-sessions');
            })
            ->pages([])
            ->navigationGroups([
                'Записи' => NavigationGroup::make(fn (): string => __('Записи')),
                'Клиенты' => NavigationGroup::make(fn (): string => __('Клиенты')),
                'Коммуникации' => NavigationGroup::make(fn (): string => __('Коммуникации')),
                'Настройки' => NavigationGroup::make(fn (): string => __('Настройки')),
                'Команда и услуги' => NavigationGroup::make(fn (): string => __('Команда и услуги')),
                'Партнёры' => NavigationGroup::make(fn (): string => __('Партнёры')),
                'Контент и знания' => NavigationGroup::make(fn (): string => __('Контент и знания')),
                'Искусственный интеллект' => NavigationGroup::make(fn (): string => __('Искусственный интеллект')),
                'Финансы' => NavigationGroup::make(fn (): string => __('Финансы')),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetAdminLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                EnsurePrivilegedSessionIsCurrent::class,
                Authenticate::class,
                ResolveOrganization::class,
            ])
            ->persistentMiddleware([
                EnsurePrivilegedSessionIsCurrent::class,
                ResolveOrganization::class,
                SetAdminLocale::class,
            ]);
    }
}
