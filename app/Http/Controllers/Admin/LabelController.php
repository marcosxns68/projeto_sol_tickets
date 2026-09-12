<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Label;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LabelController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        return view('admin.labels.index', [
            'labels' => Label::query()->withCount('tickets')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess($request);
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:labels,name'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $label = Label::create([
            'name' => $data['name'],
            'color' => strtoupper($data['color']),
            'system' => false,
        ]);

        $this->audit($request, $label, 'label.created', null, $label->only(['name', 'color']));

        return redirect()->route('admin.labels.index')->with('success', 'Etiqueta criada.');
    }

    public function update(Request $request, Label $label)
    {
        $this->authorizeAccess($request);
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('labels', 'name')->ignore($label->id)],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $old = $label->only(['name', 'color']);
        $label->update([
            'name' => $data['name'],
            'color' => strtoupper($data['color']),
        ]);

        $this->audit($request, $label, 'label.updated', $old, $label->fresh()->only(['name', 'color']));

        return redirect()->route('admin.labels.index')->with('success', 'Etiqueta atualizada.');
    }

    public function destroy(Request $request, Label $label)
    {
        $this->authorizeAccess($request);
        abort_if($label->system, 422, 'Etiquetas do sistema não podem ser excluídas.');

        $old = $label->only(['name', 'color']);
        $label->delete();
        $this->audit($request, $label, 'label.deleted', $old, []);

        return redirect()->route('admin.labels.index')->with('success', 'Etiqueta excluída.');
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()->hasPermission('labels.manage'), 403);
    }

    private function audit(Request $request, Label $label, string $event, ?array $old, array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'auditable_type' => Label::class,
            'auditable_id' => $label->id,
            'event' => $event,
            'old_values' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => json_encode($new, JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
