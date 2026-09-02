<?php

use App\Models\User;
use App\Modules\B2B\Jobs\ProcessB2bProviderSyncEvent;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Knowledge\Application\CreateKnowledgeSource;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Application\RetireKnowledgeSource;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

require '/app/vendor/autoload.php';

final class StagingSmokeFailure extends RuntimeException {}

/** @return array<string, string> */
function options(): array
{
    $values = getopt('', ['check:', 'user-id:', 'client-id:']);

    return is_array($values) ? array_filter($values, 'is_string') : [];
}

function ok(string $label, ?string $detail = null): void
{
    printf("%-30s OK%s\n", $label, $detail === null ? '' : ' '.$detail);
}

function fail(string $label, string $reason): never
{
    throw new StagingSmokeFailure($label.': '.$reason);
}

/** @return array{0: Application, 1: HttpKernel|ConsoleKernel} */
function bootstrapApplication(bool $http): array
{
    $app = require '/app/bootstrap/app.php';
    if ($http) {
        $app->instance('request', Request::create('https://crm.psysoldatov.ru/', 'GET'));
    }
    $kernel = $app->make($http ? HttpKernel::class : ConsoleKernel::class);
    $kernel->bootstrap();

    return [$app, $kernel];
}

/** @return array{0: Organization, 1: User, 2: Client} */
function smokeIdentity(int $userId, int $clientId): array
{
    $organizationId = config('tenancy.default_organization_id');
    if (! is_int($organizationId) && ! (is_string($organizationId) && ctype_digit($organizationId))) {
        fail('SMOKE IDENTITY', 'server organization is not configured');
    }
    $organization = Organization::query()->find((int) $organizationId);
    if (! $organization instanceof Organization) {
        fail('SMOKE IDENTITY', 'server organization is missing');
    }
    $actor = User::query()
        ->whereKey($userId)
        ->whereHas('memberships', static fn ($query) => $query
            ->where('organization_id', $organization->getKey())
            ->where('is_active', true))
        ->first();
    if (! $actor instanceof User || ! $actor->hasPermission(OrganizationPermission::ViewAdmin, $organization)) {
        fail('SMOKE IDENTITY', 'configured user cannot access CRM');
    }
    $client = Client::query()
        ->where('organization_id', $organization->getKey())
        ->find($clientId);
    if (! $client instanceof Client) {
        fail('SMOKE IDENTITY', 'configured client is outside the server organization or missing');
    }
    app(OrganizationContext::class)->set($organization);

    return [$organization, $actor, $client];
}

function horizonEnvironment(): string
{
    $environment = config('horizon.env') ?? config('app.env');

    if (! is_string($environment) || $environment === '') {
        fail('HORIZON', 'environment is not configured');
    }

    return $environment;
}

function configuredHorizonSupervisor(): array
{
    $defaults = config('horizon.defaults.supervisor-1');
    if (! is_array($defaults)) {
        fail('HORIZON', 'supervisor defaults are not configured');
    }

    $overrides = config('horizon.environments.'.horizonEnvironment().'.supervisor-1', []);
    if (! is_array($overrides)) {
        fail('HORIZON', 'supervisor environment override is not configured');
    }

    return array_replace_recursive($defaults, $overrides);
}

function queueList(mixed $value): ?array
{
    if (is_array($value)) {
        foreach ($value as $queue) {
            if (! is_string($queue)) {
                return null;
            }
        }

        return array_values($value);
    }

    return is_string($value) ? explode(',', $value) : null;
}

function runtimeArray(mixed $value): ?array
{
    if (is_array($value)) {
        return $value;
    }

    if (! is_string($value)) {
        return null;
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : null;
}

function effectiveRedisPrefix(array $redisConfiguration, array $parsedConfiguration): ?string
{
    $prefix = null;
    $globalOptions = is_array($redisConfiguration['options'] ?? null) ? $redisConfiguration['options'] : [];
    $namedOptions = is_array($parsedConfiguration['options'] ?? null) ? $parsedConfiguration['options'] : [];

    if (array_key_exists('prefix', $globalOptions)) {
        $prefix = $globalOptions['prefix'];
    }
    if (array_key_exists('prefix', $namedOptions)) {
        $prefix = $namedOptions['prefix'];
    }
    if (array_key_exists('prefix', $parsedConfiguration) && $parsedConfiguration['prefix'] !== null) {
        $prefix = $parsedConfiguration['prefix'];
    }

    if ($prefix === null || $prefix === false || $prefix === '') {
        return null;
    }

    if (! is_string($prefix)) {
        fail('B2B QUEUE', 'Redis prefix is not a string');
    }

    return $prefix;
}

function effectiveRedisEndpoint(array $parsedConfiguration): array
{
    $host = $parsedConfiguration['host'] ?? null;
    if (! is_string($host) || $host === '') {
        fail('B2B QUEUE', 'Redis host is not configured');
    }

    $hostScheme = parse_url($host, PHP_URL_SCHEME);
    $hostPort = null;
    if (is_string($hostScheme)) {
        $hostUrl = parse_url($host);
        if (! is_array($hostUrl) || ! is_string($hostUrl['host'] ?? null) || $hostUrl['host'] === '') {
            fail('B2B QUEUE', 'Redis host configuration is not resolvable');
        }

        $host = $hostUrl['host'];
        $hostPort = $hostUrl['port'] ?? null;
    }

    $configuredPort = $parsedConfiguration['port'] ?? null;
    if ($hostPort !== null) {
        if ($configuredPort !== null && (string) $configuredPort !== (string) $hostPort) {
            fail('B2B QUEUE', 'Redis host and port configuration is ambiguous');
        }
        $configuredPort = $hostPort;
    }
    $configuredPort ??= 6379;

    if ((is_int($configuredPort) && $configuredPort >= 1 && $configuredPort <= 65535)
        || (is_string($configuredPort) && preg_match('/\A[0-9]{1,5}\z/', $configuredPort) === 1
            && (int) $configuredPort >= 1 && (int) $configuredPort <= 65535)) {
        $port = (int) $configuredPort;
    } else {
        fail('B2B QUEUE', 'Redis port is invalid');
    }

    $configuredDatabase = $parsedConfiguration['database'] ?? 0;
    if (is_int($configuredDatabase) && $configuredDatabase >= 0) {
        $database = $configuredDatabase;
    } elseif (is_string($configuredDatabase) && preg_match('/\A[0-9]+\z/', $configuredDatabase) === 1) {
        $database = (int) $configuredDatabase;
    } else {
        fail('B2B QUEUE', 'Redis database is invalid');
    }

    $driver = $parsedConfiguration['driver'] ?? null;
    $driver = is_string($driver) ? strtolower($driver) : null;
    $scheme = $parsedConfiguration['scheme'] ?? null;
    if (in_array($driver, ['tcp', 'tls'], true)) {
        $scheme = $driver;
    } elseif (! is_string($scheme) || $scheme === '') {
        $scheme = is_string($hostScheme) ? $hostScheme : 'tcp';
    }
    $scheme = strtolower($scheme);
    $scheme = match ($scheme) {
        'redis', 'tcp' => 'tcp',
        'rediss', 'tls' => 'tls',
        default => fail('B2B QUEUE', 'Redis scheme is unsupported'),
    };

    return [
        'scheme' => $scheme,
        'host' => strtolower($host),
        'port' => $port,
        'database' => $database,
    ];
}

function queueRedisTarget(): array
{
    $queueConfiguration = config('queue.connections.redis');
    if (! is_array($queueConfiguration) || ($queueConfiguration['driver'] ?? null) !== 'redis') {
        fail('B2B QUEUE', 'Laravel redis queue connection is not configured');
    }

    $connectionName = $queueConfiguration['connection'] ?? null;
    if (! is_string($connectionName) || $connectionName === '') {
        fail('B2B QUEUE', 'Laravel redis queue connection name is invalid');
    }

    $redisConfiguration = config('database.redis');
    if (! is_array($redisConfiguration)
        || ! array_key_exists($connectionName, $redisConfiguration)
        || ! is_array($redisConfiguration[$connectionName])) {
        fail('B2B QUEUE', 'Laravel redis queue connection name is not configured');
    }

    $namedConfiguration = $redisConfiguration[$connectionName];
    try {
        $parsedConfiguration = (new ConfigurationUrlParser)->parseConfiguration($namedConfiguration);
    } catch (Throwable) {
        fail('B2B QUEUE', 'Laravel Redis URL configuration is invalid');
    }
    if (! is_array($parsedConfiguration)) {
        fail('B2B QUEUE', 'Laravel Redis configuration is invalid');
    }

    $queue = config('b2b.queue');
    if (! is_string($queue) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $queue) !== 1) {
        fail('B2B QUEUE', 'B2B queue is invalid');
    }

    $endpoint = effectiveRedisEndpoint($parsedConfiguration);
    $identity = [
        'driver' => 'redis',
        ...$endpoint,
        'prefix' => effectiveRedisPrefix($redisConfiguration, $parsedConfiguration),
        'queue' => $queue,
    ];

    return [
        'connection' => $connectionName,
        'queue' => $queue,
        'identity' => $identity,
        'fingerprint' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
    ];
}

function assertRedisPing(mixed $result, string $label): void
{
    if ($result === true || (is_string($result) && strtoupper($result) === 'PONG')) {
        return;
    }

    fail($label, 'Redis PING returned an unexpected response');
}

function queueContractSnapshot(): array
{
    $target = queueRedisTarget();
    try {
        $connection = Redis::connection($target['connection']);
        assertRedisPing($connection->command('ping'), 'B2B QUEUE');
    } catch (StagingSmokeFailure $exception) {
        throw $exception;
    } catch (Throwable) {
        fail('B2B QUEUE', 'configured Laravel Redis connection is unavailable');
    }

    $queue = Queue::connection('redis');
    if (! $queue instanceof RedisQueue) {
        fail('B2B QUEUE', 'Laravel redis queue driver is not Redis');
    }

    $queueConnection = $queue->getConnection();
    if ($queueConnection->getName() !== $target['connection']) {
        fail('B2B QUEUE', 'queue driver resolved a different Redis connection');
    }
    try {
        assertRedisPing($queueConnection->command('ping'), 'B2B QUEUE');
    } catch (StagingSmokeFailure $exception) {
        throw $exception;
    } catch (Throwable) {
        fail('B2B QUEUE', 'queue driver Redis connection is unavailable');
    }

    try {
        $counts = [
            'pending' => $queue->pendingSize($target['queue']),
            'delayed' => $queue->delayedSize($target['queue']),
            'reserved' => $queue->reservedSize($target['queue']),
        ];
    } catch (Throwable) {
        fail('B2B QUEUE', 'queue counts are unavailable');
    }
    foreach ($counts as $type => $count) {
        if (! is_int($count)
            && ! (is_string($count) && preg_match('/\A[0-9]+\z/', $count) === 1)) {
            fail('B2B QUEUE', $type.' queue count is invalid');
        }
        $counts[$type] = (int) $count;
    }
    if (array_sum($counts) < 0 || min($counts) < 0) {
        fail('B2B QUEUE', 'queue counts are invalid');
    }

    return [
        'connection' => $target['connection'],
        'queue' => $target['queue'],
        'fingerprint' => $target['fingerprint'],
        'counts' => $counts,
        'total' => array_sum($counts),
    ];
}

function verifyConfiguredHorizonQueue(): array
{
    $configuration = configuredHorizonSupervisor();
    if (($configuration['connection'] ?? null) !== 'redis') {
        fail('HORIZON', 'configured supervisor connection is not redis');
    }

    $queue = config('b2b.queue');
    $queues = queueList($configuration['queue'] ?? null);
    if (! is_string($queue) || $queues === null || ! in_array($queue, $queues, true)) {
        fail('HORIZON', 'configured B2B queue is absent from supervisor configuration');
    }

    return $configuration;
}

function verifyB2bTransport(): void
{
    if (config('queue.default') !== 'redis') {
        fail('B2B QUEUE', 'queue.default is not redis');
    }

    $job = new ProcessB2bProviderSyncEvent(0);
    if ($job->connection !== 'redis') {
        fail('B2B QUEUE', 'B2B job connection is not redis');
    }
}

function queueContractCheck(): void
{
    bootstrapApplication(false);
    if (! app()->configurationIsCached()) {
        fail('B2B QUEUE', 'application configuration is not cached');
    }
    verifyB2bTransport();
    verifyConfiguredHorizonQueue();
    $snapshot = queueContractSnapshot();

    printf("B2B_QUEUE_PROBE=%s\n", json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function queueSnapshotCheck(): void
{
    bootstrapApplication(false);
    if (! app()->configurationIsCached()) {
        fail('B2B QUEUE', 'application configuration is not cached');
    }
    $snapshot = queueContractSnapshot();

    printf("B2B_QUEUE_PROBE=%s\n", json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function httpCheck(string $check, int $userId, int $clientId): void
{
    [$app, $kernel] = bootstrapApplication(true);
    [, $actor, $client] = smokeIdentity($userId, $clientId);
    config()->set('session.driver', 'array');
    $session = app('session')->driver();
    $session->start();
    $session->put('_token', bin2hex(random_bytes(20)));
    $paths = [
        'crm-home' => ['CRM HOME', '/admin'],
        'clients' => ['CLIENTS', '/admin/clients'],
        'client-card' => ['CLIENT CARD', '/admin/clients/'.$client->getKey()],
        'sessions' => ['SESSIONS', '/admin/clients/'.$client->getKey().'/sessions'],
        'survey-definitions' => ['SURVEY DEFINITIONS', '/admin/survey-definitions'],
        'survey-attempts' => ['SURVEY ATTEMPTS', '/admin/survey-attempts'],
        'knowledge-sources' => ['KNOWLEDGE SOURCES', '/admin/knowledge-sources'],
        'knowledge-inspector' => ['KNOWLEDGE INSPECTOR', '/admin/knowledge-retrieval-inspector'],
        'portal' => ['PORTAL', '/'],
    ];
    if (! isset($paths[$check])) {
        fail('HTTP CHECK', 'unknown check');
    }
    [$label, $path] = $paths[$check];
    if ($check === 'portal') {
        $session->put('client_portal.client_id', $client->getKey());
    } else {
        Auth::login($actor);
    }
    $request = Request::create('https://crm.psysoldatov.ru'.$path, 'GET');
    $request->setLaravelSession($session);
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $kernel->terminate($request, $response);
    if ($status !== 200) {
        fail($label, 'HTTP status '.$status);
    }
    ok($label);
}

function runtimeCheck(): void
{
    bootstrapApplication(false);
    DB::selectOne('select 1 as ok');
    ok('POSTGRESQL');
    if (! app()->configurationIsCached()) {
        fail('CONFIGURATION', 'application configuration is not cached');
    }
    ok('CONFIGURATION', 'cached');
    verifyB2bTransport();
    verifyConfiguredHorizonQueue();
    $snapshot = queueContractSnapshot();
    ok('REDIS', 'queue connection '.$snapshot['connection']);
    $b2bQueue = $snapshot['queue'];

    $masterRepository = app(MasterSupervisorRepository::class);
    $supervisorRepository = app(SupervisorRepository::class);
    $masters = array_values(array_filter(
        $masterRepository->all(),
        static fn ($master): bool => ($master->status ?? null) === 'running'
            && ($master->environment ?? null) === horizonEnvironment()
            && is_string($master->name ?? null)
            && is_array($master->supervisors ?? null),
    ));
    if (count($masters) !== 1) {
        fail('HORIZON', 'there is not exactly one active current Horizon master');
    }
    $master = $masters[0];

    $supervisors = array_values(array_filter(
        $supervisorRepository->all(),
        static fn ($supervisor): bool => ($supervisor->status ?? null) === 'running'
            && is_string($supervisor->name ?? null)
            && ($supervisor->master ?? null) === $master->name
            && in_array($supervisor->name, $master->supervisors, true)
            && str_ends_with($supervisor->name, ':supervisor-1'),
    ));
    if (count($supervisors) !== 1) {
        fail('HORIZON', 'there is not exactly one active supervisor for the current master');
    }
    $supervisor = $supervisors[0];
    $supervisorOptions = runtimeArray($supervisor->options ?? null);
    if ($supervisorOptions === null) {
        fail('HORIZON', 'active supervisor options are invalid');
    }
    if (($supervisorOptions['connection'] ?? null) !== 'redis') {
        fail('HORIZON', 'active supervisor connection is not redis');
    }
    $runtimeQueues = queueList($supervisorOptions['queue'] ?? null);
    if ($runtimeQueues === null || ! in_array($b2bQueue, $runtimeQueues, true)) {
        fail('HORIZON', 'configured B2B queue is absent from active supervisor');
    }

    $processes = runtimeArray($supervisor->processes ?? null);
    if ($processes === null) {
        fail('HORIZON', 'active supervisor process state is invalid');
    }
    $b2bWorkers = 0;
    foreach ($processes as $pool => $count) {
        if (! is_string($pool) || ! (is_int($count) || (is_string($count) && preg_match('/\A[0-9]+\z/', $count) === 1))) {
            fail('HORIZON', 'active supervisor process state is invalid');
        }
        [$connection, $queues] = array_pad(explode(':', $pool, 2), 2, null);
        if ($connection !== 'redis' || ! is_string($queues)) {
            continue;
        }
        $poolQueues = queueList($queues);
        if ($poolQueues === null) {
            fail('HORIZON', 'active supervisor queue pool is invalid');
        }
        if (in_array($b2bQueue, $poolQueues, true)) {
            $b2bWorkers += (int) $count;
        }
    }
    if ($b2bWorkers < 1) {
        fail('HORIZON', 'B2B queue has no active worker process pool');
    }

    printf("B2B_QUEUE_PHYSICAL_FINGERPRINT=%s\n", $snapshot['fingerprint']);
    ok('HORIZON', $b2bWorkers.' B2B worker'.($b2bWorkers === 1 ? '' : 's').' queue '.$b2bQueue);
}

function deepCheck(int $userId, int $clientId): void
{
    [, $kernel] = bootstrapApplication(true);
    [$organization, $actor] = smokeIdentity($userId, $clientId);
    $source = null;
    $failure = null;
    try {
        Auth::login($actor);
        $token = bin2hex(random_bytes(8));
        $reference = 'staging-smoke://m9/'.$token;
        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Staging M9 smoke '.$token,
            'type' => KnowledgeSourceType::AuthoredText->value,
            'content' => 'Synthetic non-medical retrieval phrase '.$token.' controlled knowledge boundary.',
            'source_reference' => $reference,
        ]);
        $revision = $source->revisions()->sole();
        $deadline = microtime(true) + 60;
        $run = null;
        do {
            $run = $revision->ingestionRuns()->latest('id')->first();
            if ($run instanceof KnowledgeIngestionRun) {
                if ($run->status->value === 'ready') {
                    break;
                }
                if ($run->status->value === 'failed') {
                    fail('RAG INGESTION', 'failed with '.$run->error_code);
                }
            }
            usleep(500000);
        } while (microtime(true) < $deadline);
        if (! $run instanceof KnowledgeIngestionRun || $run->status->value !== 'ready') {
            fail('RAG INGESTION', 'READY timeout');
        }
        ok('RAG INGESTION');
        $results = app(KnowledgeRetriever::class)->retrieve($actor, new RetrievalQuery($token.' controlled knowledge boundary', 5));
        $result = collect($results)->first(static fn ($item): bool => $item->sourceId === $source->getKey());
        if ($result === null) {
            fail('RAG RETRIEVAL', 'source was not returned');
        }
        ok('RAG RETRIEVAL');
        if ($result->revisionId !== $revision->getKey()
            || $result->revisionVersion !== 1
            || $result->ingestionRunId !== $run->getKey()
            || $result->sourceReference !== $reference) {
            fail('RAG PROVENANCE', 'reference mismatch');
        }
        ok('RAG PROVENANCE');
        config()->set('session.driver', 'array');
        $session = app('session')->driver();
        $session->start();
        Auth::login($actor);
        $request = Request::create('https://crm.psysoldatov.ru/admin/knowledge-sources/'.$source->getKey().'/edit', 'GET');
        $request->setLaravelSession($session);
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $kernel->terminate($request, $response);
        if ($status !== 200) {
            fail('REVISION HISTORY', 'HTTP status '.$status);
        }
        ok('REVISION HISTORY');
    } catch (StagingSmokeFailure $exception) {
        $failure = $exception;
    } catch (Throwable) {
        $failure = new StagingSmokeFailure('RAG DEEP: unexpected application error');
    } finally {
        if ($source instanceof KnowledgeSource) {
            app(OrganizationContext::class)->set($organization);
            Auth::login($actor);
            app(RetireKnowledgeSource::class)->handle($actor, $source->fresh());
            if ($source->fresh()?->status->value !== 'retired') {
                $failure = new StagingSmokeFailure('CLEANUP: source was not retired');
            } else {
                ok('CLEANUP');
            }
        }
    }
    if ($failure instanceof StagingSmokeFailure) {
        throw $failure;
    }
}

$arguments = options();
$check = $arguments['check'] ?? '';
$userId = filter_var($arguments['user-id'] ?? null, FILTER_VALIDATE_INT);
$clientId = filter_var($arguments['client-id'] ?? null, FILTER_VALIDATE_INT);

try {
    if ($check === 'queue-snapshot') {
        queueSnapshotCheck();
    } elseif ($check === 'queue-contract') {
        queueContractCheck();
    } elseif ($check === 'runtime') {
        runtimeCheck();
    } elseif ($userId !== false && $clientId !== false && $check === 'deep') {
        deepCheck($userId, $clientId);
    } elseif ($userId !== false && $clientId !== false) {
        httpCheck($check, $userId, $clientId);
    } else {
        fail('SMOKE IDENTITY', 'numeric user/client IDs are required');
    }
} catch (StagingSmokeFailure $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "STAGING SMOKE: unexpected application error\n");
    exit(1);
}
