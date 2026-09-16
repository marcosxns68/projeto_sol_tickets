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

    #[Test]
    public function deployment_runs_migrations_through_one_time_authenticated_trigger_before_verification(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertStringContainsString('deploy-migrate.php', $workflow);
        $this->assertStringContainsString('X-Deploy-Token:', $workflow);
        $this->assertStringContainsString("hash('sha256'", $workflow);
        $this->assertStringContainsString('unlink(__FILE__)', $workflow);
        $this->assertStringContainsString('migration-ok', $workflow);

        $migrationPosition = strpos($workflow, 'migration-ok');
        $verificationPosition = strpos($workflow, '- name: Verificar produção');

        $this->assertNotFalse($migrationPosition);
        $this->assertNotFalse($verificationPosition);
        $this->assertLessThan($verificationPosition, $migrationPosition);
    }
}
