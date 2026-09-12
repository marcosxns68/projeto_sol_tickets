<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsNoAdminExposureFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_labels_admin_route_requires_authenticated_user(): void
    {
        $this->get('/admin/etiquetas')->assertRedirect('/entrar');
    }
}
