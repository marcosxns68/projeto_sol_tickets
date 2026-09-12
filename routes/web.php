<?php

use App\Http\Controllers\Admin\DepartmentController as AdminDepartmentController;
use App\Http\Controllers\Admin\IntegrationController as AdminIntegrationController;
use App\Http\Controllers\Admin\LabelController as AdminLabelController;
use App\Http\Controllers\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationUserDirectoryController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\TicketAssignmentController;
use App\Http\Controllers\TicketBoxController;
use App\Http\Controllers\TicketChecklistController;
use App\Http\Controllers\TicketCommentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketLabelController;
use App\Http\Controllers\TicketLifecycleController;
use App\Http\Controllers\TicketParticipantController;
use App\Http\Controllers\TicketRoutingController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [AuthController::class, 'login'])->name('login');
    Route::post('/entrar', [AuthController::class, 'authenticate'])->name('login.perform');
    Route::get('/cadastro', [AuthController::class, 'register'])->name('register');
    Route::post('/cadastro', [AuthController::class, 'store'])->name('register.store');
    Route::get('/esqueci-senha', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/esqueci-senha', [PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/redefinir-senha/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/redefinir-senha', [PasswordResetController::class, 'reset'])->name('password.update');
});

Route::get('/email/verificar', fn () => view('auth.verify-email'))->middleware('auth')->name('verification.notice');
Route::get('/email/verificar/{id}/{hash}', function (EmailVerificationRequest $request) {
    $request->fulfill();
    return redirect()->route('dashboard');
})->middleware(['auth', 'signed'])->name('verification.verify');
Route::post('/email/reenviar', function (Illuminate\Http\Request $request) {
    $request->user()->sendEmailVerificationNotification();
    return back()->with('success', 'Novo link enviado.');
})->middleware(['auth', 'throttle:6,1'])->name('verification.send');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/minha-caixa', [TicketBoxController::class, 'mine'])->name('boxes.mine');
    Route::get('/todos-os-tickets', [TicketBoxController::class, 'all'])->name('boxes.all');
    Route::get('/departamentos/{department}/tickets', [TicketBoxController::class, 'department'])->name('boxes.department');
    Route::get('/integracoes/{integration}/usuarios', IntegrationUserDirectoryController::class)->name('integrations.users.search');

    Route::post('/tickets/{ticket}/assumir', [TicketAssignmentController::class, 'assume'])->name('tickets.assume');
    Route::patch('/tickets/{ticket}/responsavel', [TicketAssignmentController::class, 'reassign'])->name('tickets.reassign');
    Route::post('/tickets/{ticket}/encaminhar', [TicketRoutingController::class, 'forward'])->name('tickets.forward');
    Route::post('/tickets/{ticket}/participantes', [TicketParticipantController::class, 'store'])->name('tickets.participants.store');
    Route::delete('/tickets/{ticket}/participantes/{user}', [TicketParticipantController::class, 'destroy'])->name('tickets.participants.destroy');
    Route::post('/tickets/{ticket}/etiquetas', [TicketLabelController::class, 'store'])->name('tickets.labels.store');
    Route::delete('/tickets/{ticket}/etiquetas/{label}', [TicketLabelController::class, 'destroy'])->name('tickets.labels.destroy');

    Route::post('/tickets/{ticket}/comentarios', [TicketCommentController::class, 'store'])->name('tickets.comments.store');
    Route::post('/tickets/{ticket}/checklist', [TicketChecklistController::class, 'store'])->name('tickets.checklist.store');
    Route::patch('/tickets/{ticket}/checklist/{item}/alternar', [TicketChecklistController::class, 'toggle'])->name('tickets.checklist.toggle');
    Route::delete('/tickets/{ticket}/checklist/{item}', [TicketChecklistController::class, 'destroy'])->name('tickets.checklist.destroy');

    Route::post('/tickets/{ticket}/solicitar-conclusao', [TicketLifecycleController::class, 'requestCompletion'])->name('tickets.completion.request');
    Route::post('/tickets/{ticket}/resolver', [TicketLifecycleController::class, 'resolve'])->name('tickets.resolve');
    Route::post('/tickets/{ticket}/fechar', [TicketLifecycleController::class, 'close'])->name('tickets.close');
    Route::post('/tickets/{ticket}/cancelar', [TicketLifecycleController::class, 'cancel'])->name('tickets.cancel');
    Route::post('/tickets/{ticket}/reabrir', [TicketLifecycleController::class, 'reopen'])->name('tickets.reopen');

    Route::get('/admin/usuarios', [AdminUserController::class, 'index'])->name('admin.users.index');
    Route::get('/admin/usuarios/{user}/editar', [AdminUserController::class, 'edit'])->name('admin.users.edit');
    Route::patch('/admin/usuarios/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');

    Route::get('/admin/departamentos', [AdminDepartmentController::class, 'index'])->name('admin.departments.index');
    Route::post('/admin/departamentos', [AdminDepartmentController::class, 'store'])->name('admin.departments.store');
    Route::get('/admin/departamentos/{department}/editar', [AdminDepartmentController::class, 'edit'])->name('admin.departments.edit');
    Route::patch('/admin/departamentos/{department}', [AdminDepartmentController::class, 'update'])->name('admin.departments.update');

    Route::get('/admin/etiquetas', [AdminLabelController::class, 'index'])->name('admin.labels.index');
    Route::post('/admin/etiquetas', [AdminLabelController::class, 'store'])->name('admin.labels.store');
    Route::patch('/admin/etiquetas/{label}', [AdminLabelController::class, 'update'])->name('admin.labels.update');
    Route::delete('/admin/etiquetas/{label}', [AdminLabelController::class, 'destroy'])->name('admin.labels.destroy');

    Route::get('/admin/cargos', [AdminRoleController::class, 'index'])->name('admin.roles.index');
    Route::post('/admin/cargos', [AdminRoleController::class, 'store'])->name('admin.roles.store');
    Route::get('/admin/cargos/{role}/editar', [AdminRoleController::class, 'edit'])->name('admin.roles.edit');
    Route::patch('/admin/cargos/{role}', [AdminRoleController::class, 'update'])->name('admin.roles.update');

    Route::get('/admin/integracoes', [AdminIntegrationController::class, 'index'])->name('admin.integrations.index');
    Route::post('/admin/integracoes', [AdminIntegrationController::class, 'store'])->name('admin.integrations.store');
    Route::patch('/admin/integracoes/{integration}', [AdminIntegrationController::class, 'update'])->name('admin.integrations.update');
    Route::post('/admin/integracoes/{integration}/nova-chave', [AdminIntegrationController::class, 'rotateKey'])->name('admin.integrations.rotate-key');
    Route::post('/admin/integracoes/{integration}/novo-segredo-webhook', [AdminIntegrationController::class, 'rotateWebhookSecret'])->name('admin.integrations.rotate-webhook-secret');

    Route::resource('tickets', TicketController::class)->except(['destroy']);
    Route::post('/sair', [AuthController::class, 'logout'])->name('logout');
});
