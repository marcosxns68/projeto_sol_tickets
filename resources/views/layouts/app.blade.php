<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#6d28d9"><title>@yield('title','Sutoorii Tickets')</title><link rel="manifest" href="/manifest.webmanifest"><link rel="stylesheet" href="/css/app.css">
</head>
<body>
<header class="topbar">
<a class="brand" href="{{ route('dashboard') }}"><span class="mark">S</span><span>Sutoorii <b>Tickets</b></span></a>
@auth
<nav class="main-nav">
<a href="{{ route('boxes.mine') }}" class="nav-link">Minha Caixa</a>
@if(auth()->user()->department_id && auth()->user()->hasPermission('tickets.view_department'))<a href="{{ route('boxes.department',auth()->user()->department_id) }}" class="nav-link desktop">Meu Departamento</a>@endif
@if(auth()->user()->hasPermission('users.manage'))<a href="{{ route('admin.users.index') }}" class="nav-link desktop">Usuários</a>@endif
@if(auth()->user()->hasPermission('tickets.create'))<a href="{{ route('tickets.create') }}" class="button create-button">+ Novo</a>@endif
<form action="{{ route('logout') }}" method="post">@csrf<button class="link">Sair</button></form>
</nav>
@endauth
</header>
<main class="container">
@if(session('success'))<div class="notice">{{ session('success') }}</div>@endif
@yield('content')
</main>
<footer class="site-footer">Desenvolvido por Sutoorii Labs</footer>
<script>if('serviceWorker' in navigator)navigator.serviceWorker.register('/service-worker.js');</script>
</body>
</html>
