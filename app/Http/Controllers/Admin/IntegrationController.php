<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\WebhookDelivery;
use App\Services\WebhookDeliveryProcessor;
use App\Services\WebhookUrlGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        return view('admin.integrations.index', [
            'integrations' => ConnectedSystem::with('department')->orderBy('name')->get(),
            'departments' => Department::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, WebhookUrlGuard $guard)
    {
        $this->authorizeAccess($request);
        $data = $this->validated($request, $guard);

        $integration = ConnectedSystem::create([
            'company_id' => null,
            'department_id' => $data['department_id'] ?? null,
            'name' => trim($data['name']),
            'base_url' => $data['base_url'] ?? null,
            'webhook_url' => $data['webhook_url'] ?? null,
            'active' => $request->boolean('active'),
        ]);

        $token = $integration->issueApiToken();
        $secret = $integration->webhook_url ? $integration->issueWebhookSecret() : null;

        $this->audit($request, $integration, 'integration.created', null, $this->safeValues($integration));

        return redirect()->route('admin.integrations.index')
            ->with('success', 'Integração criada.')
            ->with('generated_api_token', $token)
            ->with('generated_webhook_secret', $secret)
            ->with('generated_integration_name', $integration->name);
    }

    public function edit(Request $request, ConnectedSystem $integration)
    {
        $this->authorizeAccess($request);

        return view('admin.integrations.edit', [
            'integration' => $integration->load('department'),
            'departments' => Department::where('active', true)->orderBy('name')->get(),
            'deliveries' => $integration->webhookDeliveries()->latest()->limit(20)->get(),
        ]);
    }

    public function update(Request $request, ConnectedSystem $integration, WebhookUrlGuard $guard)
    {
        $this->authorizeAccess($request);
        $data = $this->validated($request, $guard);
        $old = $this->safeValues($integration);

        $integration->update([
            'department_id' => $data['department_id'] ?? null,
            'name' => trim($data['name']),
            'base_url' => $data['base_url'] ?? null,
            'webhook_url' => $data['webhook_url'] ?? null,
            'active' => $request->boolean('active'),
        ]);

        $this->audit($request, $integration, 'integration.updated', $old, $this->safeValues($integration->fresh()));
        return redirect()->route('admin.integrations.edit', $integration)->with('success', 'Integração atualizada.');
    }

    public function rotateToken(Request $request, ConnectedSystem $integration)
    {
        $this->authorizeAccess($request);
        $token = $integration->issueApiToken();
        $this->audit($request, $integration, 'integration.api_token_rotated', null, ['token_rotated' => true]);

        return back()->with('success', 'Nova chave gerada. A chave anterior deixou de funcionar.')
            ->with('generated_api_token', $token)
            ->with('generated_integration_name', $integration->name);
    }

    public function rotateWebhookSecret(Request $request, ConnectedSystem $integration)
    {
        $this->authorizeAccess($request);
        $secret = $integration->issueWebhookSecret();
        $this->audit($request, $integration, 'integration.webhook_secret_rotated', null, ['webhook_secret_rotated' => true]);

        return back()->with('success', 'Novo segredo do webhook gerado.')
            ->with('generated_webhook_secret', $secret)
            ->with('generated_integration_name', $integration->name);
    }

    public function retryDelivery(Request $request, ConnectedSystem $integration, WebhookDelivery $delivery, WebhookDeliveryProcessor $processor)
    {
        $this->authorizeAccess($request);
        abort_unless((int) $delivery->system_id === (int) $integration->id, 404);

        $delivery->update([
            'attempts' => 0,
            'status' => 'pending',
            'last_error' => null,
            'next_attempt_at' => now(),
            'delivered_at' => null,
        ]);
        $processor->attempt($delivery->fresh());

        return back()->with('success', 'Entrega reenviada.');
    }

    private function validated(Request $request, WebhookUrlGuard $guard): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'base_url' => ['nullable', 'url', 'max:500'],
            'webhook_url' => ['nullable', 'url', 'max:500'],
            'active' => ['nullable', 'boolean'],
        ]);

        if (!empty($data['webhook_url']) && !$guard->isAllowed($data['webhook_url'])) {
            abort(422, 'URL de webhook não permitida. Use um endereço HTTPS público.');
        }

        return $data;
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()->hasPermission('integrations.manage'), 403);
    }

    private function safeValues(ConnectedSystem $integration): array
    {
        return [
            'name' => $integration->name,
            'department_id' => $integration->department_id,
            'base_url' => $integration->base_url,
            'webhook_url' => $integration->webhook_url,
            'active' => (bool) $integration->active,
            'has_api_token' => (bool) $integration->api_token_hash,
            'has_webhook_secret' => (bool) ($integration->webhook_secret_encrypted || $integration->webhook_secret),
        ];
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
