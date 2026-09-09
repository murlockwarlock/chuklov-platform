<?php

namespace App\Providers\Filament;

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
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile()
            ->multiFactorAuthentication([AuditedAppAuthentication::make()->recoverable()], isRequired: true)
            ->userMenuItems([
                'revoke-privileged-sessions' => Action::make('revokePrivilegedSessions')
                    ->label('Завершить все сеансы')
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
            $panel->assets([
                Js::make('chuklov-rich-text-editor', Vite::asset('resources/js/filament/rich-text-editor.ts'))->module(),
            ]);
        }

        return $panel
            ->spa()
            ->databaseNotifications()
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
                'Клиенты',
                'Записи',
                'Настройки',
                'Команда и услуги',
                'Коммуникации',
                'Партнёры',
                'Контент и знания',
                'Искусственный интеллект',
                'Финансы',
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                SetAdminLocale::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
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
            ]);
    }
}
