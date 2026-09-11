<?php

use App\Http\Controllers\Api\V1\IntegrationTicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => ['status' => 'ok', 'service' => 'Sutoorii Tickets']);

    Route::middleware(['integration'])->group(function () {
        Route::get('/tickets', [IntegrationTicketController::class, 'index']);
        Route::post('/tickets', [IntegrationTicketController::class, 'store']);
        Route::get('/tickets/{reference}', [IntegrationTicketController::class, 'show']);
        Route::post('/tickets/{reference}/comments', [IntegrationTicketController::class, 'comment']);
        Route::post('/tickets/{reference}/attachments', [IntegrationTicketController::class, 'attachment']);
        Route::get('/tickets/{reference}/attachments/{attachment}', [IntegrationTicketController::class, 'downloadAttachment']);
        Route::get('/tickets/{reference}/activity', [IntegrationTicketController::class, 'activity']);
        Route::post('/tickets/{reference}/close', [IntegrationTicketController::class, 'close']);
        Route::post('/tickets/{reference}/reopen', [IntegrationTicketController::class, 'reopen']);
    });
});
