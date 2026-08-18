<?php

use App\Models\User;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    Redis::connection()->command('ping');
    ok('REDIS');
    if (config('queue.default') !== 'redis') {
        fail('HORIZON', 'queue.default is not redis');
    }
    $masters = app(MasterSupervisorRepository::class)->all();
    if ($masters === [] || collect($masters)->contains(static fn ($master): bool => $master->status !== 'running')) {
        fail('HORIZON', 'master is not running');
    }
    $supervisors = array_values(array_filter(
        app(SupervisorRepository::class)->all(),
        static fn ($supervisor): bool => $supervisor->status === 'running' && str_contains($supervisor->name, 'supervisor-1'),
    ));
    $workers = array_sum(array_map(
        static fn ($supervisor): int => array_sum(array_map('intval', is_array($supervisor->processes) ? $supervisor->processes : [])),
        $supervisors,
    ));
    if ($supervisors === [] || $workers < 1) {
        fail('HORIZON', 'no active supervisor workers');
    }
    ok('HORIZON', $workers.' worker'.($workers === 1 ? '' : 's'));
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
    if ($check === 'runtime') {
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
