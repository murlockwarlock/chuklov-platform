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
        self::assertStringContainsString('--force-recreate app horizon scheduler telegram', $script);
        self::assertSame(2, substr_count($script, '--force-recreate app horizon scheduler telegram < /dev/null'));
        self::assertStringContainsString('up -d --wait < /dev/null', $script);
        self::assertStringContainsString("'[.services.app, .services.horizon, .services.scheduler, .services.telegram] | all(.image == \$image and .user == \"33:33\")'", $script);
        self::assertStringContainsString('up -d postgres redis', $script);
        self::assertStringContainsString('trap \'rollback "$LINENO" "$?"\' ERR', $script);
        self::assertStringContainsString('horizon:supervisors --no-ansi', $script);
        self::assertStringContainsString('Horizon did not report an active supervisor with workers', $script);
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
        self::assertStringContainsString('app(SupervisorRepository::class)->all()', $php);
        self::assertStringContainsString('app(RetireKnowledgeSource::class)->handle', $php);
        self::assertStringContainsString('STAGING_SMOKE_USER_ID=', $example);
        self::assertStringContainsString('STAGING_SMOKE_CLIENT_ID=', $example);
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
