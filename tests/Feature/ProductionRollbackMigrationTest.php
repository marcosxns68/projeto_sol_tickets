<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionRollbackMigrationTest extends TestCase
{
    public function test_failed_integrations_migration_is_overwritten_by_noop_tombstone(): void
    {
        $path = database_path('migrations/2026_09_11_000004_add_integrations_api_foundation.php');

        $this->assertFileExists($path);

        $contents = file_get_contents($path);

        $this->assertStringContainsString('production rollback tombstone', $contents);
        $this->assertStringNotContainsString('Schema::table', $contents);
        $this->assertStringNotContainsString('Schema::create', $contents);
    }
}
