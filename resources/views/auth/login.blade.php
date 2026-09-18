@extends('layouts.guest')
@section('title','Anmelden')
@section('content')
<h1>Willkommen zurück</h1><p class="muted">Melde dich an, um deine Angebote, Nachweise und Vergütungen zu verwalten.</p>
<form method="post" action="{{ route('login.submit') }}" class="stack-form">@csrf
<label>E-Mail-Adresse<input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
<label>Passwort<input type="password" name="password" required></label>
<label class="check"><input type="checkbox" name="remember" value="1"><span>Angemeldet bleiben</span></label>
<button class="btn primary wide">Anmelden</button>
</form><p class="guest-switch">Noch kein Konto? <a href="{{ route('register') }}">Jetzt registrieren</a></p>
@endsection
