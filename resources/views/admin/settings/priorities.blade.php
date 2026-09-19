@extends('layouts.app')
@section('title','Prazos por prioridade — Sutoorii Tickets')
@section('content')
<div class="page-head">
    <div>
        <p class="eyebrow">ADMINISTRAÇÃO</p>
        <h1>Prazos por prioridade</h1>
        <p class="muted">Determine o prazo automático dos novos tickets. A equipe pode alterar a data final de cada ticket quando necessário.</p>
    </div>
</div>

@if($errors->any())
<div class="alert error-box"><strong>Confira os valores informados.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<form class="admin-editor" action="{{ route('admin.settings.priorities.update') }}" method="post">
    @csrf
    @method('PATCH')
    <article class="panel">
        <div class="section-title"><div><p class="eyebrow">CONFIGURAÇÃO PADRÃO</p><h2>Quantidade de dias</h2></div></div>
        <div class="grid form-grid">
            @foreach(['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'] as $priority=>$label)
            <label>{{ $label }}
                <input type="number" name="{{ $priority }}" min="0" max="365" step="1" required value="{{ old($priority, $days[$priority]) }}">
                <small class="muted">Dias corridos após a abertura. Zero significa a mesma data.</small>
            </label>
            @endforeach
        </div>
        <p class="muted">Os prazos automáticos terminam às 23h59 na data calculada. Salvar aqui não altera os tickets existentes.</p>
    </article>
    <div class="sticky-actions"><button class="button" type="submit">Salvar prazos</button></div>
</form>
@endsection
