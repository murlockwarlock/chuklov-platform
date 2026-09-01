<?php

namespace Tests\Integration;

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class B2bQueueRedisContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_B2B_REDIS_CONTRACT_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_B2B_REDIS_CONTRACT_TESTS=1 to run the read-only Redis queue contract checks.');
        }
    }

    public function test_laravel_pings_and_counts_the_configured_named_queue_connection(): void
    {
        $connectionName = config('queue.connections.redis.connection');
        self::assertIsString($connectionName);
        self::assertNotSame('', $connectionName);
        self::assertIsArray(config('database.redis.'.$connectionName));

        $redis = Redis::connection($connectionName);
        self::assertSame('PONG', strtoupper((string) $redis->command('ping')));

        $queue = Queue::connection('redis');
        self::assertInstanceOf(RedisQueue::class, $queue);
        self::assertSame($connectionName, $queue->getConnection()->getName());
        self::assertSame('PONG', strtoupper((string) $queue->getConnection()->command('ping')));

        foreach (['pendingSize', 'delayedSize', 'reservedSize'] as $method) {
            self::assertGreaterThanOrEqual(0, $queue->{$method}((string) config('b2b.queue')));
        }
    }

    public function test_laravel_can_use_a_non_default_named_queue_connection_without_writes(): void
    {
        $cacheConfiguration = config('database.redis.cache');
        if (! is_array($cacheConfiguration)) {
            self::markTestSkipped('The cache Redis connection is not configured.');
        }

        config()->set('queue.connections.redis.connection', 'cache');
        Queue::purge('redis');

        $queue = Queue::connection('redis');
        self::assertInstanceOf(RedisQueue::class, $queue);
        self::assertSame('cache', $queue->getConnection()->getName());
        self::assertSame('PONG', strtoupper((string) $queue->getConnection()->command('ping')));
        self::assertGreaterThanOrEqual(0, $queue->pendingSize((string) config('b2b.queue')));
        self::assertGreaterThanOrEqual(0, $queue->delayedSize((string) config('b2b.queue')));
        self::assertGreaterThanOrEqual(0, $queue->reservedSize((string) config('b2b.queue')));
    }

    public function test_effective_url_database_and_prefix_values_are_distinguishable(): void
    {
        $parser = new ConfigurationUrlParser;
        $first = $parser->parseConfiguration([
            'url' => 'redis://example.test:6379/0?prefix=queue-a-',
            'host' => 'ignored.test',
            'port' => '6380',
            'database' => '4',
        ]);
        $second = $parser->parseConfiguration([
            'url' => 'redis://example.test:6379/1?prefix=queue-b-',
            'host' => 'ignored.test',
            'port' => '6380',
            'database' => '4',
        ]);

        self::assertSame('example.test', $first['host']);
        self::assertSame('0', $first['database']);
        self::assertSame('queue-a-', $first['prefix']);
        self::assertSame('1', $second['database']);
        self::assertSame('queue-b-', $second['prefix']);
        self::assertNotSame([$first['database'], $first['prefix']], [$second['database'], $second['prefix']]);
    }
}
