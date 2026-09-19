<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PriorityDeadlines;
use Illuminate\Http\Request;

class PrioritySettingsController extends Controller
{
    public function edit(Request $request, PriorityDeadlines $deadlines)
    {
        $this->authorizeAdmin($request);

        return view('admin.settings.priorities', ['days' => $deadlines->all()]);
    }

    public function update(Request $request, PriorityDeadlines $deadlines)
    {
        $this->authorizeAdmin($request);

        $days = $request->validate([
            'low' => ['required', 'integer', 'between:0,365'],
            'normal' => ['required', 'integer', 'between:0,365'],
            'high' => ['required', 'integer', 'between:0,365'],
            'urgent' => ['required', 'integer', 'between:0,365'],
        ]);

        $deadlines->save($days);

        return redirect()->route('admin.settings.priorities.edit')
            ->with('success', 'Prazos padrão atualizados. Os tickets existentes mantêm seus prazos atuais.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role?->name === 'Super Admin', 403);
    }
}
