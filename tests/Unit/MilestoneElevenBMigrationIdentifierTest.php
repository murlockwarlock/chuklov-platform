<?php

namespace Tests\Unit;

use Tests\TestCase;

final class MilestoneElevenBMigrationIdentifierTest extends TestCase
{
    public function test_broadcast_migration_identifiers_and_down_constraint_are_symmetric(): void
    {
        $path = 'database/migrations/2026_08_26_120000_create_broadcast_engine_tables.php';
        $migration = file_get_contents(base_path($path));

        if ($migration === false) {
            self::fail('Unable to read '.$path.'.');
        }

        $upMigration = strstr($migration, 'public function down(): void', true);
        if ($upMigration === false) {
            self::fail('Unable to isolate the up method in '.$path.'.');
        }

        $matchCount = preg_match_all(
            '/(?<![a-z0-9_])([a-z][a-z0-9_]+_(?:unique|index|foreign|fk|check|uq|ix|ck))(?![a-z0-9_])/',
            $upMigration,
            $matches,
        );
        if ($matchCount === false) {
            self::fail('Migration identifier inspection failed.');
        }

        $identifiers = array_values(array_unique(array_filter($matches[1], 'is_string')));
        self::assertCount(56, $identifiers);
        foreach ($identifiers as $identifier) {
            self::assertLessThanOrEqual(63, strlen($identifier), $identifier);
        }
        self::assertCount(count($identifiers), array_unique(array_map(
            static fn (string $identifier): string => substr($identifier, 0, 63),
            $identifiers,
        )));

        self::assertStringContainsString("\$table->dropForeign('bc_campaign_org_snapshot_fk');", $migration);
        self::assertStringContainsString("dropForeign(['organization_id', 'id', 'audience_snapshot_id'])", $migration);
        self::assertStringContainsString("DB::getDriverName() === 'sqlite'", $migration);
        self::assertStringContainsString('bc_attempt_org_recipient_scope_fk', $migration);
    }
}
