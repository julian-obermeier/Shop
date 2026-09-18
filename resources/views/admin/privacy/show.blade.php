@extends('layouts.app')
@section('title','Datenschutzantrag #'.$privacyRequest->id)
@section('content')
<div class="page-head split"><div><span class="eyebrow">Datenschutzantrag #{{ $privacyRequest->id }}</span><h1>{{ $privacyRequest->user->first_name }} {{ $privacyRequest->user->last_name }}</h1><p>{{ $privacyRequest->user->email }}</p></div><span class="status {{ $privacyRequest->status }}">{{ strtoupper($privacyRequest->status) }}</span></div>

<div class="admin-order-grid">
<section>
<div class="panel"><h2>Antrag</h2><dl class="meta-list"><div><dt>Typ</dt><dd>{{ strtoupper($privacyRequest->type) }}</dd></div><div><dt>Eingang</dt><dd>{{ $privacyRequest->created_at->format('d.m.Y H:i') }}</dd></div><div><dt>Bearbeitet</dt><dd>{{ $privacyRequest->reviewed_at?->format('d.m.Y H:i') ?: '–' }}</dd></div></dl><p>{{ $privacyRequest->reason ?: 'Keine Begründung angegeben.' }}</p></div>

<div class="panel"><h2>Technische Sperrgründe</h2>
@if($blockers)<div class="flash error"><ul>@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
@else<div class="flash success">Keine technischen Sperrgründe vorhanden.</div>@endif
<p class="muted">Eine fehlende technische Sperre ersetzt keine rechtliche Prüfung. Abrechnungsrelevante Datensätze werden bei Anonymisierung nicht gelöscht, sondern bleiben pseudonymisiert bestehen.</p>
</div>
</section>

<aside>
@if(!in_array($privacyRequest->status,['completed','cancelled'],true))
<div class="panel"><h2>Prüfung</h2>
<form method="post" action="{{ route('admin.privacy.review',$privacyRequest) }}" class="stack-form">@csrf
<label>Status<select name="status"><option value="review">In Prüfung</option><option value="approved">Freigeben</option><option value="rejected">Ablehnen</option></select></label>
<label>Admin-Notiz<textarea name="admin_note" rows="5">{{ $privacyRequest->admin_note }}</textarea></label>
<button class="btn secondary wide">Speichern</button>
</form>
</div>
@endif

@if($privacyRequest->status==='approved')
<div class="panel danger-zone"><h2>Konto anonymisieren</h2>
<p>Dieser Schritt entfernt bzw. anonymisiert Kontakt-/Profildaten, private Dateien und personenbezogene Freitexte soweit technisch vorgesehen. Auftrags-, Wallet- und abrechnungsrelevante Datensatzstrukturen bleiben erhalten.</p>
<form method="post" action="{{ route('admin.privacy.anonymize',$privacyRequest) }}" class="stack-form">@csrf
<label class="check"><input type="checkbox" name="confirm" value="1" required><span>Ich habe die rechtliche und operative Prüfung abgeschlossen und bestätige die Anonymisierung.</span></label>
<button class="btn primary wide" @disabled($blockers!==[])>Jetzt anonymisieren</button>
</form>
</div>
@endif
</aside>
</div>
@endsection
