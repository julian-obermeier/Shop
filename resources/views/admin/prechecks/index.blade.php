@extends('layouts.app')
@section('title','Vorabkontrollen')
@section('content')
<div class="page-head"><span class="eyebrow">Administration</span><h1>Vorabkontrollen</h1><p>Jede Perspektive wird einzeln geprüft. Sobald alle Pflichtaufnahmen akzeptiert sind, startet der Auftrag automatisch.</p></div>
@forelse($prechecks as $precheck)
<section class="panel" style="margin-bottom:18px">
<div class="page-head split"><div><span class="eyebrow">Auftrag #{{ $precheck->order->order_number }} · Durchlauf {{ $precheck->order->series_number }}</span><h2>{{ $precheck->order->user->first_name }} {{ $precheck->order->user->last_name }}</h2><p>{{ $precheck->item_description }}</p></div><a class="btn secondary" href="{{ route('admin.orders.show',$precheck->order) }}">Auftrag öffnen</a></div>
<div class="proof-review-grid">
@foreach($evidenceByPrecheck[$precheck->id]??[] as $evidence)
<article class="proof-review-card">
<div class="proof-preview">▧</div>
<h3>{{ $evidence->label }}</h3>
<p><span class="status {{ $evidence->status }}">{{ strtoupper($evidence->status) }}</span></p>
<p><a href="{{ route('admin.prechecks.evidence',$evidence->id) }}">Originaldatei öffnen</a></p>
@if($evidence->status==='submitted')
<form method="post" action="{{ route('admin.prechecks.evidence.review',[$precheck,$evidence->id]) }}" class="stack-form">@csrf
<label>Entscheidung<select name="status"><option value="accepted">Akzeptieren</option><option value="resubmit">Neu anfordern</option></select></label>
<label>Grund bei Nachforderung<select name="rejection_kind"><option value="">–</option><option value="blurred">Unscharf</option><option value="dark">Zu dunkel</option><option value="framing">Falscher Bildausschnitt</option><option value="incomplete">Artikel/Motiv nicht vollständig sichtbar</option><option value="wrong_subject">Falsches Motiv</option><option value="timing">Falsches Timing</option><option value="other">Sonstiges</option></select></label>
<label>Hinweis<textarea name="admin_comment" rows="2"></textarea></label>
<label>Nachforderungsfrist<input type="datetime-local" name="resubmit_due_at"></label>
<button class="btn primary">Entscheidung speichern</button>
</form>
@elseif($evidence->admin_comment)
<div class="notice">{{ $evidence->admin_comment }}</div>
@endif
</article>
@endforeach
</div>
<form method="post" action="{{ route('admin.prechecks.reject',$precheck) }}" class="stack-form" style="margin-top:18px">@csrf
<label>Gesamten Auftrag endgültig ablehnen<textarea name="reason" required rows="2" placeholder="Begründung"></textarea></label>
<button class="btn secondary">Auftrag endgültig ablehnen</button>
</form>
</section>
@empty
<div class="empty">Keine offenen Vorabkontrollen.</div>
@endforelse
{{ $prechecks->links() }}
@endsection
