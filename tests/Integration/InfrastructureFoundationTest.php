<?php

namespace Tests\Integration;

use App\Jobs\RecordFoundationProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class InfrastructureFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_and_pgvector_are_available(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $extension = DB::selectOne("select extversion from pg_extension where extname = 'vector'");

        self::assertNotNull($extension);
    }

    public function test_redis_and_queue_worker_process_a_safe_payload(): void
    {
        $probeId = (string) Str::uuid();
        $queue = 'foundation-probe';
        config()->set([
            'cache.default' => 'redis',
            'queue.default' => 'redis',
        ]);

        Redis::set("integration:{$probeId}", 'ok', 'EX', 60);
        self::assertSame('ok', Redis::get("integration:{$probeId}"));

        RecordFoundationProbe::dispatch($probeId)
            ->onConnection('redis')
            ->onQueue($queue);
        self::assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $queue]));

        self::assertTrue(Cache::store('redis')->get("foundation-probe:{$probeId}"));
    }

    public function test_health_endpoint_verifies_foundation_dependencies(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'checks' => [
                    'database' => true,
                    'pgvector' => true,
                    'redis' => true,
                    'private_storage' => true,
                ],
            ]);
    }
}
