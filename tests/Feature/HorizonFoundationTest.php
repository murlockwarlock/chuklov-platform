<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HorizonFoundationTest extends TestCase
{
    public function test_horizon_metrics_snapshot_is_scheduled(): void
    {
        self::assertSame(0, Artisan::call('schedule:list'));
        self::assertStringContainsString('horizon:snapshot', Artisan::output());
    }
}
