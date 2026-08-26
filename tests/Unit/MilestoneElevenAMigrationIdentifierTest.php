<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MilestoneElevenAMigrationIdentifierTest extends TestCase
{
    #[Test]
    public function m11a_schema_identifiers_fit_postgresql_limit_without_truncation_collisions(): void
    {
        $migrationPaths = [
            'database/migrations/2026_08_24_164059_create_m11a_attribution_referral_feedback_tables.php',
            'database/migrations/2026_08_24_195627_add_event_obligation_provenance_to_referral_evidence_table.php',
        ];
        $identifiers = [];

        foreach ($migrationPaths as $migrationPath) {
            $migration = file_get_contents(base_path($migrationPath));

            if ($migration === false) {
                self::fail('Unable to read '.$migrationPath.'.');
            }

            $upMigration = strstr($migration, 'public function down(): void', true);

            if ($upMigration === false) {
                self::fail('Unable to isolate the up method in '.$migrationPath.'.');
            }

            $matchCount = preg_match_all(
                '/(?<![a-z0-9_])([a-z][a-z0-9_]+_(?:unique|index|foreign|fk|check))(?![a-z0-9_])/',
                $upMigration,
                $matches,
            );

            if ($matchCount === false) {
                self::fail('Unable to inspect identifiers in '.$migrationPath.'.');
            }

            foreach ($matches[1] ?? [] as $identifier) {
                if (! is_string($identifier)) {
                    self::fail('Migration identifier matches must be strings.');
                }

                $identifiers[] = $identifier;
            }
        }

        self::assertCount(71, $identifiers);
        self::assertCount(count($identifiers), array_unique($identifiers));

        foreach ($identifiers as $identifier) {
            self::assertLessThanOrEqual(63, strlen($identifier), $identifier);
        }

        $truncatedIdentifiers = array_map(
            static fn (string $identifier): string => substr($identifier, 0, 63),
            $identifiers,
        );

        self::assertCount(count($identifiers), array_unique($truncatedIdentifiers));
    }
}
