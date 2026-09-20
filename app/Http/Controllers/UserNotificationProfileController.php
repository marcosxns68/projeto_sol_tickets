<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppConnection;
use Illuminate\Http\Request;

class UserNotificationProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile.notifications', ['managedUser' => $request->user()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'whatsapp' => ['nullable', 'string', 'max:30', function ($field, $value, $fail) {
                if (filled($value) && WhatsAppConnection::normalizeNumber($value) === null) {
                    $fail('Informe seu WhatsApp com DDD válido, por exemplo (15) 99999-8888.');
                }
            }],
            'whatsapp_reply_enabled' => ['nullable', 'boolean'],
        ]);

        $request->user()->update([
            'whatsapp' => $data['whatsapp'] ?? null,
            'whatsapp_reply_enabled' => $request->boolean('whatsapp_reply_enabled'),
        ]);

        return redirect()->route('profile.notifications.edit')
            ->with('success', 'Preferências de WhatsApp atualizadas.');
    }
}
