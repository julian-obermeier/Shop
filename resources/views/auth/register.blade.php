@extends('layouts.guest')
@section('title','Registrieren')
@section('content')
<h1>Konto erstellen</h1><p class="muted">Die Plattform ist ausschließlich für volljährige Personen vorgesehen.</p>
<form method="post" action="{{ route('register.submit') }}" class="stack-form">@csrf
<div class="two-col"><label>Vorname<input name="first_name" value="{{ old('first_name') }}" required></label><label>Nachname<input name="last_name" value="{{ old('last_name') }}" required></label></div>
<label>Geburtsdatum<input type="date" name="birth_date" value="{{ old('birth_date') }}" required></label>
<label>E-Mail-Adresse<input type="email" name="email" value="{{ old('email') }}" required></label>
<div class="two-col"><label>Passwort<input type="password" name="password" required></label><label>Passwort wiederholen<input type="password" name="password_confirmation" required></label></div>
<label class="check"><input type="checkbox" name="adult" value="1" required><span>Ich bestätige, dass ich mindestens 18 Jahre alt bin.</span></label>
<label class="check"><input type="checkbox" name="terms" value="1" required><span>Ich akzeptiere die Plattformregeln und Datenschutzbestimmungen.</span></label>
<button class="btn primary wide">Registrieren</button>
</form><p class="guest-switch">Bereits registriert? <a href="{{ route('login') }}">Anmelden</a></p>
@endsection
