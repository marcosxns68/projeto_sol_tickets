<?php

namespace App\Http\Controllers;

use App\Models\ConnectedSystem;
use App\Services\IntegrationUserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class IntegrationUserDirectoryController extends Controller
{
    public function __invoke(Request $request, ConnectedSystem $integration, IntegrationUserDirectory $directory): JsonResponse
    {
        abort_unless($request->user()->hasPermission('tickets.create'), 403);
        abort_unless($integration->active, 404);

        $data = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:160'],
        ]);

        try {
            $users = $directory->search($integration, $data['search']);
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'Não foi possível consultar os usuários desta integração agora.',
            ], 503);
        }

        return response()->json(['data' => $users]);
    }
}
