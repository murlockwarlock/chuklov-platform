<?php

namespace App\Providers;

use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OrganizationContext::class);
    }

    public function boot(): void {}
}
