<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandIdentityTest extends TestCase
{
    public function test_guest_and_application_layout_use_official_sutoorii_tickets_brand(): void
    {
        $this->get('/entrar')
            ->assertOk()
            ->assertSee('brand/sutoorii-tickets-logo.svg', false)
            ->assertSee('icons/sutoorii-tickets-icon.svg', false);

        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('sutoorii-tickets-icon.svg', $layout);
        $this->assertStringContainsString('sutoorii-tickets-logo.svg', $layout);
        $this->assertStringNotContainsString('<span class="brand-mark">S</span>', $layout);
    }

    public function test_pwa_and_push_use_generated_official_icon(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('/icons/sutoorii-tickets-icon-512.png', $manifest['icons'][0]['src']);
        $this->assertSame('512x512', $manifest['icons'][0]['sizes']);
        $this->assertSame('image/png', $manifest['icons'][0]['type']);
        $this->assertStringContainsString('maskable', $manifest['icons'][0]['purpose']);

        $this->assertFileExists(public_path('icons/sutoorii-tickets-icon.svg'));
        $this->assertFileExists(public_path('icons/sutoorii-tickets-icon-512.png'));
        $this->assertGreaterThan(1000, filesize(public_path('icons/sutoorii-tickets-icon-512.png')));

        $worker = file_get_contents(public_path('service-worker.js'));
        $this->assertStringContainsString('/icons/sutoorii-tickets-icon-512.png', $worker);
        $this->assertStringContainsString("sutoorii-tickets-v4", $worker);
    }
}
