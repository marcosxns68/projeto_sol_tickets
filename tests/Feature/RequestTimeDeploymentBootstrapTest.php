<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RequestTimeDeploymentBootstrapTest extends TestCase
{
    #[Test]
    public function production_requests_do_not_apply_deployment_archives_or_migrations(): void
    {
        $index = file_get_contents(base_path('public/index.php'));
        $workflow = file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertStringNotContainsString('DeploymentBootstrap::apply', $index);
        $this->assertStringNotContainsString('DeploymentMigration::run', $index);
        $this->assertStringNotContainsString('deploy-release.zip', $workflow);
        $this->assertStringContainsString('.deploy/direct/', $workflow);
    }
}
