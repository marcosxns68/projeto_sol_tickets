@extends('layouts.app')
@section('title','Departamentos — Sutoorii Tickets')
@section('content')
<div class="page-head">
    <div><p class="eyebrow">EQUIPE</p><h1>Departamentos</h1><p class="muted">Acompanhe as caixas e organize quem pode enviar, visualizar ou editar tickets em cada departamento.</p></div>
</div>

@if($errors->any())<div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@if($canManage)
<article class="panel department-create-panel">
    <div class="section-title"><div><p class="eyebrow">NOVO</p><h2>Criar departamento</h2></div></div>
    <form action="{{ route('admin.departments.store') }}" method="post" class="grid form-grid">@csrf
        <label>Nome<input name="name" value="{{ old('name') }}" placeholder="Ex.: Desenvolvimento" required></label>
        <label class="toggle-card"><input type="checkbox" name="active" value="1" @checked(old('active',true))><span><b>Departamento ativo</b><small>Pode receber usuários e tickets.</small></span></label>
        <div><button class="button" type="submit">Criar departamento</button></div>
    </form>
</article>
@endif

<div class="department-list">
@forelse($departments as $department)
    @php
        $canViewTickets = $canManage || in_array($department->id, $viewableDepartmentIds, true);
        $myMembership = $department->users->firstWhere('id', auth()->id());
    @endphp
    <article class="panel department-card" data-department-card>
        <button type="button" class="department-summary" data-department-toggle aria-expanded="false">
            <span class="department-title"><strong>{{ $department->name }}</strong><small>{{ $department->active ? 'Ativo' : 'Inativo' }}</small></span>
            @if($canViewTickets)
                <span class="department-stat"><b>Abertos</b><strong>{{ $department->open_tickets_count }}</strong></span>
                <span class="department-stat"><b>Concluídos</b><strong>{{ $department->completed_tickets_count }}</strong></span>
            @else
                <span class="department-stat department-no-access"><b>Tickets</b><small>Sem acesso aos tickets</small></span>
            @endif
            <span class="department-stat"><b>Pessoas com acesso</b><strong>{{ $department->users->count() }}</strong></span>
            <span class="department-expand">Expandir</span>
        </button>

        <div class="department-details" data-department-details hidden>
            @if($canViewTickets)
                <div class="department-shortcuts">
                    <a class="secondary-button compact" href="{{ route('boxes.department', ['department' => $department, 'status' => 'new']) }}">Ver tickets abertos</a>
                    <a class="secondary-button compact" href="{{ route('boxes.department', ['department' => $department, 'status' => 'closed']) }}">Ver concluídos</a>
                </div>
            @endif

            @if($myMembership && in_array($myMembership->pivot->access_level, ['view','edit'], true))
                <form method="post" action="{{ route('departments.follow',$department) }}" class="follow-department-form">@csrf @method('PATCH')
                    <input type="hidden" name="follow_department" value="{{ $myMembership->pivot->follow_department ? 0 : 1 }}">
                    <div><strong>Acompanhar departamento</strong><small class="muted">Receba e-mail quando um ticket entrar ou sair desta caixa.</small></div>
                    <button class="secondary-button compact" type="submit">{{ $myMembership->pivot->follow_department ? 'Desativar' : 'Ativar' }}</button>
                </form>
            @endif

            <div class="department-members">
                <div class="member-row member-head"><span>Pessoa</span><span>Acesso</span><span>Acompanhamento</span><span></span></div>
                @forelse($department->users as $member)
                    <div class="member-row">
                        <div><strong>{{ $member->name }}</strong><small>{{ $member->email }}</small></div>
                        @if($canManage)
                            <form method="post" action="{{ route('admin.departments.users.update',[$department,$member]) }}" class="member-access-form">@csrf @method('PATCH')
                                <select name="access_level" onchange="this.form.submit()">
                                    <option value="send" @selected($member->pivot->access_level==='send')>Enviar tickets</option>
                                    <option value="view" @selected($member->pivot->access_level==='view')>Visualizar tickets</option>
                                    <option value="edit" @selected($member->pivot->access_level==='edit')>Editar tickets</option>
                                </select>
                            </form>
                        @else
                            <span>{{ ['send'=>'Enviar tickets','view'=>'Visualizar tickets','edit'=>'Editar tickets'][$member->pivot->access_level] ?? $member->pivot->access_level }}</span>
                        @endif
                        <span>{{ in_array($member->pivot->access_level,['view','edit'],true) ? ($member->pivot->follow_department ? 'Acompanhando' : 'Não acompanha') : 'Indisponível' }}</span>
                        @if($canManage)
                            <form method="post" action="{{ route('admin.departments.users.destroy',[$department,$member]) }}" onsubmit="return confirm('Remover esta pessoa do departamento?')">@csrf @method('DELETE')<button class="text-danger" type="submit">Remover</button></form>
                        @else<span></span>@endif
                    </div>
                @empty
                    <div class="empty">Nenhuma pessoa associada.</div>
                @endforelse
            </div>

            @if($canManage)
                <form method="post" action="{{ route('admin.departments.users.store',$department) }}" class="department-add-user" data-user-picker>@csrf
                    <div class="user-picker-wrap">
                        <label>Adicionar pessoa<input type="search" data-user-search autocomplete="off" placeholder="Digite nome ou e-mail"></label>
                        <input type="hidden" name="user_id" data-user-id>
                        <div class="user-picker-results" data-user-results></div>
                    </div>
                    <label>Nível de acesso
                        <select name="access_level" required>
                            <option value="send">Enviar tickets</option>
                            <option value="view">Visualizar tickets</option>
                            <option value="edit">Editar tickets</option>
                        </select>
                    </label>
                    <button class="button" type="submit">Adicionar</button>
                </form>
                <div class="department-admin-actions"><a href="{{ route('admin.departments.edit',$department) }}">Editar nome/status do departamento</a></div>
            @endif
        </div>
    </article>
@empty
    <article class="panel empty">Nenhum departamento disponível.</article>
@endforelse
</div>

<style>
.department-list{display:grid;gap:12px}.department-card{padding:0;overflow:visible}.department-summary{width:100%;border:0;background:transparent;display:grid;grid-template-columns:minmax(180px,2fr) repeat(3,minmax(100px,1fr)) auto;gap:18px;align-items:center;padding:18px 20px;text-align:left;cursor:pointer}.department-title,.department-stat{display:grid;gap:3px}.department-title small,.department-stat small{color:#756d7b}.department-stat b{font-size:.72rem;text-transform:uppercase;color:#766d7c;letter-spacing:.04em}.department-stat strong{font-size:1.15rem}.department-expand{color:#6843a8;font-weight:700}.department-details{padding:0 20px 20px;border-top:1px solid #eee8f2}.department-shortcuts{display:flex;gap:8px;flex-wrap:wrap;padding:16px 0}.follow-department-form{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:13px 0;border-bottom:1px solid #eee8f2}.follow-department-form div{display:grid;gap:3px}.department-members{margin-top:12px}.member-row{display:grid;grid-template-columns:minmax(180px,2fr) minmax(150px,1fr) minmax(130px,1fr) auto;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid #f0ebf3}.member-row>div:first-child{display:grid}.member-row small{color:#756d7b}.member-head{font-size:.75rem;text-transform:uppercase;font-weight:700;color:#766d7c}.member-access-form select{margin:0}.department-add-user{display:grid;grid-template-columns:minmax(240px,2fr) minmax(170px,1fr) auto;gap:10px;align-items:end;margin-top:18px;padding-top:16px;border-top:1px solid #eee8f2}.user-picker-wrap{position:relative}.user-picker-results{position:absolute;z-index:30;left:0;right:0;top:100%;background:white;border:1px solid #ded6e5;border-radius:10px;box-shadow:0 10px 24px #25133422;overflow:hidden}.user-picker-results:empty{display:none}.user-picker-result{display:block;width:100%;border:0;border-bottom:1px solid #eee8f2;background:#fff;padding:10px 12px;text-align:left;cursor:pointer}.user-picker-result:hover{background:#faf7ff}.user-picker-result small{display:block;color:#756d7b}.department-admin-actions{padding-top:12px}.text-danger{border:0;background:none;color:#a93434;cursor:pointer}@media(max-width:800px){.department-summary{grid-template-columns:1fr 1fr;gap:10px}.department-title{grid-column:1/-1}.department-expand{justify-self:end}.member-head{display:none}.member-row{grid-template-columns:1fr;gap:6px}.department-add-user{grid-template-columns:1fr}.follow-department-form{align-items:flex-start;flex-direction:column}}
</style>
<script>
(() => {
 document.querySelectorAll('[data-department-toggle]').forEach(button => button.addEventListener('click', () => {
   const card = button.closest('[data-department-card]'); const details = card.querySelector('[data-department-details]'); const open = details.hidden;
   details.hidden = !open; button.setAttribute('aria-expanded', open ? 'true' : 'false'); button.querySelector('.department-expand').textContent = open ? 'Recolher' : 'Expandir';
 }));
 const searchUrl = @json(route('users.search'));
 document.querySelectorAll('[data-user-picker]').forEach(picker => {
   const search = picker.querySelector('[data-user-search]'), id = picker.querySelector('[data-user-id]'), results = picker.querySelector('[data-user-results]'); let timer;
   search.addEventListener('input', () => { clearTimeout(timer); id.value=''; results.replaceChildren(); const q=search.value.trim(); if(q.length<2)return; timer=setTimeout(async()=>{ try { const r=await fetch(searchUrl+'?q='+encodeURIComponent(q),{headers:{Accept:'application/json'}}); const p=await r.json(); results.replaceChildren(); (p.data||[]).forEach(user=>{ const b=document.createElement('button'); b.type='button'; b.className='user-picker-result'; b.innerHTML='<strong></strong><small></small>'; b.querySelector('strong').textContent=user.name; b.querySelector('small').textContent=user.email; b.addEventListener('click',()=>{ id.value=user.id; search.value=user.name+' · '+user.email; results.replaceChildren(); }); results.append(b); }); } catch(e){ results.replaceChildren(); } },250); });
   picker.addEventListener('submit', e => { if(!id.value){ e.preventDefault(); search.focus(); } });
 });
})();
</script>
@endsection
