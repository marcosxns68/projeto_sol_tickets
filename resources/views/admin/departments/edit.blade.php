@extends('layouts.app')
@section('title','Editar departamento — Sutoorii Tickets')
@section('content')
<div class="page-head"><div><p class="eyebrow">ADMINISTRAÇÃO</p><h1>{{ $department->name }}</h1><p class="muted">Edite o nome e a disponibilidade deste departamento.</p></div><a href="{{ route('admin.departments.index') }}" class="secondary-button">← Departamentos</a></div>
@if($errors->any())<div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form action="{{ route('admin.departments.update',$department) }}" method="post" class="admin-editor">@csrf @method('PATCH')<article class="panel"><div class="grid form-grid"><label>Nome<input name="name" value="{{ old('name',$department->name) }}" required></label><label class="toggle-card"><input type="checkbox" name="active" value="1" @checked(old('active',$department->active))><span><b>Departamento ativo</b><small>Desmarque para impedir novos usos deste departamento.</small></span></label></div></article><div class="sticky-actions"><a href="{{ route('admin.departments.index') }}" class="secondary-button">Cancelar</a><button class="button" type="submit">Salvar departamento</button></div></form>
@endsection
