<?php

use App\Http\Controllers\Api\V1\TicketActivityController;
use App\Http\Controllers\Api\V1\TicketAttachmentController;
use App\Http\Controllers\Api\V1\TicketCommentController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\TicketLifecycleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => ['status' => 'ok', 'service' => 'Sutoorii Tickets']);

    Route::middleware(['integration', 'throttle:integrations'])->group(function () {
        Route::get('/tickets', [TicketController::class, 'index']);
        Route::post('/tickets', [TicketController::class, 'store']);
        Route::get('/tickets/{number}', [TicketController::class, 'show']);
        Route::post('/tickets/{number}/comments', [TicketCommentController::class, 'store']);
        Route::post('/tickets/{number}/attachments', [TicketAttachmentController::class, 'store']);
        Route::get('/tickets/{number}/activity', [TicketActivityController::class, 'show']);
        Route::post('/tickets/{number}/close', [TicketLifecycleController::class, 'close']);
        Route::post('/tickets/{number}/reopen', [TicketLifecycleController::class, 'reopen']);
    });
});
