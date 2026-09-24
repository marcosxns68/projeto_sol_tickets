<?php

namespace App\Http\Controllers;

use App\Models\PwaPushSubscription;
use App\Services\PwaWebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PwaPushController extends Controller
{
    public function config(Request $request, PwaWebPush $push): JsonResponse
    {
        return response()->json(['publicKey' => $push->publicKey()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function subscribe(Request $request, PwaWebPush $push): JsonResponse
    {
        $data = $request->validate([
            'subscription' => ['required', 'array'],
            'subscription.endpoint' => ['required', 'string', 'max:2048'],
            'subscription.keys' => ['required', 'array'],
            'subscription.keys.p256dh' => ['required', 'string', 'max:120'],
            'subscription.keys.auth' => ['required', 'string', 'max:64'],
            'device_label' => ['nullable', 'string', 'max:120'],
        ]);

        $value = PwaWebPush::validateSubscription($data['subscription']);
        if ($value === null) {
            return response()->json(['message' => 'Assinatura push inválida para este dispositivo.'], 422);
        }

        // Ensures the key already exists before the subscription is stored.
        $push->publicKey();
        $hash = hash('sha256', $value['endpoint']);
        DB::transaction(function () use ($request, $value, $hash, $data) {
            // One physical subscription belongs to only one logged-in account.
            PwaPushSubscription::query()->where('endpoint_hash', $hash)->delete();
            PwaPushSubscription::create([
                'user_id' => $request->user()->id,
                'endpoint_hash' => $hash,
                'subscription' => $value,
                'device_label' => substr((string) ($data['device_label'] ?? 'PWA'), 0, 120),
            ]);
            $ids = PwaPushSubscription::where('user_id', $request->user()->id)
                ->orderByDesc('id')->skip(10)->take(100)->pluck('id');
            if ($ids->isNotEmpty()) {
                PwaPushSubscription::whereIn('id', $ids)->delete();
            }
        });

        return response()->json(['message' => 'Notificações push ativadas neste dispositivo.']);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);
        PwaPushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->json(['message' => 'Notificações push desativadas neste dispositivo.']);
    }

    public function state(Request $request): JsonResponse
    {
        return response()->json([
            'devices' => PwaPushSubscription::where('user_id', $request->user()->id)->count(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
