<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Notifications\DepartmentMembershipNotification;
use App\Services\DepartmentAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class DepartmentController extends Controller
{
    public function index(Request $request, DepartmentAccess $access)
    {
        $user = $request->user();
        $canManage = $user->hasPermission('departments.manage');

        if ($request->routeIs('admin.departments.index')) {
            abort_unless($canManage, 403);
        }

        $query = Department::query()
            ->with(['users' => fn ($q) => $q->where('users.active', true)->orderBy('users.name')])
            ->withCount([
                'tickets as open_tickets_count' => fn ($q) => $q->whereNull('trashed_at')->whereHas('status', fn ($s) => $s->whereNotIn('category', ['completed', 'cancelled'])),
                'tickets as completed_tickets_count' => fn ($q) => $q->whereHas('status', fn ($s) => $s->where('category', 'completed')),
            ])
            ->orderByRaw("CASE WHEN system_key = 'triage' THEN 0 ELSE 1 END")
            ->orderBy('name');

        if (!$canManage) {
            $ids = $access->sendableIds($user);
            $query->whereIn('id', $ids === [] ? [0] : $ids);
        }

        $departments = $query->get();
        $viewable = $access->viewableIds($user);
        $unseenCounts = [];
        foreach ($departments as $department) {
            $membership = $department->users->firstWhere('id', $user->id);
            $seen = $membership?->pivot?->last_seen_at;
            if (!$membership || !$membership->pivot->follow_department || !$seen
                || !in_array($department->id, $viewable, true)) {
                continue;
            }
            $unseenCounts[$department->id] = $department->tickets()
                ->activeForBox()
                ->where(fn ($q) => $q->where('created_at', '>', $seen)
                    ->orWhere('updated_at', '>', $seen))
                ->count();
        }

        return view('admin.departments.index', [
            'unseenCounts' => $unseenCounts,
            'departments' => $departments,
            'canManage' => $canManage,
            'viewableDepartmentIds' => $viewable,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:departments,name'],
            'active' => ['nullable', 'boolean'],
        ]);

        $department = Department::create([
            'name' => trim($data['name']),
            'active' => $request->boolean('active'),
        ]);

        $this->audit($request, $department, 'department.created', null, $department->only(['name', 'active']));

        return redirect()->route('admin.departments.index')->with('success', 'Departamento criado.');
    }

    public function edit(Request $request, Department $department)
    {
        $this->authorizeAccess($request);
        abort_if($department->isTriage(), 403, 'A Triagem é uma caixa padrão do sistema e não pode ser editada.');

        return view('admin.departments.edit', compact('department'));
    }

    public function update(Request $request, Department $department)
    {
        $this->authorizeAccess($request);
        abort_if($department->isTriage(), 403, 'A Triagem é uma caixa padrão do sistema e não pode ser alterada.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('departments', 'name')->ignore($department->id)],
            'active' => ['nullable', 'boolean'],
        ]);

        $old = $department->only(['name', 'active']);
        $department->update([
            'name' => trim($data['name']),
            'active' => $request->boolean('active'),
        ]);

        $this->audit($request, $department, 'department.updated', $old, $department->fresh()->only(['name', 'active']));

        return redirect()->route('admin.departments.index')->with('success', 'Departamento atualizado.');
    }

    public function addUser(Request $request, Department $department)
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'access_level' => ['required', Rule::in(['send', 'view', 'edit'])],
        ]);
        $user = User::query()->whereKey($data['user_id'])->where('active', true)->firstOrFail();

        $alreadyAssociated = DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->where('department_id', $department->id)
            ->exists();

        // Reassociar uma pessoa não deve apagar suas preferências já existentes.
        $changes = ['access_level' => $data['access_level'], 'updated_at' => now()];
        if (!$alreadyAssociated) {
            $changes += ['follow_department' => false, 'notify_email' => false,
                'notify_whatsapp' => false, 'notify_push' => false,
                'created_at' => now()];
        } elseif ($data['access_level'] === 'send') {
            $changes += ['follow_department' => false, 'notify_email' => false,
                'notify_whatsapp' => false, 'notify_push' => false];
        }
        DB::table('department_user_access')->updateOrInsert(
            ['user_id' => $user->id, 'department_id' => $department->id],
            $changes
        );

        $this->audit($request, $department, 'department.user_added', null, [
            'user_id' => $user->id,
            'access_level' => $data['access_level'],
        ]);

        if (!$alreadyAssociated) {
            try {
                $user->notify(new DepartmentMembershipNotification($department, $data['access_level']));
            } catch (Throwable $exception) {
                Log::warning('Falha ao enviar notificação de entrada em departamento.', [
                    'department_id' => $department->id,
                    'user_id' => $user->id,
                    'exception' => $exception::class,
                    'code' => $exception->getCode(),
                ]);
            }
        }

        return back()->with('success', 'Pessoa associada ao departamento.');
    }

    public function updateUser(Request $request, Department $department, User $user)
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'access_level' => ['required', Rule::in(['send', 'view', 'edit'])],
        ]);

        $current = DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->where('department_id', $department->id)
            ->first();
        abort_unless($current, 404);

        $follow = in_array($data['access_level'], ['view', 'edit'], true)
            ? (bool) $current->follow_department
            : false;

        DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->where('department_id', $department->id)
            ->update([
                'access_level' => $data['access_level'],
                'follow_department' => $follow,
                'notify_email' => $follow && (bool) $current->notify_email,
                'notify_whatsapp' => $follow && (bool) $current->notify_whatsapp,
                'notify_push' => $follow && (bool) $current->notify_push,
                'updated_at' => now(),
            ]);

        $this->audit($request, $department, 'department.user_access_updated', [
            'user_id' => $user->id,
            'access_level' => $current->access_level,
            'follow_department' => (bool) $current->follow_department,
        ], [
            'user_id' => $user->id,
            'access_level' => $data['access_level'],
            'follow_department' => $follow,
        ]);

        return back()->with('success', 'Acesso atualizado.');
    }

    public function removeUser(Request $request, Department $department, User $user)
    {
        $this->authorizeAccess($request);
        DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->where('department_id', $department->id)
            ->delete();

        $this->audit($request, $department, 'department.user_removed', ['user_id' => $user->id], []);
        return back()->with('success', 'Pessoa removida do departamento.');
    }

    public function follow(Request $request, Department $department, DepartmentAccess $access)
    {
        $user = $request->user();
        abort_unless($access->canView($user, $department), 403);

        // Compatibilidade com o botão antigo de acompanhar: e-mail + sininho.
        $channelsSubmitted = $request->hasAny(['notify_email', 'notify_whatsapp', 'notify_push']);
        $request->validate([
            'follow_department' => ['nullable', 'boolean'],
            'notify_email' => ['nullable', 'boolean'],
            'notify_whatsapp' => ['nullable', 'boolean'],
            'notify_push' => ['nullable', 'boolean'],
        ]);

        if ($channelsSubmitted) {
            $email = $request->boolean('notify_email');
            $whatsapp = $request->boolean('notify_whatsapp');
            $push = $request->boolean('notify_push');
        } else {
            $request->validate(['follow_department' => ['required', 'boolean']]);
            $email = $push = $request->boolean('follow_department');
            $whatsapp = false;
        }

        if ($whatsapp && (
            !$user->whatsapp_reply_enabled ||
            \App\Services\WhatsAppConnection::normalizeNumber($user->whatsapp) === null
        )) {
            return back()->withErrors(['notify_whatsapp' =>
                'Cadastre e ative seu WhatsApp em Meu perfil antes de receber avisos desta caixa.']);
        }

        $following = $email || $whatsapp || $push;
        $row = DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->where('department_id', $department->id)
            ->whereIn('access_level', ['view', 'edit'])
            ->first();
        abort_unless($row, 403);

        $changedToFollow = !$row->follow_department && $following;
        DB::table('department_user_access')
            ->where('id', $row->id)
            ->update([
                'follow_department' => $following,
                'notify_email' => $email,
                'notify_whatsapp' => $whatsapp,
                'notify_push' => $push,
                'last_seen_at' => $changedToFollow ? now() : $row->last_seen_at,
                'updated_at' => now(),
            ]);

        return back()->with('success', $following
            ? 'Preferências de acompanhamento da caixa salvas.'
            : 'Acompanhamento desativado para esta caixa.');
    }

    public function markSeen(Request $request, Department $department, DepartmentAccess $access)
    {
        abort_unless($access->canView($request->user(), $department), 403);
        $updated = DB::table('department_user_access')
            ->where('user_id', $request->user()->id)
            ->where('department_id', $department->id)
            ->where('follow_department', true)
            ->whereIn('access_level', ['view', 'edit'])
            ->update(['last_seen_at' => now(), 'updated_at' => now()]);
        abort_unless($updated > 0, 403);

        return back()->with('success', 'Novidades desta caixa marcadas como vistas.');
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()->hasPermission('departments.manage'), 403);
    }

    private function audit(Request $request, Department $department, string $event, ?array $old, array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'auditable_type' => Department::class,
            'auditable_id' => $department->id,
            'event' => $event,
            'old_values' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => json_encode($new, JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
