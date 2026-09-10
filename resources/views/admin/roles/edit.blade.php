@extends('layouts.app')
@section('title','Editar cargo — Sutoorii Tickets')
@section('content')
<div class="page-head"><div><p class="eyebrow">ADMINISTRAÇÃO</p><h1>{{ $role->name }}</h1><p class="muted">Configure os dados e as permissões padrão deste cargo.</p></div><a href="{{ route('admin.roles.index') }}" class="secondary-button">← Cargos</a></div>
@if($errors->any())<div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form action="{{ route('admin.roles.update',$role) }}" method="post" class="admin-editor">@csrf @method('PATCH')
<article class="panel"><div class="section-title"><div><p class="eyebrow">CARGO</p><h2>Dados gerais</h2></div></div><div class="grid form-grid"><label>Nome<input name="name" value="{{ old('name',$role->name) }}" required></label><label class="toggle-card"><input type="checkbox" name="active" value="1" @checked(old('active',$role->active))><span><b>Cargo ativo</b><small>Disponível para atribuição aos usuários.</small></span></label></div></article>
<article class="panel"><div class="section-title"><div><p class="eyebrow">ACESSO</p><h2>Permissões padrão</h2><p class="muted">Usuários deste cargo herdam estas permissões, salvo quando houver uma permissão individual concedida ou negada.</p></div></div><div class="permission-groups">@foreach($permissions as $group=>$items)<section class="permission-group"><h3>{{ ucfirst($group ?: 'Geral') }}</h3>@foreach($items as $permission)@php($checked=in_array($permission->id,(array)old('permissions',$selectedPermissions),true))<label class="permission-row"><div><b>{{ $permission->name }}</b><small>{{ $permission->key }}</small></div><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked($checked)></label>@endforeach</section>@endforeach</div></article>
<div class="sticky-actions"><a href="{{ route('admin.roles.index') }}" class="secondary-button">Cancelar</a><button class="button" type="submit">Salvar cargo</button></div>
</form>
@endsection
