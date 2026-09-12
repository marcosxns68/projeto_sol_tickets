<?php

namespace Tests\Feature;

use App\Models\Label;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LabelsExistingSchemaFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_labels_schema_supports_name_and_color(): void
    {
        $this->assertTrue(Schema::hasColumns('labels', ['name', 'color']));
        $label = Label::query()->create(['name' => 'Teste', 'color' => '#6d28d9']);
        $this->assertSame('Teste', $label->name);
    }
}
