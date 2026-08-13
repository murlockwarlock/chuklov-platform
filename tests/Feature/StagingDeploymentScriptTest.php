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
        self::assertStringContainsString('pg_restore -l', $script);
        self::assertStringNotContainsString("sh -lc 'pg_restore -l'", $script);
        self::assertStringContainsString('migrate --force', $script);
        self::assertStringContainsString('--force-recreate app horizon scheduler telegram', $script);
        self::assertStringContainsString('up -d postgres redis', $script);
        self::assertStringContainsString('trap rollback ERR', $script);
        self::assertStringContainsString('report_preflight_failure', $script);
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
