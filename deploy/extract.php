<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido.'); }
$token = (string)($_POST['token'] ?? '');
if (!hash_equals('__DEPLOY_HASH__', hash('sha256', $token))) { http_response_code(403); exit('Não autorizado.'); }

$archive = __DIR__.'/release.zip';
$target = dirname(__DIR__);
if (!is_file($archive)) { http_response_code(404); exit('Pacote não encontrado.'); }

$zip = new ZipArchive();
if ($zip->open($archive) !== true) { http_response_code(500); exit('Não foi possível abrir o pacote.'); }
if (!$zip->extractTo($target)) { $zip->close(); http_response_code(500); exit('Não foi possível extrair o pacote.'); }
$zip->close();

try {
    require_once $target.'/vendor/autoload.php';
    $app = require $target.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $migrationCode = $kernel->call('migrate', ['--force' => true]);
    if ($migrationCode !== 0) {
        http_response_code(500);
        exit("Arquivos extraídos, mas a atualização do banco falhou.\n".$kernel->output());
    }

    $kernel->call('optimize:clear');
} catch (Throwable $e) {
    http_response_code(500);
    exit('Falha ao finalizar a atualização do sistema: '.$e->getMessage());
}

@unlink($archive);
@unlink(__FILE__);
echo 'Deploy concluído. Banco de dados atualizado.';
