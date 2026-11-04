<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StagingReleaseRetentionTest extends TestCase
{
    #[Test]
    public function retention_keeps_active_and_five_latest_successful_releases_and_removes_old_releases(): void
    {
        $fixture = $this->createFixture([
            'A' => 1000,
            'B' => 900,
            'C' => 890,
            'D' => 880,
            'E' => 870,
            'F' => 860,
            'G' => 100,
            'H' => 90,
            'I' => 80,
        ]);
        symlink(
            $fixture['releases'].'/'.$this->releaseName('B'),
            $fixture['root'].'/previous',
        );
        symlink(
            $fixture['releases'].'/'.$this->releaseName('F'),
            $fixture['root'].'/rollback',
        );

        try {
            $dryRun = $this->runRetention('--dry-run', $fixture, 5);

            self::assertSame(0, $dryRun->getExitCode(), $dryRun->getOutput().$dryRun->getErrorOutput());
            self::assertStringContainsString('retention=5', $dryRun->getOutput());
            self::assertStringContainsString('candidate_count=3', $dryRun->getOutput());
            self::assertStringContainsString('candidates='.$this->releaseName('G'), $dryRun->getOutput());
            self::assertStringContainsString($this->releaseName('H'), $dryRun->getOutput());
            self::assertStringContainsString($this->releaseName('I'), $dryRun->getOutput());
            self::assertDirectoryExists($fixture['releases'].'/'.$this->releaseName('G'));

            $cleanup = $this->runRetention('--cleanup', $fixture, 5);

            self::assertSame(0, $cleanup->getExitCode(), $cleanup->getOutput().$cleanup->getErrorOutput());
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $label) {
                self::assertDirectoryExists($fixture['releases'].'/'.$this->releaseName($label));
            }
            foreach (['G', 'H', 'I'] as $label) {
                self::assertDirectoryDoesNotExist($fixture['releases'].'/'.$this->releaseName($label));
            }
            self::assertSame(
                realpath($fixture['releases'].'/'.$this->releaseName('A')),
                realpath($fixture['root'].'/current'),
            );

            $secondCleanup = $this->runRetention('--cleanup', $fixture, 5);

            self::assertSame(0, $secondCleanup->getExitCode(), $secondCleanup->getOutput().$secondCleanup->getErrorOutput());
            self::assertStringContainsString('candidate_count=0', $secondCleanup->getOutput());
        } finally {
            (new Filesystem)->remove($fixture['root']);
        }
    }

    #[Test]
    public function symlink_release_and_outside_target_are_rejected_without_deletion(): void
    {
        $fixture = $this->createFixture(['A' => 1000]);
        $outside = sys_get_temp_dir().'/chuklov-retention-outside-'.bin2hex(random_bytes(6));
        mkdir($outside, 0755, true);
        file_put_contents($outside.'/sentinel', 'preserve');
        $linkedName = $this->releaseName('Z');
        symlink($outside, $fixture['releases'].'/'.$linkedName);

        try {
            $cleanup = $this->runRetention('--cleanup', $fixture, 5);

            self::assertNotSame(0, $cleanup->getExitCode(), $cleanup->getOutput().$cleanup->getErrorOutput());
            self::assertStringContainsString('unsafe_entries=', $cleanup->getOutput());
            self::assertFileExists($outside.'/sentinel');
            self::assertDirectoryExists($fixture['releases'].'/'.$linkedName);
            self::assertDirectoryExists($fixture['releases'].'/'.$this->releaseName('A'));
        } finally {
            (new Filesystem)->remove($fixture['root']);
            (new Filesystem)->remove($outside);
        }
    }

    #[Test]
    public function fewer_than_five_successful_releases_are_all_retained(): void
    {
        $fixture = $this->createFixture([
            'A' => 1000,
            'B' => 900,
        ]);

        try {
            $cleanup = $this->runRetention('--cleanup', $fixture, 5);

            self::assertSame(0, $cleanup->getExitCode(), $cleanup->getOutput().$cleanup->getErrorOutput());
            self::assertStringContainsString('candidate_count=0', $cleanup->getOutput());
            self::assertDirectoryExists($fixture['releases'].'/'.$this->releaseName('A'));
            self::assertDirectoryExists($fixture['releases'].'/'.$this->releaseName('B'));
        } finally {
            (new Filesystem)->remove($fixture['root']);
        }
    }

    #[Test]
    public function zero_retention_keeps_only_active_and_protected_successful_releases(): void
    {
        $fixture = $this->createFixture([
            'A' => 1000,
            'B' => 900,
        ]);

        try {
            $cleanup = $this->runRetention('--cleanup', $fixture, 0);

            self::assertSame(0, $cleanup->getExitCode(), $cleanup->getOutput().$cleanup->getErrorOutput());
            self::assertDirectoryExists($fixture['releases'].'/'.$this->releaseName('A'));
            self::assertDirectoryDoesNotExist($fixture['releases'].'/'.$this->releaseName('B'));
        } finally {
            (new Filesystem)->remove($fixture['root']);
        }
    }

    #[Test]
    public function unrecognized_release_entry_blocks_even_dry_run(): void
    {
        $fixture = $this->createFixture(['A' => 1000]);
        mkdir($fixture['releases'].'/unexpected-entry', 0755, true);

        try {
            $dryRun = $this->runRetention('--dry-run', $fixture, 5);

            self::assertNotSame(0, $dryRun->getExitCode(), $dryRun->getOutput().$dryRun->getErrorOutput());
            self::assertStringContainsString('unsafe_entries=', $dryRun->getOutput());
            self::assertDirectoryExists($fixture['releases'].'/unexpected-entry');
        } finally {
            (new Filesystem)->remove($fixture['root']);
        }
    }

    #[Test]
    public function failed_and_incomplete_releases_use_a_separate_grace_period(): void
    {
        $fixture = $this->createFixture([
            'A' => 1000,
            'B' => 900,
            'C' => 890,
            'D' => 880,
            'E' => 870,
            'F' => 860,
        ]);
        $oldFailed = $this->releaseName('G').'.failed';
        $recentFailed = $this->releaseName('H').'.failed-retry';
        $oldIncomplete = $this->releaseName('I');
        $recentIncomplete = $this->releaseName('J');
        $protectedFailed = $this->releaseName('K').'.incomplete';
        $oldTime = time() - (72 * 3600);

        $this->createPendingRelease($fixture['releases'], $oldFailed, $oldTime);
        $this->createPendingRelease($fixture['releases'], $recentFailed, time());
        $this->createPendingRelease($fixture['releases'], $oldIncomplete, $oldTime);
        $this->createPendingRelease($fixture['releases'], $recentIncomplete, time());
        $this->createPendingRelease($fixture['releases'], $protectedFailed, $oldTime);
        symlink($fixture['releases'].'/'.$protectedFailed, $fixture['root'].'/failed-rollback');

        try {
            $dryRun = $this->runRetention('--dry-run', $fixture, 5, 48);

            self::assertSame(0, $dryRun->getExitCode(), $dryRun->getOutput().$dryRun->getErrorOutput());
            self::assertStringContainsString('failed_grace_hours=48', $dryRun->getOutput());
            self::assertStringContainsString('failed_release_count=3', $dryRun->getOutput());
            self::assertStringContainsString('incomplete_release_count=2', $dryRun->getOutput());
            self::assertStringContainsString('failed_candidate_count=1', $dryRun->getOutput());
            self::assertStringContainsString('incomplete_candidate_count=1', $dryRun->getOutput());
            self::assertStringContainsString('candidate_count=2', $dryRun->getOutput());
            self::assertStringContainsString($oldFailed, $dryRun->getOutput());
            self::assertStringContainsString($oldIncomplete, $dryRun->getOutput());
            self::assertStringNotContainsString($recentFailed, $dryRun->getOutput());
            self::assertStringNotContainsString($recentIncomplete, $dryRun->getOutput());
            self::assertStringNotContainsString($protectedFailed, $dryRun->getOutput());

            $cleanup = $this->runRetention('--cleanup', $fixture, 5, 48);

            self::assertSame(0, $cleanup->getExitCode(), $cleanup->getOutput().$cleanup->getErrorOutput());
            self::assertDirectoryDoesNotExist($fixture['releases'].'/'.$oldFailed);
            self::assertDirectoryDoesNotExist($fixture['releases'].'/'.$oldIncomplete);
            self::assertDirectoryExists($fixture['releases'].'/'.$recentFailed);
            self::assertDirectoryExists($fixture['releases'].'/'.$recentIncomplete);
            self::assertDirectoryExists($fixture['releases'].'/'.$protectedFailed);
            self::assertSame(
                realpath($fixture['releases'].'/'.$protectedFailed),
                realpath($fixture['root'].'/failed-rollback'),
            );
        } finally {
            (new Filesystem)->remove($fixture['root']);
        }
    }

    private function createFixture(array $releaseTimes): array
    {
        $root = sys_get_temp_dir().'/chuklov-retention-'.bin2hex(random_bytes(6));
        $releases = $root.'/releases';
        mkdir($releases, 0755, true);

        foreach ($releaseTimes as $label => $mtime) {
            $name = $this->releaseName($label);
            $path = $releases.'/'.$name;
            mkdir($path, 0755, true);
            file_put_contents($path.'/REVISION', $name);
            touch($path.'/docker-compose.staging-qa.yml');
            touch($path, $mtime);
        }

        symlink($releases.'/'.$this->releaseName('A'), $root.'/current');

        return [
            'root' => $root,
            'releases' => $releases,
        ];
    }

    private function createPendingRelease(string $releases, string $name, int $mtime): void
    {
        $path = $releases.'/'.$name;
        mkdir($path, 0755, true);
        touch($path, $mtime);
    }

    private function releaseName(string $label): string
    {
        return str_pad(dechex(ord($label)), 40, '0', STR_PAD_LEFT);
    }

    private function runRetention(string $mode, array $fixture, int $retention, ?int $failedGraceHours = null): Process
    {
        $arguments = [
            'bash',
            base_path('scripts/staging-release-retention.sh'),
            $mode,
            $fixture['root'],
            (string) $retention,
        ];
        if ($failedGraceHours !== null) {
            $arguments[] = (string) $failedGraceHours;
        }
        $process = new Process($arguments);
        $process->run();

        return $process;
    }
}
