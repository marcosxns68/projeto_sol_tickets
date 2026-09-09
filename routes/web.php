<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Auth\EmailVerificationRequest;

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [AuthController::class, 'login'])->name('login');
    Route::post('/entrar', [AuthController::class, 'authenticate'])->name('login.perform');
    Route::get('/cadastro', [AuthController::class, 'register'])->name('register');
    Route::post('/cadastro', [AuthController::class, 'store'])->name('register.store');
});
Route::get('/email/verificar', fn()=>view('auth.verify-email'))->middleware('auth')->name('verification.notice');
Route::get('/email/verificar/{id}/{hash}', function(EmailVerificationRequest $request){$request->fulfill();return redirect()->route('dashboard');})->middleware(['auth','signed'])->name('verification.verify');
Route::post('/email/reenviar', function(Illuminate\Http\Request $request){$request->user()->sendEmailVerificationNotification();return back()->with('success','Novo link enviado.');})->middleware(['auth','throttle:6,1'])->name('verification.send');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::resource('tickets', TicketController::class)->except(['destroy']);
    Route::post('/sair', [AuthController::class, 'logout'])->name('logout');
});
