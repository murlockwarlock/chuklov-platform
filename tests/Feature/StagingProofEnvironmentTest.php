<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StagingProofEnvironmentTest extends TestCase
{
    public function test_streamed_queue_probe_boots_without_new_proof_helpers_on_the_previous_release(): void
    {
        $source = file_get_contents(base_path('scripts/staging-smoke.php'));
        self::assertIsString($source);
        $bootstrapBoundary = strpos($source, '$arguments = options();');
        self::assertNotFalse($bootstrapBoundary);
        $prefix = substr($source, 0, $bootstrapBoundary);
        $prefix = str_replace("require '/app/vendor/autoload.php';", 'require '.var_export(base_path('vendor/autoload.php'), true).';', $prefix);
        $process = new Process([PHP_BINARY]);
        $process->setInput($prefix.'echo "PROBE_BOOT_OK";');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        self::assertSame('PROBE_BOOT_OK', $process->getOutput());
    }

    #[DataProvider('unsafeEnvironments')]
    public function test_external_proof_mutations_reject_any_unapproved_environment(string $environment): void
    {
        require_once base_path('scripts/staging-proof-integrations.php');
        app()->instance('env', $environment);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Proof mutations require the explicitly authorized staging environment.');

        \requireStagingProofTarget();
    }

    public static function unsafeEnvironments(): array
    {
        return [['production'], ['local'], ['testing'], ['development']];
    }
}
