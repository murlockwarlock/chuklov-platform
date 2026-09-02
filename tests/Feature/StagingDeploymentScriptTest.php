<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StagingDeploymentScriptTest extends TestCase
{
    #[Test]
    public function staging_deployment_is_exact_revision_isolated_and_non_destructive(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-staging.sh'));
        $normalizer = file_get_contents(base_path('scripts/normalize-fail2ban-elements.awk'));

        self::assertIsString($script);
        self::assertIsString($normalizer);
        self::assertStringContainsString('deployment_ref="${STAGING_DEPLOY_REF:-origin/main}"', $script);
        self::assertStringContainsString('git merge-base --is-ancestor "$revision" "$deployment_ref"', $script);
        self::assertStringContainsString('--project-name "$project"', $script);
        self::assertStringContainsString('pg_dump', $script);
        self::assertStringContainsString("-Fc' < /dev/null >", $script);
        self::assertStringContainsString('pg_restore -l', $script);
        self::assertStringNotContainsString("sh -lc 'pg_restore -l'", $script);
        self::assertStringContainsString('migrate --force', $script);
        self::assertStringContainsString('php artisan portal:validate-configuration', $script);
        self::assertStringContainsString('db:seed --class=Database\\\\Seeders\\\\ScenarioNotificationSeeder --force', $script);
        self::assertStringContainsString('--force-recreate app horizon scheduler telegram', $script);
        self::assertSame(2, substr_count($script, '--force-recreate app horizon scheduler telegram < /dev/null'));
        self::assertStringContainsString('up -d --wait < /dev/null', $script);
        self::assertStringContainsString("'[.services.app, .services.horizon, .services.scheduler, .services.telegram] | all(.image == \$image and .user == \"33:33\")'", $script);
        self::assertStringContainsString('up -d postgres redis', $script);
        self::assertStringContainsString('trap \'rollback "$LINENO" "$?"\' ERR', $script);
        self::assertStringContainsString('horizon:supervisors --no-ansi', $script);
        self::assertStringContainsString('Horizon did not report an active supervisor with workers', $script);
        self::assertStringContainsString("grep -Ec '^QUEUE_CONNECTION='", $script);
        self::assertStringContainsString("grep -Fxq 'QUEUE_CONNECTION=redis'", $script);
        self::assertStringNotContainsString('redis-cli ping', $script);
        self::assertStringContainsString('resolve_current_redis_volume', $script);
        self::assertStringContainsString('redis_container_output', $script);
        self::assertStringContainsString("docker inspect \"\$container\" --format '{{json .Mounts}}'", $script);
        self::assertStringContainsString('docker volume inspect "$current_redis_volume"', $script);
        self::assertStringContainsString('current_redis_volume_key', $script);
        self::assertStringContainsString('candidate_redis_volume_key', $script);
        self::assertStringContainsString('resolve_candidate_redis_volume_key', $script);
        self::assertStringContainsString('write_redis_volume_override', $script);
        self::assertStringContainsString('name: "%s"', $script);
        self::assertStringContainsString('verify_candidate_redis_volume', $script);
        self::assertStringContainsString('Candidate Redis /data physical volume does not match the current staging volume', $script);
        self::assertStringNotContainsString('volume="${project}_redis_data"', $script);
        self::assertStringContainsString('ensure_current_dependencies', $script);
        self::assertStringContainsString('up -d --wait postgres redis', $script);
        self::assertStringContainsString('run_queue_contract_probe', $script);
        self::assertStringContainsString('php -- --check="$check" < "$queue_probe_script"', $script);
        self::assertStringNotContainsString('php -- < "$queue_probe_script"', $script);
        self::assertStringContainsString('--network "$staging_network"', $script);
        self::assertStringContainsString("--env 'APP_CONFIG_CACHE=/app/bootstrap/cache/config.php'", $script);
        self::assertStringContainsString('compose_service_environment_file', $script);
        self::assertStringContainsString('--env-file "$probe_environment"', $script);
        self::assertStringContainsString('current_queue_fingerprint', $script);
        self::assertStringContainsString('candidate_queue_fingerprint', $script);
        self::assertStringContainsString('current_pending_work', $script);
        self::assertStringContainsString('physical Redis queue identity change', $script);
        self::assertStringContainsString('candidate_build_cache', $script);
        self::assertStringContainsString('queue_preflight_cache', $script);
        self::assertStringContainsString('php artisan config:cache --no-ansi', $script);
        self::assertStringNotContainsString('php artisan tinker --no-ansi --execute=', $script);
        self::assertStringContainsString('Protected host services and routing match the pre-deploy baseline.', $script);
        self::assertStringContainsString('report_preflight_failure', $script);
        self::assertStringContainsString('CHUKLOV_CONTAINER_IP', $script);
        self::assertStringContainsString('CHUKLOV_LOOPBACK_GUARD_PRESENT', $script);
        self::assertStringContainsString('Expected exactly one loopback guard', $script);
        self::assertStringContainsString('DYNAMIC_BANS', $normalizer);
        self::assertStringContainsString('trusted_normalizer="$script_directory/normalize-fail2ban-elements.awk"', $script);
        self::assertStringContainsString('[[ ! -f "$trusted_normalizer" || ! -r "$trusted_normalizer" ]]', $script);
        self::assertStringContainsString('cp -- "$trusted_normalizer" "$normalizer"', $script);
        self::assertStringNotContainsString('tar -xOf "$archive" scripts/normalize-fail2ban-elements.awk > "$normalizer"', $script);
        self::assertStringContainsString('awk -f "$remote_normalizer" > "$output"', $script);
        self::assertStringContainsString('cleanup_remote_transfer_artifacts()', $script);
        self::assertStringContainsString('rm -f -- "$archive" "$remote_normalizer" || true', $script);
        self::assertStringContainsString('trap cleanup_remote_transfer_artifacts EXIT', $script);
        self::assertStringContainsString('normalize_legacy_app_server_command', $script);
        self::assertStringContainsString('forbidden php -S app command', $script);
        self::assertStringContainsString('normalize_staging_runtime_user', $script);
        self::assertStringContainsString('Staging Compose configuration is missing the hardened app base.', $script);
        self::assertStringContainsString('all(.image == $image and .user == "33:33")', $script);
        self::assertStringContainsString('prepare_runtime_ownership', $script);
        self::assertStringContainsString('chown -R 33:33', $script);
        self::assertStringContainsString('prepare_release_permissions', $script);
        self::assertStringContainsString('chown -R root:33', $script);
        self::assertStringNotContainsString('down -v', $script);
        self::assertStringNotContainsString('docker system prune', $script);
        self::assertStringNotContainsString('docker volume prune', $script);
        self::assertStringNotContainsString('docker volume rm', $script);
        self::assertStringNotContainsString('redis-cli flush', $script);
        self::assertStringNotContainsString('FLUSHALL', $script);
    }

    #[Test]
    public function redis_volume_preflight_preserves_the_current_physical_volume_and_fails_closed_on_unsafe_evidence(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-staging.sh'));

        self::assertIsString($script);

        $fixtures = [
            'legacy' => [
                'mounts' => [[
                    'Type' => 'volume',
                    'Name' => 'staging-test_redis-data',
                    'Source' => '/var/lib/docker/volumes/staging-test_redis-data/_data',
                    'Destination' => '/data',
                ]],
                'physical_volume' => 'staging-test_redis-data',
                'candidate_volume' => 'staging-test_redis-data',
                'expected_success' => true,
            ],
            'standard' => [
                'mounts' => [[
                    'Type' => 'volume',
                    'Name' => 'staging-test_redis_data',
                    'Source' => '/var/lib/docker/volumes/staging-test_redis_data/_data',
                    'Destination' => '/data',
                ]],
                'physical_volume' => 'staging-test_redis_data',
                'candidate_volume' => 'staging-test_redis_data',
                'expected_success' => true,
            ],
            'no-data-volume' => [
                'mounts' => [[
                    'Type' => 'bind',
                    'Name' => '',
                    'Source' => '/srv/staging/redis',
                    'Destination' => '/data',
                ]],
                'physical_volume' => 'staging-test_redis_data',
                'candidate_volume' => 'staging-test_redis_data',
                'expected_success' => false,
            ],
            'ambiguous-data-volume' => [
                'mounts' => [
                    [
                        'Type' => 'volume',
                        'Name' => 'staging-test_redis-data-a',
                        'Source' => '/var/lib/docker/volumes/staging-test_redis-data-a/_data',
                        'Destination' => '/data',
                    ],
                    [
                        'Type' => 'volume',
                        'Name' => 'staging-test_redis-data-b',
                        'Source' => '/var/lib/docker/volumes/staging-test_redis-data-b/_data',
                        'Destination' => '/data',
                    ],
                ],
                'physical_volume' => 'staging-test_redis-data-a',
                'candidate_volume' => 'staging-test_redis-data-a',
                'expected_success' => false,
            ],
            'candidate-mismatch' => [
                'mounts' => [[
                    'Type' => 'volume',
                    'Name' => 'staging-test_redis-data',
                    'Source' => '/var/lib/docker/volumes/staging-test_redis-data/_data',
                    'Destination' => '/data',
                ]],
                'physical_volume' => 'staging-test_redis-data',
                'candidate_volume' => 'staging-test_redis_data',
                'expected_success' => false,
            ],
            'hyphenated-logical-key' => [
                'mounts' => [[
                    'Type' => 'volume',
                    'Name' => 'staging-test_redis-data',
                    'Source' => '/var/lib/docker/volumes/staging-test_redis-data/_data',
                    'Destination' => '/data',
                ]],
                'physical_volume' => 'staging-test_redis-data',
                'candidate_volume' => 'staging-test_redis-data',
                'candidate_volume_key' => 'redis-data',
                'expected_success' => true,
            ],
        ];

        foreach ($fixtures as $name => $fixture) {
            $result = $this->runRedisVolumeFixture(
                $script,
                $fixture['mounts'],
                $fixture['physical_volume'],
                $fixture['candidate_volume'],
                $fixture['candidate_volume_key'] ?? 'redis_data',
            );

            if ($fixture['expected_success']) {
                self::assertSame(0, $result['exit_code'], $name);
                self::assertStringContainsString('Current Redis /data physical volume: '.$fixture['physical_volume'], $result['output'], $name);
                self::assertStringContainsString('Candidate Redis /data physical volume: '.$fixture['candidate_volume'], $result['output'], $name);
                self::assertStringContainsString('name: "'.$fixture['physical_volume'].'"', $result['output'], $name);
            } else {
                self::assertNotSame(0, $result['exit_code'], $name);
            }

            self::assertStringNotContainsString('up ', $result['docker_log'], $name);
            self::assertStringNotContainsString('volume rm', $result['docker_log'], $name);
            self::assertStringNotContainsString('flush', strtolower($result['docker_log']), $name);
        }

        $legacy = $this->runRedisVolumeFixture(
            $script,
            $fixtures['legacy']['mounts'],
            $fixtures['legacy']['physical_volume'],
            $fixtures['legacy']['candidate_volume'],
            $fixtures['legacy']['candidate_volume_key'] ?? 'redis_data',
        );
        self::assertStringContainsString('staging-test_redis-data', $legacy['docker_log']);
        self::assertStringNotContainsString('staging-test_redis_data', $legacy['docker_log']);
    }

    #[Test]
    public function queue_preflight_is_before_activation_and_migrations(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-staging.sh'));

        self::assertIsString($script);
        $noOp = strpos($script, 'if [[ "$current_revision" == "$revision" ]]');
        $dependencyStart = strpos($script, "\nensure_current_dependencies\nresolve_staging_network");
        $candidateVolume = strpos($script, "\nverify_candidate_redis_volume\n");
        $currentProbe = strpos($script, 'current_queue_probe="$(run_queue_contract_probe');
        $candidateCache = strpos($script, 'php artisan config:cache --no-ansi');
        $candidateProbe = strpos($script, 'candidate_queue_probe="$(run_queue_contract_probe');
        $activation = strpos($script, 'mv "$compose.next" "$compose"');
        $migration = strpos($script, 'php artisan migrate --force');

        self::assertIsInt($noOp);
        self::assertIsInt($dependencyStart);
        self::assertIsInt($candidateVolume);
        self::assertIsInt($currentProbe);
        self::assertIsInt($candidateCache);
        self::assertIsInt($candidateProbe);
        self::assertIsInt($activation);
        self::assertIsInt($migration);
        self::assertLessThan($dependencyStart, $noOp);
        self::assertLessThan($currentProbe, $dependencyStart);
        self::assertLessThan($currentProbe, $candidateVolume);
        self::assertLessThan($activation, $candidateCache);
        self::assertLessThan($activation, $candidateProbe);
        self::assertLessThan($migration, $activation);
    }

    #[Test]
    public function queue_preflight_separates_current_snapshot_from_candidate_contract(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-staging.sh'));
        $smoke = file_get_contents(base_path('scripts/staging-smoke.php'));

        self::assertIsString($script);
        self::assertIsString($smoke);
        self::assertStringContainsString(
            'current_queue_probe="$(run_queue_contract_probe "queue-snapshot"',
            $script,
        );
        self::assertStringContainsString(
            'candidate_queue_probe="$(run_queue_contract_probe "queue-contract"',
            $script,
        );

        $snapshotStart = strpos($smoke, 'function queueSnapshotCheck(): void');
        $snapshotEnd = $snapshotStart === false ? false : strpos($smoke, "\nfunction httpCheck", $snapshotStart);
        $contractStart = strpos($smoke, 'function queueContractCheck(): void');

        self::assertIsInt($snapshotStart);
        self::assertIsInt($snapshotEnd);
        self::assertIsInt($contractStart);
        $snapshotBody = substr($smoke, $snapshotStart, $snapshotEnd - $snapshotStart);
        $contractBody = substr($smoke, $contractStart, $snapshotStart - $contractStart);

        self::assertStringContainsString('queueContractSnapshot()', $snapshotBody);
        self::assertStringNotContainsString('verifyB2bTransport()', $snapshotBody);
        self::assertStringNotContainsString('verifyConfiguredHorizonQueue()', $snapshotBody);
        self::assertStringContainsString('verifyB2bTransport()', $contractBody);
        self::assertStringContainsString('verifyConfiguredHorizonQueue()', $contractBody);
        self::assertStringContainsString("if (\$check === 'queue-snapshot')", $smoke);
        self::assertStringContainsString("\$check === 'queue-contract'", $smoke);
        self::assertStringContainsString("'connection' => \$target['connection']", $smoke);
        self::assertStringContainsString("'queue' => \$target['queue']", $smoke);
        self::assertStringContainsString("'fingerprint' => \$target['fingerprint']", $smoke);
        self::assertStringContainsString("'counts' => \$counts", $smoke);
        self::assertStringContainsString("'total' => array_sum(\$counts)", $smoke);
        self::assertStringContainsString('configured Laravel Redis connection is unavailable', $smoke);
        self::assertStringContainsString('Laravel redis queue driver is not Redis', $smoke);
        self::assertStringContainsString('queue counts are unavailable', $smoke);
        self::assertStringContainsString('B2B queue is invalid', $smoke);
        self::assertStringContainsString('Redis host configuration is not resolvable', $smoke);
    }

    #[Test]
    public function target_archive_without_normalizer_uses_the_trusted_deploy_checkout_copy(): void
    {
        $filesystem = new Filesystem;
        $checkout = sys_get_temp_dir().'/chuklov-staging-tooling-'.bin2hex(random_bytes(8));
        $environment = tempnam(sys_get_temp_dir(), 'chuklov-staging-env-');

        if ($environment === false) {
            throw new \RuntimeException('Unable to create staging deployment environment.');
        }

        $filesystem->mkdir([$checkout.'/bin', $checkout.'/capture']);

        try {
            $targetRevision = $this->createTrustedDeployCheckout($checkout);
            $captureDirectory = $checkout.'/capture';
            file_put_contents($environment, implode(PHP_EOL, [
                'STAGING_HOST=staging.example.test',
                'STAGING_USER=deployer',
                'STAGING_SSH_KEY=/tmp/staging-test-key',
                'STAGING_ROOT=/tmp/staging-root',
                'STAGING_PROJECT=staging-test',
                'STAGING_HEALTH_URL=http://staging.example.test/health',
                'STAGING_EXPECTED_HOST_PORT=127.0.0.1:18080',
                'STAGING_DEPLOY_REF=origin/main',
            ]).PHP_EOL);

            $process = new Process(
                ['bash', $checkout.'/scripts/deploy-staging.sh', $targetRevision],
                sys_get_temp_dir(),
                [
                    'PATH' => $checkout.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
                    'STAGING_DEPLOY_ENV' => $environment,
                    'TEST_CAPTURE_DIRECTORY' => $captureDirectory,
                ],
            );
            $process->mustRun();

            $archive = $captureDirectory.'/target.tar';
            $archiveListing = $this->runProcess(['tar', '-tf', $archive], $checkout)->getOutput();
            $normalizer = file_get_contents($captureDirectory.'/normalizer.awk');
            $trustedNormalizer = file_get_contents(base_path('scripts/normalize-fail2ban-elements.awk'));
            $sourcePaths = file($captureDirectory.'/source-paths.txt', FILE_IGNORE_NEW_LINES);

            self::assertStringNotContainsString('scripts/normalize-fail2ban-elements.awk', $archiveListing);
            self::assertIsString($normalizer);
            self::assertIsString($trustedNormalizer);
            self::assertSame($trustedNormalizer, $normalizer);
            self::assertIsArray($sourcePaths);
            self::assertCount(2, $sourcePaths);
            self::assertFileDoesNotExist($sourcePaths[1]);
        } finally {
            $filesystem->remove($environment);
            $filesystem->remove($checkout);
        }
    }

    #[Test]
    public function fail2ban_single_and_multiline_membership_is_fully_normalized(): void
    {
        $singleLine = $this->normalizeNftables($this->fail2banRuleset(
            'elements = { 193.47.62.69 }',
        ));
        $multiLine = $this->normalizeNftables($this->fail2banRuleset(<<<'NFT'
            elements = { 193.47.62.69,
                         62.60.130.253 }
            NFT));

        self::assertSame(1, substr_count($singleLine, 'elements = { DYNAMIC_BANS }'));
        self::assertSame($singleLine, $multiLine);
        self::assertStringNotContainsString('193.47.62.69', $multiLine);
        self::assertStringNotContainsString('62.60.130.253', $multiLine);
        self::assertStringContainsString('chain f2b-chain', $multiLine);
        self::assertStringContainsString('tcp dport 22 ip saddr @addr-set-sshd reject', $multiLine);
    }

    #[Test]
    public function fail2ban_dynamic_membership_changes_normalize_identically(): void
    {
        $before = $this->normalizeNftables($this->fail2banRuleset(<<<'NFT'
            elements = { 193.47.62.69,
                         62.60.130.253 }
            NFT));
        $after = $this->normalizeNftables($this->fail2banRuleset(<<<'NFT'
            elements = { 45.148.10.141,
                         91.224.92.92,
                         158.173.89.136 }
            NFT));

        self::assertSame($before, $after);
    }

    #[Test]
    public function fail2ban_structure_remains_visible_to_the_guard(): void
    {
        $baseline = $this->normalizeNftables($this->fail2banRuleset(<<<'NFT'
            elements = { 193.47.62.69,
                         62.60.130.253 }
            NFT));
        $changedChain = $this->normalizeNftables(str_replace(
            'chain f2b-chain',
            'chain f2b-renamed-chain',
            $this->fail2banRuleset('elements = { 193.47.62.69 }'),
        ));
        $changedRule = $this->normalizeNftables(str_replace(
            'reject with icmp port-unreachable',
            'drop',
            $this->fail2banRuleset('elements = { 193.47.62.69 }'),
        ));
        $changedTable = $this->normalizeNftables(str_replace(
            'table inet f2b-table',
            'table inet f2b-renamed-table',
            $this->fail2banRuleset('elements = { 193.47.62.69 }'),
        ));

        self::assertNotSame($baseline, $changedChain);
        self::assertNotSame($baseline, $changedRule);
        self::assertNotSame($baseline, $changedTable);
    }

    #[Test]
    public function elements_outside_fail2ban_table_remain_visible_to_the_guard(): void
    {
        $before = $this->normalizeNftables($this->fail2banRuleset(
            'elements = { 193.47.62.69 }',
        ).<<<'NFT'

        table inet other-table {
            set other-set {
                type ipv4_addr
                elements = { 198.51.100.10 }
            }
        }
        NFT);
        $after = $this->normalizeNftables($this->fail2banRuleset(
            'elements = { 193.47.62.69 }',
        ).<<<'NFT'

        table inet other-table {
            set other-set {
                type ipv4_addr
                elements = { 198.51.100.11 }
            }
        }
        NFT);

        self::assertNotSame($before, $after);
        self::assertStringContainsString('198.51.100.10', $before);
        self::assertStringContainsString('198.51.100.11', $after);
    }

    #[Test]
    public function real_staging_deployment_environment_is_ignored(): void
    {
        $gitignore = file_get_contents(base_path('.gitignore'));

        self::assertIsString($gitignore);
        self::assertStringContainsString('.env.staging-deploy', $gitignore);
        self::assertFileExists(base_path('.env.staging-deploy.example'));
    }

    #[Test]
    public function staging_smoke_uses_the_guarded_environment_and_read_only_container_contract(): void
    {
        $shell = file_get_contents(base_path('scripts/staging-smoke.sh'));
        $php = file_get_contents(base_path('scripts/staging-smoke.php'));
        $example = file_get_contents(base_path('.env.staging-deploy.example'));

        self::assertIsString($shell);
        self::assertIsString($php);
        self::assertIsString($example);
        self::assertStringContainsString('STAGING_SMOKE_USER_ID', $shell);
        self::assertStringContainsString('STAGING_SMOKE_CLIENT_ID', $shell);
        self::assertStringContainsString('< "$repository_root/scripts/staging-smoke.php"', $shell);
        self::assertStringNotContainsString('docker cp', $shell);
        self::assertStringContainsString('--deep', $shell);
        self::assertStringContainsString('run_php_check app runtime', $shell);
        self::assertStringContainsString('run_php_check horizon runtime', $shell);
        self::assertStringContainsString('B2B_QUEUE_PHYSICAL_FINGERPRINT=', $shell);
        self::assertStringContainsString('application and Horizon resolve different physical queue targets', $shell);
        self::assertStringContainsString('Queue::connection', $php);
        self::assertStringContainsString('Redis::connection($target[\'connection\'])', $php);
        self::assertStringContainsString('ConfigurationUrlParser', $php);
        self::assertStringContainsString('pendingSize', $php);
        self::assertStringContainsString('delayedSize', $php);
        self::assertStringContainsString('reservedSize', $php);
        self::assertStringContainsString('exactly one active current Horizon master', $php);
        self::assertStringContainsString('B2B queue has no active worker process pool', $php);
        self::assertStringContainsString('$supervisorRepository->all()', $php);
        self::assertStringContainsString('configurationIsCached()', $php);
        self::assertStringContainsString('verifyB2bTransport', $php);
        self::assertStringContainsString('configured B2B queue is absent from supervisor configuration', $php);
        self::assertStringContainsString('active supervisor connection is not redis', $php);
        self::assertStringContainsString('configured B2B queue is absent from active supervisor', $php);
        self::assertStringNotContainsString('Redis::connection()->', $php);
        self::assertStringNotContainsString('dispatch(', $php);
        self::assertStringNotContainsString('lrange', $php);
        self::assertStringNotContainsString('zrange', $php);
        self::assertStringContainsString('app(RetireKnowledgeSource::class)->handle', $php);
        self::assertStringContainsString('STAGING_SMOKE_USER_ID=', $example);
        self::assertStringContainsString('STAGING_SMOKE_CLIENT_ID=', $example);
    }

    private function runRedisVolumeFixture(
        string $script,
        array $mounts,
        string $physicalVolume,
        string $candidateVolume,
        string $candidateVolumeKey = 'redis_data',
    ): array {
        $filesystem = new Filesystem;
        $fixtureDirectory = sys_get_temp_dir().'/chuklov-redis-volume-'.bin2hex(random_bytes(8));
        $filesystem->mkdir($fixtureDirectory.'/bin');

        $mountsFile = $fixtureDirectory.'/mounts.json';
        $candidateConfigFile = $fixtureDirectory.'/candidate.json';
        $dockerLog = $fixtureDirectory.'/docker.log';
        $harness = $fixtureDirectory.'/harness.sh';
        $docker = $fixtureDirectory.'/bin/docker';

        try {
            file_put_contents($mountsFile, json_encode($mounts, JSON_THROW_ON_ERROR).PHP_EOL);
            file_put_contents($candidateConfigFile, json_encode([
                'volumes' => [
                    $candidateVolumeKey => ['name' => $candidateVolume],
                ],
                'services' => [
                    'redis' => [
                        'volumes' => [[
                            'type' => 'volume',
                            'source' => $candidateVolumeKey,
                            'target' => '/data',
                        ]],
                    ],
                ],
            ], JSON_THROW_ON_ERROR).PHP_EOL);
            file_put_contents($dockerLog, '');

            $harnessContent = <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail

project='staging-test'
environment='/tmp/staging-test.env'
compose='/tmp/staging-test-compose.yml'
current_compose_base=(docker compose --project-name "$project" --env-file "$environment" -f "$compose")
current_compose=()
candidate_compose_base=()
candidate_compose=()
current_redis_volume=''
current_redis_volume_key=''
candidate_redis_volume_key=''
redis_volume_override=''
BASH;
            $harnessContent .= "\n".$this->extractRedisVolumeFunctions($script);
            $harnessContent .= <<<'BASH'

resolve_current_redis_volume
write_redis_volume_override
candidate_compose=(docker compose --project-name "$project" --env-file "$environment" -f "$compose.next" -f "$redis_volume_override")
resolve_candidate_redis_volume_key
write_redis_volume_override "$candidate_redis_volume_key"
verify_candidate_redis_volume
printf 'CURRENT=%s\n' "$current_redis_volume"
printf 'OVERRIDE=%s\n' "$redis_volume_override"
cat "$redis_volume_override"
BASH;
            file_put_contents($harness, $harnessContent);

            file_put_contents($docker, <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail

printf '%s\n' "$*" >> "$TEST_DOCKER_LOG"

if [[ "$1" == 'compose' ]]; then
    if [[ "$*" == *' ps --status running -q redis'* ]]; then
        printf '%s\n' 'redis-container'
        exit 0
    fi

    if [[ "$*" == *' config --format json'* ]]; then
        cat "$TEST_CANDIDATE_CONFIG_FILE"
        exit 0
    fi

    exit 0
fi

if [[ "$1" == 'inspect' && "${2:-}" == 'redis-container' ]]; then
    cat "$TEST_MOUNTS_FILE"
    exit 0
fi

if [[ "$1" == 'volume' && "${2:-}" == 'inspect' ]]; then
    if [[ "${3:-}" == "$TEST_PHYSICAL_VOLUME" ]]; then
        printf '%s\n' '[{}]'
        exit 0
    fi

    exit 1
fi

exit 0
BASH);
            chmod($harness, 0755);
            chmod($docker, 0755);

            $process = new Process(
                ['bash', $harness],
                $fixtureDirectory,
                [
                    'PATH' => $fixtureDirectory.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
                    'TEST_MOUNTS_FILE' => $mountsFile,
                    'TEST_CANDIDATE_CONFIG_FILE' => $candidateConfigFile,
                    'TEST_DOCKER_LOG' => $dockerLog,
                    'TEST_PHYSICAL_VOLUME' => $physicalVolume,
                    'TEST_CANDIDATE_VOLUME_KEY' => $candidateVolumeKey,
                ],
            );
            $process->run();

            return [
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput().$process->getErrorOutput(),
                'docker_log' => file_get_contents($dockerLog) ?: '',
            ];
        } finally {
            $filesystem->remove($fixtureDirectory);
        }
    }

    private function extractRedisVolumeFunctions(string $script): string
    {
        $start = strpos($script, 'resolve_current_redis_volume() {');
        $end = $start === false ? false : strpos($script, "\nensure_current_dependencies() {", $start);

        if ($start === false || $end === false) {
            throw new \RuntimeException('Unable to extract Redis volume preflight functions.');
        }

        return substr($script, $start, $end - $start);
    }

    private function createTrustedDeployCheckout(string $checkout): string
    {
        $this->runProcess(['git', 'init', '--initial-branch=main'], $checkout);
        $this->runProcess(['git', 'config', 'user.email', 'staging-test@example.test'], $checkout);
        $this->runProcess(['git', 'config', 'user.name', 'Staging Test'], $checkout);

        file_put_contents($checkout.'/target.txt', "target payload\n");
        $this->runProcess(['git', 'add', 'target.txt'], $checkout);
        $this->runProcess(['git', 'commit', '-m', 'target payload'], $checkout);
        $targetRevision = trim($this->runProcess(['git', 'rev-parse', 'HEAD'], $checkout)->getOutput());

        $filesystem = new Filesystem;
        $filesystem->mkdir($checkout.'/scripts');
        $filesystem->copy(base_path('scripts/deploy-staging.sh'), $checkout.'/scripts/deploy-staging.sh');
        $filesystem->copy(
            base_path('scripts/normalize-fail2ban-elements.awk'),
            $checkout.'/scripts/normalize-fail2ban-elements.awk',
        );
        file_put_contents($checkout.'/bin/scp', <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail

source_path=''
for argument in "$@"; do
    case "$argument" in
        *:*) ;;
        *.tar|*.awk) source_path="$argument" ;;
    esac
done

if [[ -z "$source_path" ]]; then
    echo 'Missing local copy source.' >&2
    exit 1
fi

mkdir -p "$TEST_CAPTURE_DIRECTORY"
printf '%s\n' "$source_path" >> "$TEST_CAPTURE_DIRECTORY/source-paths.txt"
case "$source_path" in
    *.tar) cp -- "$source_path" "$TEST_CAPTURE_DIRECTORY/target.tar" ;;
    *.awk) cp -- "$source_path" "$TEST_CAPTURE_DIRECTORY/normalizer.awk" ;;
    *) echo 'Unexpected copy source.' >&2; exit 1 ;;
esac
BASH);
        file_put_contents($checkout.'/bin/ssh', <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail

cat > "$TEST_CAPTURE_DIRECTORY/remote-script.sh"
BASH);
        chmod($checkout.'/bin/scp', 0755);
        chmod($checkout.'/bin/ssh', 0755);

        $this->runProcess(['git', 'add', 'bin', 'scripts'], $checkout);
        $this->runProcess(['git', 'commit', '-m', 'trusted deployment tooling'], $checkout);
        $this->runProcess(['git', 'update-ref', 'refs/remotes/origin/main', $targetRevision], $checkout);

        return $targetRevision;
    }

    private function runProcess(array $command, string $workingDirectory): Process
    {
        $process = new Process($command, $workingDirectory);
        $process->mustRun();

        return $process;
    }

    private function normalizeNftables(string $ruleset): string
    {
        $fixture = tempnam(sys_get_temp_dir(), 'nftables-test-');

        if ($fixture === false) {
            throw new \RuntimeException('Unable to create nftables fixture.');
        }

        try {
            if (file_put_contents($fixture, $ruleset) === false) {
                throw new \RuntimeException('Unable to write nftables fixture.');
            }

            $process = new Process([
                'awk',
                '-f',
                base_path('scripts/normalize-fail2ban-elements.awk'),
                $fixture,
            ]);
            $process->mustRun();

            return $process->getOutput();
        } finally {
            unlink($fixture);
        }
    }

    private function fail2banRuleset(string $elements): string
    {
        return <<<NFT
table inet f2b-table {
    set addr-set-sshd {
        type ipv4_addr
        {$elements}
    }

    chain f2b-chain {
        type filter hook input priority filter - 1; policy accept;
        tcp dport 22 ip saddr @addr-set-sshd reject with icmp port-unreachable
    }
}
NFT;
    }
}
