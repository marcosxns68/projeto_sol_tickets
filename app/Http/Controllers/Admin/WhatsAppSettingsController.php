<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppConnection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class WhatsAppSettingsController extends Controller
{
    public function edit(Request $request, WhatsAppConnection $connection)
    {
        $this->authorizeAdmin($request);

        return view('admin.settings.whatsapp', [
            'settings' => $connection->values(),
            'qrCode' => null,
            'connectionState' => null,
        ]);
    }

    public function update(Request $request, WhatsAppConnection $connection)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'base_url' => ['required', 'url:https', 'max:250'],
            'instance' => ['required', 'string', 'max:80', 'regex:/^[\\pL\\pN][\\pL\\pN _.\\-]{0,79}$/u'],
            'api_key' => ['nullable', 'string', 'max:1000'],
        ], [
            'base_url.required' => 'Informe o endereço da Evolution API.',
            'base_url.url' => 'Informe uma URL HTTPS válida para a Evolution API.',
            'base_url.max' => 'O endereço da Evolution API deve ter no máximo 250 caracteres.',
            'instance.required' => 'Informe o nome exato da instância cadastrada na Evolution API.',
            'instance.string' => 'Informe um nome de instância válido.',
            'instance.max' => 'O nome da instância deve ter no máximo 80 caracteres.',
            'instance.regex' => 'O nome da instância contém caracteres não permitidos. Use letras, números, espaços, pontos, hífen ou sublinhado.',
            'api_key.string' => 'Informe uma chave de API válida.',
            'api_key.max' => 'A chave de API deve ter no máximo 1000 caracteres.',
        ]);

        if (!$connection->values()['api_key_saved'] && blank($data['api_key'] ?? null)) {
            throw ValidationException::withMessages(['api_key' => 'Informe a chave de API para a primeira configuração.']);
        }

        try {
            $connection->save($data);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['base_url' => $exception->getMessage()]);
        }

        return redirect()->route('admin.settings.whatsapp.edit')
            ->with('success', 'Configuração do WhatsApp salva. Acesse Conectar para obter o QR Code.');
    }

    public function qrCode(Request $request, WhatsAppConnection $connection)
    {
        $this->authorizeAdmin($request);

        try {
            $qrCode = $connection->qrCode();
        } catch (Throwable $exception) {
            return redirect()->route('admin.settings.whatsapp.edit')
                ->withErrors(['whatsapp' => $exception->getMessage()]);
        }

        return response()->view('admin.settings.whatsapp', [
            'settings' => $connection->values(),
            'qrCode' => $qrCode,
            'connectionState' => null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function status(Request $request, WhatsAppConnection $connection)
    {
        $this->authorizeAdmin($request);

        try {
            $status = $connection->connectionState();
        } catch (Throwable $exception) {
            return redirect()->route('admin.settings.whatsapp.edit')
                ->withErrors(['whatsapp' => $exception->getMessage()]);
        }

        return view('admin.settings.whatsapp', [
            'settings' => $connection->values(),
            'qrCode' => null,
            'connectionState' => $status,
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role?->name === 'Super Admin', 403);
    }
}
