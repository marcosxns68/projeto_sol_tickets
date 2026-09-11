@extends('layouts.app')
@section('title','Integrações — Sutoorii Tickets')
@section('content')
<div class="page-head">
    <div><p class="eyebrow">ADMINISTRAÇÃO</p><h1>Integrações</h1><p class="muted">Cada integração possui uma chave própria e representa uma origem isolada de tickets.</p></div>
</div>

@if(session('generated_api_token') || session('generated_webhook_secret'))
<article class="panel">
    <div class="section-title"><div><p class="eyebrow">CREDENCIAIS GERADAS</p><h2>{{ session('generated_integration_name') }}</h2></div></div>
    <p class="muted">Copie agora. Por segurança, estes valores não serão exibidos novamente.</p>
    @if(session('generated_api_token'))<label>Chave da API<input readonly value="{{ session('generated_api_token') }}" onclick="this.select()"></label>@endif
    @if(session('generated_webhook_secret'))<label>Segredo do webhook<input readonly value="{{ session('generated_webhook_secret') }}" onclick="this.select()"></label>@endif
</article>
@endif

@if($errors->any())<div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<article class="panel">
    <div class="section-title"><div><p class="eyebrow">NOVA ORIGEM</p><h2>Criar integração</h2></div></div>
    <form action="{{ route('admin.integrations.store') }}" method="post" class="grid form-grid">@csrf
        <label>Nome<input name="name" value="{{ old('name') }}" placeholder="Ex.: Estúdio França" required></label>
        <label>Departamento padrão<select name="department_id"><option value="">Nenhum</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(old('department_id')==$department->id)>{{ $department->name }}</option>@endforeach</select></label>
        <label>URL base do sistema<input name="base_url" value="{{ old('base_url') }}" placeholder="https://sistema.exemplo.com"></label>
        <label>Webhook de retorno<input name="webhook_url" value="{{ old('webhook_url') }}" placeholder="https://sistema.exemplo.com/webhooks/sutoorii"></label>
        <label class="toggle-card"><input type="checkbox" name="active" value="1" @checked(old('active',true))><span><b>Integração ativa</b><small>A chave pode abrir e consultar tickets.</small></span></label>
        <div><button class="button" type="submit">Criar integração e gerar chave</button></div>
    </form>
</article>

<article class="panel table-panel mobile-card-panel">
<div class="responsive-table desktop-admin-table"><table class="admin-table"><thead><tr><th>Integração</th><th>Departamento</th><th>API</th><th>Webhook</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($integrations as $integration)
<tr>
<td><b>{{ $integration->name }}</b><br><small class="muted">{{ $integration->base_url ?: 'Sem URL base' }}</small></td>
<td>{{ $integration->department?->name ?? '—' }}</td>
<td>{{ $integration->last_api_activity_at?->format('d/m/Y H:i') ?? 'Sem atividade' }}</td>
<td>{{ $integration->last_webhook_status ?: ($integration->webhook_url ? 'Configurado' : 'Não configurado') }}</td>
<td><span class="pill {{ $integration->active?'ok':'off' }}">{{ $integration->active?'Ativa':'Inativa' }}</span></td>
<td class="right"><a class="secondary-button compact" href="{{ route('admin.integrations.edit',$integration) }}">Gerenciar</a></td>
</tr>
@empty<tr><td colspan="6" class="empty">Nenhuma integração cadastrada.</td></tr>@endforelse
</tbody></table></div>

<div class="mobile-admin-list">
@forelse($integrations as $integration)
<article class="mobile-admin-card">
<div class="mobile-admin-card-head"><div><h3>{{ $integration->name }}</h3><span class="mobile-admin-sub">{{ $integration->department?->name ?? 'Sem departamento padrão' }}</span></div><span class="pill {{ $integration->active?'ok':'off' }}">{{ $integration->active?'Ativa':'Inativa' }}</span></div>
<div class="mobile-admin-actions"><a class="secondary-button compact" href="{{ route('admin.integrations.edit',$integration) }}">Gerenciar integração</a></div>
</article>
@empty<div class="mobile-admin-empty">Nenhuma integração cadastrada.</div>@endforelse
</div>
</article>
@endsection
