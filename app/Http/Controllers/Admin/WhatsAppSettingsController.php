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
            'instance' => ['required', 'regex:/^[a-zA-Z0-9_-]{1,80}$/'],
            'api_key' => ['nullable', 'string', 'max:1000'],
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
