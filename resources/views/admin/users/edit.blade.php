@extends('layouts.app')
@section('title','Editar usuário — Sutoorii Tickets')
@section('content')
<div class="page-head"><div><p class="eyebrow">ADMINISTRAÇÃO</p><h1>{{ $managedUser->name }}</h1><p class="muted">{{ $managedUser->email }}</p></div><a href="{{ route('admin.users.index') }}" class="secondary-button">← Usuários</a></div>
@if($errors->any())<div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form action="{{ route('admin.users.update',$managedUser) }}" method="post" class="admin-editor">@csrf @method('PATCH')
<article class="panel"><div class="section-title"><div><p class="eyebrow">PERFIL</p><h2>Dados do usuário</h2></div></div>
<div class="grid form-grid"><label>Nome<input name="name" value="{{ old('name',$managedUser->name) }}" required></label><label>E-mail<input type="email" name="email" value="{{ old('email',$managedUser->email) }}" required></label><label>Cargo<select name="role_id"><option value="">Sem cargo</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected((string)old('role_id',$managedUser->role_id)===(string)$role->id)>{{ $role->name }}</option>@endforeach</select></label><label>Departamento<select name="department_id"><option value="">Sem departamento</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string)old('department_id',$managedUser->department_id)===(string)$department->id)>{{ $department->name }}</option>@endforeach</select></label><label class="toggle-card"><input type="checkbox" name="active" value="1" @checked(old('active',$managedUser->active))><span><b>Usuário ativo</b><small>Desmarque para impedir novos acessos.</small></span></label></div></article>

@if(auth()->user()->hasPermission('permissions.manage'))
<article class="panel"><div class="section-title"><div><p class="eyebrow">ACESSO</p><h2>Permissões individuais</h2><p class="muted">Herdada usa a configuração do cargo. Concedida ou Negada substitui o cargo somente para este usuário.</p></div></div>
<div class="permission-groups">@foreach($permissions as $group=>$items)<section class="permission-group"><h3>{{ ucfirst($group ?: 'Geral') }}</h3>@foreach($items as $permission)@php($effect=old('permissions.'.$permission->id,$overrides[$permission->id] ?? 'inherit'))<div class="permission-row"><div><b>{{ $permission->name }}</b><small>{{ $permission->key }}</small></div><select name="permissions[{{ $permission->id }}]" class="permission-select"><option value="inherit" @selected($effect==='inherit')>Herdada do cargo</option><option value="allow" @selected($effect==='allow')>Concedida</option><option value="deny" @selected($effect==='deny')>Negada</option></select></div>@endforeach</section>@endforeach</div>
</article>
@endif
<div class="sticky-actions"><a href="{{ route('admin.users.index') }}" class="secondary-button">Cancelar</a><button class="button" type="submit">Salvar usuário</button></div>
</form>
@endsection
