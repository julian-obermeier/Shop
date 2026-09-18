@extends('layouts.app')
@section('title','Nachweise prüfen')
@section('content')
<div class="page-head"><div><span class="eyebrow">Administration</span><h1>Nachweise prüfen</h1><p>Nachweise bleiben dauerhaft gespeichert. Ablehnungen unterscheiden nachreichbare technische/formale Fehler von nicht reproduzierbaren Mängeln.</p></div></div>
<div class="proof-review-grid">
@forelse($proofs as $proof)
<article class="proof-review-card">
<div class="proof-preview">▧</div>
<div>
<span class="eyebrow">Auftrag #{{ $proof->orderDay->order->order_number }} · {{ $proof->orderDay->day_number===0?'Startfoto':'Tag '.$proof->orderDay->day_number }} · {{ $proof->window_key }}</span>
<h3>{{ $proof->orderDay->order->user->first_name }} {{ $proof->orderDay->order->user->last_name }}</h3>
<p>{{ $proof->original_name }} · {{ number_format($proof->file_size/1024,0,',','.') }} KB · Code <strong>{{ $proof->proof_code }}</strong> · Versuch {{ $proof->retry_number }}</p>
@if($proof->text_value)<div class="notice"><strong>Pflichttext:</strong> {{ $proof->text_value }}</div>@endif
@if(is_array($proof->proof_data) && count($proof->proof_data))
<div class="notice">
@foreach($proof->proof_data as $entry)<div><strong>{{ $entry['label']??'Pflichtangabe' }}:</strong> {{ $entry['value']??'–' }}</div>@endforeach
</div>
@endif
<a class="btn secondary wide" href="{{ route('admin.proofs.file',$proof) }}">Datei öffnen</a>
<form method="post" action="{{ route('admin.proofs.review',$proof) }}" class="stack-form review-form">@csrf
<label>Prüfung<select name="review_status" data-proof-review-select><option value="accepted">Akzeptieren</option><option value="rejected">Ablehnen</option></select></label>
<label>Ablehnungsart<select name="rejection_kind"><option value="">– nur bei Annahme –</option><option value="technical">Technisch/formal – 2 Stunden Nachreichfrist</option><option value="non_reproducible">Nicht reproduzierbar – Tag ungültig</option></select></label>
<label>Kommentar / Ablehnungsgrund<textarea name="review_comment" rows="3"></textarea></label>
<button class="btn primary wide">Prüfung speichern</button>
</form>
</div>
</article>
@empty<div class="empty">Aktuell sind keine Nachweise offen.</div>@endforelse
</div>
{{ $proofs->links() }}
@endsection
