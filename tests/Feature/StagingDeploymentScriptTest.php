<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StagingDeploymentScriptTest extends TestCase
{
    #[Test]
    public function staging_deployment_is_exact_revision_isolated_and_non_destructive(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-staging.sh'));

        self::assertIsString($script);
        self::assertStringContainsString('git merge-base --is-ancestor "$revision" origin/main', $script);
        self::assertStringContainsString('--project-name "$project"', $script);
        self::assertStringContainsString('pg_dump', $script);
        self::assertStringContainsString("-Fc' < /dev/null >", $script);
        self::assertStringContainsString('pg_restore -l', $script);
        self::assertStringNotContainsString("sh -lc 'pg_restore -l'", $script);
        self::assertStringContainsString('migrate --force', $script);
        self::assertStringContainsString('--force-recreate app horizon scheduler telegram', $script);
        self::assertSame(2, substr_count($script, '--force-recreate app horizon scheduler telegram < /dev/null'));
        self::assertStringContainsString('up -d --wait < /dev/null', $script);
        self::assertStringContainsString("'[.services.app, .services.horizon, .services.scheduler, .services.telegram] | all(.image == \$image)'", $script);
        self::assertStringContainsString('up -d postgres redis', $script);
        self::assertStringContainsString('trap \'rollback "$LINENO" "$?"\' ERR', $script);
        self::assertStringContainsString('Horizon did not report a running supervisor', $script);
        self::assertStringContainsString('Protected host services and routing match the pre-deploy baseline.', $script);
        self::assertStringContainsString('report_preflight_failure', $script);
        self::assertStringContainsString('CHUKLOV_CONTAINER_IP', $script);
        self::assertStringContainsString('CHUKLOV_LOOPBACK_GUARD_PRESENT', $script);
        self::assertStringContainsString('Expected exactly one loopback guard', $script);
        self::assertStringContainsString('DYNAMIC_BANS', $script);
        self::assertStringContainsString('normalize_legacy_app_server_command', $script);
        self::assertStringContainsString('forbidden php -S app command', $script);
        self::assertStringContainsString('prepare_runtime_ownership', $script);
        self::assertStringContainsString('chown -R 33:33', $script);
        self::assertStringContainsString('prepare_release_permissions', $script);
        self::assertStringContainsString('chown -R root:33', $script);
        self::assertStringNotContainsString('down -v', $script);
        self::assertStringNotContainsString('docker system prune', $script);
        self::assertStringNotContainsString('docker volume prune', $script);
    }

    #[Test]
    public function real_staging_deployment_environment_is_ignored(): void
    {
        $gitignore = file_get_contents(base_path('.gitignore'));

        self::assertIsString($gitignore);
        self::assertStringContainsString('.env.staging-deploy', $gitignore);
        self::assertFileExists(base_path('.env.staging-deploy.example'));
    }
}
