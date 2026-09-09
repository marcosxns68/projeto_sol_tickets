<?php
declare(strict_types=1);
session_start();

$root = dirname(__DIR__);
$lock = $root.'/storage/app/installed.lock';
if (is_file($lock)) { http_response_code(410); exit('O Sutoorii Tickets já foi instalado.'); }
$_SESSION['install_csrf'] ??= bin2hex(random_bytes(24));
$error = null;

function env_value(string $value): string { return '"'.addcslashes($value, "\\\"").'"'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['install_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página.');
        $required = ['db_host','db_database','db_username','db_password','mail_password','admin_name','admin_email','admin_password'];
        foreach ($required as $field) if (trim((string)($_POST[$field] ?? '')) === '') throw new RuntimeException('Preencha todos os campos.');
        if (!str_ends_with(strtolower((string)$_POST['admin_email']), '@sutoorii.com')) throw new RuntimeException('O Super Admin precisa usar um e-mail @sutoorii.com.');
        if (strlen((string)$_POST['admin_password']) < 10) throw new RuntimeException('A senha do Super Admin deve ter pelo menos 10 caracteres.');
        if (!is_dir($root.'/vendor')) throw new RuntimeException('As dependências Laravel ainda não foram enviadas. Aguarde o deploy e tente novamente.');

        $key = 'base64:'.base64_encode(random_bytes(32));
        $env = [
            'APP_NAME="Sutoorii Tickets"','APP_ENV=production','APP_KEY='.$key,'APP_DEBUG=false','APP_URL=https://tickets.sutoorii.com','APP_TIMEZONE=America/Sao_Paulo',
            'LOG_CHANNEL=stack','LOG_LEVEL=warning','DB_CONNECTION=mysql','DB_HOST='.env_value((string)$_POST['db_host']),'DB_PORT=3306',
            'DB_DATABASE='.env_value((string)$_POST['db_database']),'DB_USERNAME='.env_value((string)$_POST['db_username']),'DB_PASSWORD='.env_value((string)$_POST['db_password']),
            'SESSION_DRIVER=database','QUEUE_CONNECTION=database','CACHE_STORE=database','MAIL_MAILER=smtp','MAIL_HOST=mail.sutoorii.com','MAIL_PORT=465',
            'MAIL_USERNAME=tickets@sutoorii.com','MAIL_PASSWORD='.env_value((string)$_POST['mail_password']),'MAIL_ENCRYPTION=ssl','MAIL_FROM_ADDRESS=tickets@sutoorii.com','MAIL_FROM_NAME="Sutoorii Tickets"',
            'IMAP_HOST=mail.sutoorii.com','IMAP_PORT=993','IMAP_ENCRYPTION=ssl','IMAP_USERNAME=tickets@sutoorii.com','IMAP_PASSWORD='.env_value((string)$_POST['mail_password']),
        ];
        if (file_put_contents($root.'/.env', implode(PHP_EOL, $env).PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Não foi possível criar o .env. Verifique as permissões da pasta.');

        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
        Illuminate\Support\Facades\Artisan::call('db:seed', ['--force'=>true]);
        $role = App\Models\Role::where('name','Super Admin')->firstOrFail();
        $user = App\Models\User::updateOrCreate(['email'=>strtolower((string)$_POST['admin_email'])], ['name'=>(string)$_POST['admin_name'],'password'=>(string)$_POST['admin_password'],'role_id'=>$role->id,'active'=>true,'email_verified_at'=>now()]);
        @mkdir(dirname($lock), 0775, true);
        file_put_contents($lock, json_encode(['installed_at'=>date(DATE_ATOM),'admin_id'=>$user->id]), LOCK_EX);
        session_destroy();
        header('Location: /entrar?installed=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar Sutoorii Tickets</title><style>body{margin:0;background:#f6f4fb;color:#18181b;font:15px system-ui}.card{max-width:720px;margin:35px auto;background:#fff;border:1px solid #e4e4e7;border-radius:20px;padding:28px;box-shadow:0 20px 50px #4c1d9515}h1{margin:0;color:#5b21b6}.sub{color:#71717a}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}label{display:grid;gap:6px;font-weight:650;margin-top:14px}input{padding:12px;border:1px solid #d4d4d8;border-radius:10px;font:inherit}button{width:100%;margin-top:24px;padding:13px;border:0;border-radius:11px;background:#6d28d9;color:#fff;font-weight:750}.error{padding:12px;background:#fee2e2;color:#991b1b;border-radius:10px}@media(max-width:650px){.card{margin:0;border:0;border-radius:0;min-height:100vh}.grid{grid-template-columns:1fr}}</style></head><body><main class="card"><h1>Sutoorii Tickets</h1><p class="sub">Instalação segura de uso único. O instalador será bloqueado após a conclusão.</p><?php if($error):?><div class="error"><?=htmlspecialchars($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['install_csrf'])?>"><h2>Banco de dados</h2><div class="grid"><label>Servidor<input name="db_host" value="localhost" required></label><label>Banco<input name="db_database" value="sutoorii_tickets" required></label><label>Usuário<input name="db_username" value="admin_tickets" required></label><label>Senha do banco<input type="password" name="db_password" required></label></div><h2>E-mail</h2><label>Senha de tickets@sutoorii.com<input type="password" name="mail_password" required></label><h2>Primeiro Super Admin</h2><div class="grid"><label>Nome<input name="admin_name" required></label><label>E-mail @sutoorii.com<input type="email" name="admin_email" required></label><label>Senha<input type="password" name="admin_password" minlength="10" required></label></div><button>Configurar e criar o banco</button></form></main></body></html>
