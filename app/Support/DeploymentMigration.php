<?php

namespace App\Support;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use RuntimeException;

final class DeploymentMigration
{
    /**
     * Aplica migrations pendentes uma única vez, na primeira requisição real
     * após um deploy direto por FTPS. O marcador só é removido se a migration
     * terminar com sucesso.
     */
    public static function run(Application $app, string $marker, array $staleArtifacts = []): void
    {
        if (!is_file($marker)) {
            return;
        }

        /** @var ConsoleKernel $kernel */
        $kernel = $app->make(ConsoleKernel::class);
        $kernel->bootstrap();

        $exitCode = $kernel->call('migrate', ['--force' => true]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Não foi possível atualizar o banco de dados para a nova versão.');
        }

        foreach ($staleArtifacts as $artifact) {
            if (is_file($artifact)) {
                @unlink($artifact);
            }
        }

        if (!@unlink($marker) && is_file($marker)) {
            throw new RuntimeException('A atualização foi aplicada, mas o marcador de deploy não pôde ser removido.');
        }
    }
}
