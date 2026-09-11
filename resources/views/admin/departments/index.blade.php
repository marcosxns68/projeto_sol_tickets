@extends('layouts.app')
@section('title','Departamentos — Sutoorii Tickets')
@section('content')
<div class="page-head"><div><p class="eyebrow">ADMINISTRAÇÃO</p><h1>Departamentos</h1><p class="muted">Crie e organize as caixas de trabalho da equipe.</p></div><div class="actions"><a href="{{ route('admin.users.index') }}" class="secondary-button">Usuários</a>@if(auth()->user()->hasPermission('roles.manage'))<a href="{{ route('admin.roles.index') }}" class="secondary-button">Cargos</a>@endif</div></div>
@if($errors->any())<div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<article class="panel"><div class="section-title"><div><p class="eyebrow">NOVO</p><h2>Novo departamento</h2></div></div><form action="{{ route('admin.departments.store') }}" method="post" class="grid form-grid">@csrf<label>Nome<input name="name" value="{{ old('name') }}" placeholder="Ex.: Desenvolvimento" required></label><label class="toggle-card"><input type="checkbox" name="active" value="1" @checked(old('active',true))><span><b>Departamento ativo</b><small>Pode receber usuários e tickets.</small></span></label><div><button class="button" type="submit">Criar departamento</button></div></form></article>
<article class="panel table-panel mobile-card-panel">
<div class="responsive-table desktop-admin-table"><table class="admin-table"><thead><tr><th>Departamento</th><th>Status</th><th></th></tr></thead><tbody>@forelse($departments as $department)<tr><td><b>{{ $department->name }}</b></td><td><span class="pill {{ $department->active?'ok':'off' }}">{{ $department->active?'Ativo':'Inativo' }}</span></td><td class="right"><a class="secondary-button compact" href="{{ route('admin.departments.edit',$department) }}">Editar</a></td></tr>@empty<tr><td colspan="3" class="empty">Nenhum departamento cadastrado.</td></tr>@endforelse</tbody></table></div>
<div class="mobile-admin-list">
@forelse($departments as $department)
<article class="mobile-admin-card">
    <div class="mobile-admin-card-head"><div><h3>{{ $department->name }}</h3><span class="mobile-admin-sub">Caixa de trabalho</span></div><span class="pill {{ $department->active?'ok':'off' }}">{{ $department->active?'Ativo':'Inativo' }}</span></div>
    <div class="mobile-admin-actions"><a class="secondary-button compact" href="{{ route('admin.departments.edit',$department) }}">Editar departamento</a></div>
</article>
@empty
<div class="mobile-admin-empty">Nenhum departamento cadastrado.</div>
@endforelse
</div>
</article>
@endsection
