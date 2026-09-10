<?php

namespace Tests\Feature;

use App\Support\DeploymentMigration;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeploymentMigrationTest extends TestCase
{
    public function test_pending_deployment_runs_migrations_and_cleans_marker_and_artifacts(): void
    {
        $base = storage_path('framework/testing-deploy');
        @mkdir($base, 0775, true);

        $marker = $base.'/pending';
        $artifact = $base.'/release.zip';
        file_put_contents($marker, 'pending');
        file_put_contents($artifact, 'old');

        DeploymentMigration::run($this->app, $marker, [$artifact]);

        $this->assertTrue(Schema::hasTable('user_permission_overrides'));
        $this->assertFileDoesNotExist($marker);
        $this->assertFileDoesNotExist($artifact);
    }
}
