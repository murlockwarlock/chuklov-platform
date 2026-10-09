<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StagingProofEnvironmentTest extends TestCase
{
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
