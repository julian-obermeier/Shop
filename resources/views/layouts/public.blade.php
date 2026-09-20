<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title','Wear&Earn') · Wear&Earn</title>
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
</head>
<body class="public-body">
<header class="public-header">
<div class="public-wrap public-nav">
<a class="brand public-brand" href="{{ route('public.home') }}"><span class="brand-mark">♥</span><span><strong>Wear&Earn</strong><small>Diskrete Ankaufsplattform · 18+</small></span></a>
<nav>
<a href="{{ route('public.home') }}">Start</a>
<a href="{{ route('offers.index') }}">Angebote</a>
<a href="{{ route('public.how') }}">So funktioniert es</a>
<a href="{{ route('public.faq') }}">FAQ</a>
<a href="{{ route('public.contact') }}">Kontakt</a>
<a href="{{ route('public.rules') }}">Regeln</a>
</nav>
<div class="public-actions">
@if(auth()->check())
<a class="btn secondary" href="{{ route('dashboard') }}">Dashboard</a>
@else
<a class="btn secondary" href="{{ route('login') }}">Anmelden</a>
<a class="btn primary" href="{{ route('register') }}">Registrieren</a>
@endif
</div>
</div>
</header>
<main>
@if(session('success'))<div class="public-wrap" style="padding-top:18px"><div class="flash success">{{ session('success') }}</div></div>@endif
@yield('content')
</main>
<footer class="public-footer">
<div class="public-wrap footer-grid">
<div><strong>Wear&Earn</strong><p>Diskrete Ankaufsplattform für volljährige Verkäuferinnen.</p></div>
<div><a href="{{ route('public.how') }}">So funktioniert es</a><a href="{{ route('public.faq') }}">FAQ</a><a href="{{ route('public.rules') }}">Regeln</a></div>
<div><a href="{{ route('public.contact') }}">Kontakt</a><a href="{{ route('privacy.index') }}">Datenschutz</a><span>Nur für Volljährige ab 18 Jahren.</span></div>
</div>
</footer>
</body>
</html>
