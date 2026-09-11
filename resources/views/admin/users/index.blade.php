@extends('layouts.app')
@section('title','Usuários — Sutoorii Tickets')
@section('content')
<div class="page-head"><div><p class="eyebrow">ADMINISTRAÇÃO</p><h1>Usuários</h1><p class="muted">Gerencie cargos, departamentos e acessos individuais.</p></div></div>
<div class="panel table-panel mobile-card-panel">
<div class="responsive-table desktop-admin-table"><table class="admin-table"><thead><tr><th>Usuário</th><th>Cargo</th><th>Departamento</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($users as $user)<tr><td><b>{{ $user->name }}</b><small>{{ $user->email }}</small></td><td>{{ $user->role?->name ?? 'Sem cargo' }}</td><td>{{ $user->department?->name ?? 'Sem departamento' }}</td><td><span class="pill {{ $user->active?'ok':'off' }}">{{ $user->active?'Ativo':'Inativo' }}</span></td><td class="right"><a class="secondary-button compact" href="{{ route('admin.users.edit',$user) }}">Editar</a></td></tr>@empty<tr><td colspan="5" class="empty">Nenhum usuário cadastrado.</td></tr>@endforelse
</tbody></table></div>
<div class="mobile-admin-list">
@forelse($users as $user)
<article class="mobile-admin-card">
    <div class="mobile-admin-card-head"><div><h3>{{ $user->name }}</h3><span class="mobile-admin-sub">{{ $user->email }}</span></div><span class="pill {{ $user->active?'ok':'off' }}">{{ $user->active?'Ativo':'Inativo' }}</span></div>
    <div class="mobile-admin-meta"><div><small>Cargo</small><strong>{{ $user->role?->name ?? 'Sem cargo' }}</strong></div><div><small>Departamento</small><strong>{{ $user->department?->name ?? 'Sem departamento' }}</strong></div></div>
    <div class="mobile-admin-actions"><a class="secondary-button compact" href="{{ route('admin.users.edit',$user) }}">Editar usuário</a></div>
</article>
@empty
<div class="mobile-admin-empty">Nenhum usuário cadastrado.</div>
@endforelse
</div>
@if($users->hasPages())<div class="pagination">{{ $users->links() }}</div>@endif
</div>
@endsection
