<?php

namespace Tests\Feature;

use Tests\TestCase;

class MobileSidebarRegressionTest extends TestCase
{
    public function test_responsive_stylesheet_reenables_sidebar_on_mobile(): void
    {
        // A folha principal antiga esconde a sidebar no breakpoint móvel.
        // A camada responsiva precisa reativá-la antes de aplicar o drawer.
        $css = file_get_contents(public_path('css/responsive-shell.css'));

        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 900px\).*?\.app-sidebar,\s*\.app-shell\.sidebar-collapsed \.app-sidebar\s*\{[^}]*display:\s*flex/s',
            $css
        );
    }
}
