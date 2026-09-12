@extends('layouts.app')
@section('title','Etiquetas — Sutoorii Tickets')
@section('content')
<div class="page-head">
    <div><p class="eyebrow">ADMINISTRAÇÃO</p><h1>Etiquetas</h1><p class="muted">Padronize a classificação dos tickets com nomes e cores consistentes.</p></div>
</div>
@if($errors->any())<div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<article class="panel">
    <div class="section-title"><div><p class="eyebrow">NOVA</p><h2>Nova etiqueta</h2></div></div>
    <form action="{{ route('admin.labels.store') }}" method="post" class="grid form-grid">@csrf
        <label>Nome<input name="name" value="{{ old('name') }}" placeholder="Ex.: Financeiro" required></label>
        <label>Cor<input type="color" name="color" value="{{ old('color','#6D28D9') }}" required></label>
        <div><button class="button" type="submit">Criar etiqueta</button></div>
    </form>
</article>
<article class="panel table-panel mobile-card-panel">
    <div class="responsive-table desktop-admin-table">
        <table class="admin-table"><thead><tr><th>Etiqueta</th><th>Uso</th><th>Ações</th></tr></thead><tbody>
        @forelse($labels as $label)
            <tr>
                <td><span class="label-chip" style="--label-color:{{ $label->color }}">{{ $label->name }}</span></td>
                <td>{{ $label->tickets_count }} {{ $label->tickets_count === 1 ? 'ticket' : 'tickets' }}</td>
                <td>
                    <form action="{{ route('admin.labels.update',$label) }}" method="post" class="label-admin-form">@csrf @method('PATCH')
                        <input name="name" value="{{ $label->name }}" required aria-label="Nome da etiqueta {{ $label->name }}">
                        <input type="color" name="color" value="{{ $label->color }}" required aria-label="Cor da etiqueta {{ $label->name }}">
                        <button class="secondary-button compact" type="submit">Salvar</button>
                    </form>
                    @unless($label->system)
                    <form action="{{ route('admin.labels.destroy',$label) }}" method="post" onsubmit="return confirm('Excluir esta etiqueta? Os tickets continuarão existindo, apenas perderão esta classificação.')">@csrf @method('DELETE')<button class="danger-button compact" type="submit">Excluir</button></form>
                    @endunless
                </td>
            </tr>
        @empty
            <tr><td colspan="3" class="empty">Nenhuma etiqueta cadastrada.</td></tr>
        @endforelse
        </tbody></table>
    </div>
    <div class="mobile-admin-list">
        @forelse($labels as $label)
            <article class="mobile-admin-card">
                <div class="mobile-admin-card-head"><div><span class="label-chip" style="--label-color:{{ $label->color }}">{{ $label->name }}</span><span class="mobile-admin-sub">{{ $label->tickets_count }} {{ $label->tickets_count === 1 ? 'ticket' : 'tickets' }}</span></div></div>
                <form action="{{ route('admin.labels.update',$label) }}" method="post" class="label-admin-form mobile-label-form">@csrf @method('PATCH')
                    <input name="name" value="{{ $label->name }}" required>
                    <input type="color" name="color" value="{{ $label->color }}" required>
                    <button class="secondary-button compact" type="submit">Salvar</button>
                </form>
                @unless($label->system)<form action="{{ route('admin.labels.destroy',$label) }}" method="post">@csrf @method('DELETE')<button class="danger-button compact" type="submit">Excluir</button></form>@endunless
            </article>
        @empty<div class="mobile-admin-empty">Nenhuma etiqueta cadastrada.</div>@endforelse
    </div>
</article>
@endsection
