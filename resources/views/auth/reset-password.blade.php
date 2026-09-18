@extends('layouts.guest')
@section('title','Neues Passwort')
@section('content')
<h1>Neues Passwort festlegen</h1>
<form method="post" action="{{ route('password.update') }}" class="stack-form">@csrf
<input type="hidden" name="token" value="{{ $token }}">
<label>E-Mail-Adresse<input type="email" name="email" value="{{ old('email',$email) }}" autocomplete="email" required></label>
<label>Neues Passwort<input type="password" name="password" minlength="12" autocomplete="new-password" required></label>
<label>Passwort wiederholen<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label>
<button class="btn primary wide">Passwort speichern</button>
</form>
@endsection
