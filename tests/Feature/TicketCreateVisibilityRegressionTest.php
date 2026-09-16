<?php

namespace Tests\Feature;

use Tests\TestCase;

class TicketCreateVisibilityRegressionTest extends TestCase
{
    public function test_progressive_create_sections_force_hidden_elements_out_of_layout(): void
    {
        $source = file_get_contents(resource_path('views/tickets/create.blade.php'));

        $this->assertStringContainsString(
            '.ticket-create-v2 [hidden]{display:none!important}',
            $source
        );
    }
}
