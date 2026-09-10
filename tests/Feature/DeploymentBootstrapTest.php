<?php

namespace Tests\Feature;

use Tests\TestCase;
use ZipArchive;

class DeploymentBootstrapTest extends TestCase
{
    public function test_private_release_is_extracted_and_removed_before_application_boot(): void
    {
        $bootstrap = public_path('deployment-bootstrap.php');
        $this->assertFileExists($bootstrap);
        require_once $bootstrap;
        $this->assertTrue(class_exists('DeploymentBootstrap'));

        $base = storage_path('framework/testing-bootstrap');
        $target = $base.'/target';
        @mkdir($target, 0775, true);
        $archive = $base.'/release.zip';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('release-marker.txt', 'nova-versao');
        $zip->close();

        DeploymentBootstrap::apply($archive, $target);

        $this->assertSame('nova-versao', file_get_contents($target.'/release-marker.txt'));
        $this->assertFileDoesNotExist($archive);
    }
}
