<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ConnectedSystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    private const TECHNICAL_COMPANY = 'Sutoorii Integrações — interno';

    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        return view('admin.integrations.index', [
            'integrations' => ConnectedSystem::query()->orderBy('name')->orderBy('id')->get(),
            'generatedToken' => session('generated_api_token'),
            'generatedFor' => session('generated_api_token_for'),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'webhook_url' => ['nullable', 'url', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);

        $company = Company::firstOrCreate(
            ['name' => self::TECHNICAL_COMPANY],
            ['document' => null, 'active' => false]
        );

        $integration = ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => trim($data['name']),
            'base_url' => $data['base_url'] ?? null,
            'webhook_url' => $data['webhook_url'] ?? null,
            'active' => $request->boolean('active'),
        ]);

        $token = $integration->issueApiToken();

        $this->audit($request, $integration, 'integration.created', null, [
            'name' => $integration->name,
            'base_url' => $integration->base_url,
            'webhook_url' => $integration->webhook_url,
            'active' => $integration->active,
        ]);

        return redirect()->route('admin.integrations.index')
            ->with('success', 'Integração criada. Copie a chave agora: ela não será exibida novamente.')
            ->with('generated_api_token', $token)
            ->with('generated_api_token_for', $integration->id);
    }

    public function update(Request $request, ConnectedSystem $integration)
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'webhook_url' => ['nullable', 'url', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);

        $old = $integration->only(['name', 'base_url', 'webhook_url', 'active']);

        $integration->update([
            'name' => trim($data['name']),
            'base_url' => $data['base_url'] ?? null,
            'webhook_url' => $data['webhook_url'] ?? null,
            'active' => $request->boolean('active'),
        ]);

        $this->audit($request, $integration, 'integration.updated', $old, $integration->fresh()->only([
            'name', 'base_url', 'webhook_url', 'active',
        ]));

        return redirect()->route('admin.integrations.index')->with('success', 'Integração atualizada.');
    }

    public function rotateKey(Request $request, ConnectedSystem $integration)
    {
        $this->authorizeAccess($request);

        $token = $integration->issueApiToken();

        $this->audit($request, $integration, 'integration.key_rotated', null, [
            'integration_id' => $integration->id,
        ]);

        return redirect()->route('admin.integrations.index')
            ->with('success', 'Nova chave gerada. A chave anterior deixou de ser válida.')
            ->with('generated_api_token', $token)
            ->with('generated_api_token_for', $integration->id);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()->hasPermission('integrations.manage'), 403);
    }

    private function audit(Request $request, ConnectedSystem $integration, string $event, ?array $old, array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'auditable_type' => ConnectedSystem::class,
            'auditable_id' => $integration->id,
            'event' => $event,
            'old_values' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => json_encode($new, JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
