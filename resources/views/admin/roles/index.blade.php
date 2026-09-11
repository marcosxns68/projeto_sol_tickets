@extends('layouts.app')
@section('title','Cargos — Sutoorii Tickets')
@section('content')
<div class="page-head"><div><p class="eyebrow">ADMINISTRAÇÃO</p><h1>Cargos</h1><p class="muted">Defina os conjuntos padrão de permissões usados pelos usuários.</p></div><div class="actions"><a href="{{ route('admin.users.index') }}" class="secondary-button">Usuários</a>@if(auth()->user()->hasPermission('departments.manage'))<a href="{{ route('admin.departments.index') }}" class="secondary-button">Departamentos</a>@endif</div></div>
@if($errors->any())<div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<article class="panel"><div class="section-title"><div><p class="eyebrow">NOVO</p><h2>Novo cargo</h2><p class="muted">Após criar, você poderá escolher as permissões padrão do cargo.</p></div></div><form action="{{ route('admin.roles.store') }}" method="post" class="grid form-grid">@csrf<label>Nome<input name="name" value="{{ old('name') }}" placeholder="Ex.: Atendimento" required></label><label class="toggle-card"><input type="checkbox" name="active" value="1" @checked(old('active',true))><span><b>Cargo ativo</b><small>Disponível para atribuição aos usuários.</small></span></label><div><button class="button" type="submit">Criar cargo</button></div></form></article>
<article class="panel table-panel mobile-card-panel">
<div class="responsive-table desktop-admin-table"><table class="admin-table"><thead><tr><th>Cargo</th><th>Permissões</th><th>Usuários</th><th>Status</th><th></th></tr></thead><tbody>@forelse($roles as $role)<tr><td><b>{{ $role->name }}</b>@if($role->protected)<small>Cargo do sistema</small>@endif</td><td>{{ $role->permissions_count }}</td><td>{{ $role->users_count }}</td><td><span class="pill {{ $role->active?'ok':'off' }}">{{ $role->active?'Ativo':'Inativo' }}</span></td><td class="right"><a class="secondary-button compact" href="{{ route('admin.roles.edit',$role) }}">Editar</a></td></tr>@empty<tr><td colspan="5" class="empty">Nenhum cargo cadastrado.</td></tr>@endforelse</tbody></table></div>
<div class="mobile-admin-list">
@forelse($roles as $role)
<article class="mobile-admin-card">
    <div class="mobile-admin-card-head"><div><h3>{{ $role->name }}</h3>@if($role->protected)<span class="mobile-admin-sub">Cargo do sistema</span>@else<span class="mobile-admin-sub">Cargo configurável</span>@endif</div><span class="pill {{ $role->active?'ok':'off' }}">{{ $role->active?'Ativo':'Inativo' }}</span></div>
    <div class="mobile-admin-meta"><div><small>Permissões</small><strong>{{ $role->permissions_count }}</strong></div><div><small>Usuários</small><strong>{{ $role->users_count }}</strong></div></div>
    <div class="mobile-admin-actions"><a class="secondary-button compact" href="{{ route('admin.roles.edit',$role) }}">Editar cargo</a></div>
</article>
@empty
<div class="mobile-admin-empty">Nenhum cargo cadastrado.</div>
@endforelse
</div>
</article>
@endsection
