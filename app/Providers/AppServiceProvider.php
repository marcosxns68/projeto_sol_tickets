<?php

namespace App\Providers;

use App\Http\Controllers\TicketFolderContextController;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketFolder;
use App\Services\DepartmentAccess;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // A criação normal continua na raiz. O contexto de pasta só é aplicado
        // quando o formulário envia folder_id explicitamente (ex.: "+ Nova tarefa"
        // dentro da árvore). A validação acontece antes do INSERT e, portanto,
        // participa da mesma transação já usada pelo TicketController.
        Ticket::creating(function (Ticket $ticket): void {
            $request = request();
            if (!$request->routeIs('tickets.store') || !$request->has('folder_id')) {
                return;
            }

            $rawFolderId = $request->input('folder_id');
            if ($rawFolderId === null || $rawFolderId === '') {
                $ticket->folder_id = null;
                return;
            }

            if (filter_var($rawFolderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw ValidationException::withMessages([
                    'folder_id' => 'Selecione uma pasta válida.',
                ]);
            }

            $folder = TicketFolder::query()->find((int) $rawFolderId);
            $department = $ticket->department_id
                ? Department::query()->find((int) $ticket->department_id)
                : null;

            if (!$folder || !$department || $department->isTriage() || !$folder->belongsToDepartment($department)) {
                throw ValidationException::withMessages([
                    'folder_id' => 'A pasta selecionada não pertence ao departamento de destino.',
                ]);
            }

            $user = $request->user();
            abort_unless($user && app(DepartmentAccess::class)->canEdit($user, $department), 403);

            $ticket->folder_id = $folder->id;
        });

        // Endpoints leves usados apenas para montar o seletor de pasta no PWA/web.
        // O endpoint de departamento exige visualização real da caixa; Nível 1
        // (Enviar) não consegue enumerar a estrutura interna por URL.
        Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
            Route::get('/departamentos/{department}/pastas', [TicketFolderContextController::class, 'department'])
                ->name('departments.folders.options');
            Route::get('/tickets/{ticket}/pastas', [TicketFolderContextController::class, 'ticket'])
                ->name('tickets.folders.context');
        });
    }
}
