@extends('layouts.guest')
@section('title','Registrieren')
@section('content')
<h1>Konto erstellen</h1>
<p class="muted">Die Plattform ist ausschließlich für volljährige Personen vorgesehen.</p>

<form method="post" action="{{ route('register.submit') }}" class="stack-form">@csrf
<div class="two-col">
<label>Vorname<input name="first_name" value="{{ old('first_name') }}" required></label>
<label>Nachname<input name="last_name" value="{{ old('last_name') }}" required></label>
</div>
<label>Geburtsdatum<input type="date" name="birth_date" value="{{ old('birth_date') }}" required></label>
<label>E-Mail-Adresse<input type="email" name="email" value="{{ old('email') }}" required></label>
<div class="two-col">
<label>Passwort<input type="password" name="password" minlength="12" required></label>
<label>Passwort wiederholen<input type="password" name="password_confirmation" minlength="12" required></label>
</div>

@if($documents->count())
<div class="panel">
<h2>Vertrags- und Datenschutzdokumente</h2>
<p>Die folgenden aktuell veröffentlichten Dokumentversionen gelten für diese Registrierung. Deine Zustimmung wird genau für diese Versionen mit Zeitpunkt dokumentiert.</p>
@foreach($documents as $document)
@php($version=$document->versions->first())
<details style="margin:10px 0">
<summary><strong>{{ $document->title }}</strong> · Version {{ $version->version }}</summary>
<div class="prose" style="white-space:pre-wrap;margin-top:10px">{{ $version->content }}</div>
</details>
@endforeach
</div>
@endif

<label class="check"><input type="checkbox" name="adult" value="1" required><span>Ich bestätige, dass ich mindestens 18 Jahre alt bin.</span></label>
<label class="check"><input type="checkbox" name="terms" value="1" required><span>Ich akzeptiere die oben aufgeführten aktuell veröffentlichten Dokumentversionen sowie die Plattformregeln und Datenschutzbestimmungen. Eine spätere neue Dokumentversion erfordert keine erneute Zustimmung für die Nutzung bestehender Funktionen.</span></label>
<button class="btn primary wide">Registrieren</button>
</form>

<p class="guest-switch">Bereits registriert? <a href="{{ route('login') }}">Anmelden</a></p>
@endsection
