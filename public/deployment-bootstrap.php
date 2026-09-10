<?php

declare(strict_types=1);

final class DeploymentBootstrap
{
    public static function apply(string $archive, string $target): void
    {
        if (!is_file($archive)) {
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Não foi possível abrir o pacote da nova versão.');
        }

        if (!$zip->extractTo($target)) {
            $zip->close();
            throw new RuntimeException('Não foi possível aplicar o pacote da nova versão.');
        }

        $zip->close();

        if (!@unlink($archive) && is_file($archive)) {
            throw new RuntimeException('A nova versão foi aplicada, mas o pacote temporário não pôde ser removido.');
        }
    }
}
