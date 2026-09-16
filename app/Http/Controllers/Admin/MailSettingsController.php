<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SmtpTestMail;
use App\Services\MailSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

class MailSettingsController extends Controller
{
    public function edit(Request $request, MailSettings $settings)
    {
        $this->authorizeFullAdmin($request);

        return view('admin.settings.mail', [
            'mailSettings' => $settings->values(),
        ]);
    }

    public function update(Request $request, MailSettings $settings)
    {
        $this->authorizeFullAdmin($request);

        $data = $request->validate([
            'host' => ['required', 'string', 'max:190'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:500'],
            'encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
            'from_address' => ['required', 'email', 'max:190'],
        ]);

        $old = $this->auditValues($settings->values());
        $passwordChanged = filled($data['password'] ?? null);

        $settings->save($data);
        $current = $this->auditValues($settings->values());
        $current['password_changed'] = $passwordChanged;

        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'auditable_type' => MailSettings::class,
            'auditable_id' => null,
            'event' => 'mail.settings.updated',
            'old_values' => json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode($current, JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('admin.settings.mail.edit')
            ->with('success', 'Configuração de e-mail atualizada.');
    }

    public function test(Request $request, MailSettings $settings)
    {
        $this->authorizeFullAdmin($request);

        $data = $request->validate([
            'test_email' => ['required', 'email', 'max:190'],
        ]);

        try {
            $settings->apply();
            app('mail.manager')->purge('smtp');
            Mail::to($data['test_email'])->send(new SmtpTestMail());
        } catch (Throwable $exception) {
            Log::warning('Falha no teste SMTP do Sutoorii Tickets.', [
                'exception' => $exception::class,
                'code' => $exception->getCode(),
            ]);

            return redirect()
                ->route('admin.settings.mail.edit')
                ->withErrors([
                    'test_email' => 'Não foi possível enviar o e-mail de teste. Verifique servidor, porta, criptografia, usuário e senha.',
                ]);
        }

        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'auditable_type' => MailSettings::class,
            'auditable_id' => null,
            'event' => 'mail.settings.test_sent',
            'old_values' => null,
            'new_values' => json_encode(['recipient' => $data['test_email']], JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('admin.settings.mail.edit')
            ->with('success', 'E-mail de teste enviado para '.$data['test_email'].'.');
    }

    private function authorizeFullAdmin(Request $request): void
    {
        $actor = $request->user();

        abort_unless(
            $actor->hasPermission('users.manage') && $actor->hasPermission('permissions.manage'),
            403
        );
    }

    private function auditValues(array $values): array
    {
        return [
            'host' => $values['host'] ?? null,
            'port' => $values['port'] ?? null,
            'username' => $values['username'] ?? null,
            'encryption' => $values['encryption'] ?? null,
            'from_address' => $values['from_address'] ?? null,
            'from_name' => 'Sutoorii Tickets',
        ];
    }
}
