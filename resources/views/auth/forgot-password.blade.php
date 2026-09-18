@extends('layouts.guest')
@section('title','Passwort vergessen')
@section('content')
<h1>Passwort zurücksetzen</h1>
<p class="muted">Gib deine E-Mail-Adresse ein. Du erhältst anschließend einen zeitlich begrenzten Link zum Setzen eines neuen Passworts.</p>
<form method="post" action="{{ route('password.email') }}" class="stack-form">@csrf
<label>E-Mail-Adresse<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus></label>
<button class="btn primary wide">Reset-Link senden</button>
</form>
<p style="margin-top:16px"><a href="{{ route('login') }}">← Zur Anmeldung</a></p>
@endsection
