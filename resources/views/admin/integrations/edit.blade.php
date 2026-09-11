@extends('layouts.app')
@section('title','Gerenciar integração — Sutoorii Tickets')
@section('content')
<div class="page-head"><div><p class="eyebrow">INTEGRAÇÕES</p><h1>{{ $integration->name }}</h1><p class="muted">Chave, webhook e isolamento desta origem de tickets.</p></div><div class="actions"><a href="{{ route('admin.integrations.index') }}" class="secondary-button">Voltar</a></div></div>

@if(session('generated_api_token') || session('generated_webhook_secret'))
<article class="panel"><div class="section-title"><div><p class="eyebrow">COPIE AGORA</p><h2>Nova credencial</h2></div></div><p class="muted">Este valor não será mostrado novamente.</p>
@if(session('generated_api_token'))<label>Chave da API<input readonly value="{{ session('generated_api_token') }}" onclick="this.select()"></label>@endif
@if(session('generated_webhook_secret'))<label>Segredo do webhook<input readonly value="{{ session('generated_webhook_secret') }}" onclick="this.select()"></label>@endif
</article>
@endif

@if($errors->any())<div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<article class="panel">
<div class="section-title"><div><p class="eyebrow">CONFIGURAÇÃO</p><h2>Dados da integração</h2></div></div>
<form action="{{ route('admin.integrations.update',$integration) }}" method="post" class="grid form-grid">@csrf @method('PATCH')
<label>Nome<input name="name" value="{{ old('name',$integration->name) }}" required></label>
<label>Departamento padrão<select name="department_id"><option value="">Nenhum</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(old('department_id',$integration->department_id)==$department->id)>{{ $department->name }}</option>@endforeach</select></label>
<label>URL base<input name="base_url" value="{{ old('base_url',$integration->base_url) }}"></label>
<label>Webhook de retorno<input name="webhook_url" value="{{ old('webhook_url',$integration->webhook_url) }}" placeholder="https://..."></label>
<label class="toggle-card"><input type="checkbox" name="active" value="1" @checked(old('active',$integration->active))><span><b>Integração ativa</b><small>Desmarcar bloqueia imediatamente o acesso pela chave.</small></span></label>
<div><button class="button" type="submit">Salvar alterações</button></div>
</form>
</article>

<article class="panel">
<div class="section-title"><div><p class="eyebrow">SEGURANÇA</p><h2>Credenciais</h2></div></div>
<div class="actions">
<form action="{{ route('admin.integrations.rotate-token',$integration) }}" method="post" onsubmit="return confirm('Gerar uma nova chave? A chave atual deixará de funcionar imediatamente.')">@csrf<button class="secondary-button" type="submit">Gerar nova chave da API</button></form>
<form action="{{ route('admin.integrations.rotate-secret',$integration) }}" method="post">@csrf<button class="secondary-button" type="submit">Gerar novo segredo do webhook</button></form>
</div>
<p class="muted">A chave e o segredo são exibidos somente no momento da geração.</p>
</article>

<article class="panel table-panel">
<div class="section-title"><div><p class="eyebrow">WEBHOOKS</p><h2>Últimas entregas</h2></div></div>
<div class="responsive-table"><table class="admin-table"><thead><tr><th>Evento</th><th>Estado</th><th>Tentativas</th><th>HTTP</th><th>Data</th><th></th></tr></thead><tbody>
@forelse($deliveries as $delivery)
<tr><td>{{ $delivery->event }}</td><td>{{ $delivery->status }}</td><td>{{ $delivery->attempts }}/5</td><td>{{ $delivery->last_http_status ?? '—' }}</td><td>{{ $delivery->created_at?->format('d/m/Y H:i') }}</td><td class="right">@if($delivery->status!=='delivered')<form action="{{ route('admin.integrations.deliveries.retry',[$integration,$delivery]) }}" method="post">@csrf<button class="secondary-button compact" type="submit">Reenviar</button></form>@endif</td></tr>
@empty<tr><td colspan="6" class="empty">Nenhuma entrega registrada.</td></tr>@endforelse
</tbody></table></div>
</article>
@endsection
