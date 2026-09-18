@extends('layouts.app')
@section('title','Verifizierung')
@section('content')
<div class="page-head"><span class="eyebrow">Profil & Sicherheit</span><h1>Verifizierung</h1><p>Vor der Annahme eines Auftrags muss die Volljährigkeit und Identität geprüft sein.</p></div>
<div class="wallet-layout">
<section class="panel">
<h2>Status</h2>
@if(auth()->user()->verified_at)
<div class="flash success">Verifizierung abgeschlossen am {{ auth()->user()->verified_at->format('d.m.Y H:i') }}.</div>
@elseif($verification)
<p><span class="status {{ $verification->status }}">{{ strtoupper($verification->status) }}</span></p>
@if($verification->admin_comment)<div class="notice">{{ $verification->admin_comment }}</div>@endif
@else
<p class="muted">Noch keine Verifizierung eingereicht.</p>
@endif
</section>
<aside class="panel">
<h2>Prüfung einreichen</h2>
@if(!auth()->user()->verified_at && (!$verification || in_array($verification->status,['rejected'],true)))
<form method="post" enctype="multipart/form-data" action="{{ route('verification.store') }}" class="stack-form">@csrf
<label>Ausweisdokument – Vorderseite<input type="file" name="document_front" accept="image/jpeg,image/png,application/pdf" required></label>
<label>Rückseite, falls vorhanden<input type="file" name="document_back" accept="image/jpeg,image/png,application/pdf"></label>
<p class="notice">Die Dateien werden im privaten Speicher abgelegt und nur autorisierten Administratoren zur Prüfung bereitgestellt.</p>
<button class="btn primary wide">Zur Prüfung einreichen</button>
</form>
@else
<p class="muted">Aktuell ist keine neue Einreichung erforderlich.</p>
@endif
</aside>
</div>
@endsection
