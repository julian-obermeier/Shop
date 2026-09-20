@extends('layouts.app')
@section('title','Admin Auftrag #'.$order->order_number)
@section('content')
<div class="page-head split">
<div><span class="eyebrow">Auftrag #{{ $order->order_number }}</span><h1>{{ data_get($order->offer_snapshot,'title') }}</h1><p>{{ $order->user->first_name }} {{ $order->user->last_name }} · {{ $order->user->email }}</p><span class="status {{ $order->status }}">{{ strtoupper(str_replace('_',' ',$order->status)) }}</span></div>
<div class="headline-amount"><span>Auftragswert</span><strong>{{ number_format($order->compensation_total,2,',','.') }} €</strong>@if($order->final_compensation!==null)<small>Final: {{ number_format($order->final_compensation,2,',','.') }} €</small>@endif</div>
</div>

<div class="phase-bar">@foreach(['preparation'=>'Vorbereitung','execution'=>'Durchführung','shipping'=>'Versand','review'=>'Prüfung','payout'=>'Auszahlung','archive'=>'Archiv'] as $key=>$label)<span class="{{ $order->phase===$key?'active':'' }}">{{ $label }}</span>@endforeach</div>

<div class="admin-order-grid">
<section>
<div class="panel"><h2>Durchläufe</h2><div class="timeline">@foreach($runs as $run)<div><span>Durchlauf {{ $run->run_number }}</span><strong>{{ strtoupper($run->status) }}</strong><small>@if($run->started_at)Start {{ \Carbon\Carbon::parse($run->started_at)->format('d.m.Y H:i') }}@endif @if($run->restart_reason) · {{ $run->restart_reason }}@endif</small></div>@endforeach</div></div>

@if($order->fieldValues->count())<div class="panel"><h2>Auftragsangaben</h2><dl class="meta-list">@foreach($order->fieldValues as $field)<div><dt>{{ $field->label }}</dt><dd>{{ $field->value==='1' && data_get($field->field_snapshot,'type')==='checkbox'?'Ja':($field->value ?: '–') }}</dd></div>@endforeach</dl></div>@endif

@if(in_array($order->status,['precheck','precheck_resubmit'],true))
<div class="panel"><h2>Vorabkontrolle</h2><p>{{ $order->precheck?->item_description ?: 'Noch keine Artikeldaten eingereicht.' }}</p><a class="btn primary" href="{{ route('admin.prechecks.index') }}">Vorabnachweise prüfen</a></div>
@endif

<div class="panel"><h2>Durchführungstage</h2>
@forelse($order->days->sortByDesc('series_number')->sortBy('day_number') as $day)
<div class="admin-day"><div><strong>Durchlauf {{ $day->series_number }} · {{ $day->day_number===0?'Starttag':('Tag '.$day->day_number) }}</strong><small>{{ $day->date->format('d.m.Y') }} · {{ strtoupper($day->status) }} · {{ strtoupper($day->source_type ?? 'regular') }}</small></div><div class="proof-chips">@foreach($day->proofs as $proof)<a href="{{ route('admin.proofs.file',$proof) }}">{{ $proof->window_key }} · {{ strtoupper($proof->review_status) }}</a>@endforeach</div></div>
@empty<p class="muted">Noch keine Auftragstage vorhanden.</p>@endforelse
</div>

@if($violations->count())
<div class="panel"><h2>Offene und entschiedene Verstöße</h2>
@foreach($violations as $v)<div class="operation-row"><div><strong>{{ $v->type }}</strong><small>{{ $v->reason }} · {{ strtoupper($v->status) }}</small></div>@if(in_array($v->status,['open','reviewed'],true))<div style="display:flex;gap:6px"><form method="post" action="{{ route('admin.orders.violation',[$order,$v->id]) }}">@csrf<input type="hidden" name="decision" value="confirm"><button class="btn secondary">Bestätigen (+1 Tag)</button></form><form method="post" action="{{ route('admin.orders.violation',[$order,$v->id]) }}">@csrf<input type="hidden" name="decision" value="discard"><button class="btn secondary">Verwerfen</button></form></div>@endif</div>@endforeach
</div>
@endif

@if($extensionDays->count())
<div class="panel"><h2>Zusätzliche Tage</h2><div class="timeline">@foreach($extensionDays as $extra)<div><span>#{{ $extra->sequence_no }} · {{ $extra->date }}</span><strong>{{ strtoupper($extra->source_type) }} · {{ $extra->paid?number_format((float)$extra->amount,2,',','.').' €':'unbezahlt' }}</strong><small>{{ $extra->reason }}</small></div>@endforeach</div></div>
@endif

@if($damageCases->count())
<div class="panel"><h2>Beschädigungsvorgänge</h2>
@foreach($damageCases as $case)
<div class="operation-block"><div class="day-top"><strong>Vorgang #{{ $case->id }}</strong><span class="status">{{ strtoupper($case->status) }}</span></div><p>{{ $case->reason }}</p>
@if(in_array($case->status,['reported','evidence_requested','review'],true))
<form method="post" action="{{ route('admin.orders.damage-evidence',[$order,$case->id]) }}" class="form-grid">@csrf<label>Zusatznachweis<select name="type"><option value="photo">Foto</option><option value="video">Video</option><option value="text">Freitext</option><option value="field">anderes Nachweisfeld</option></select></label><label>Frist<input type="datetime-local" name="due_at" required></label><label class="full">Anweisung<textarea name="instructions" required rows="2"></textarea></label><div class="full"><button class="btn secondary">Nachweis anfordern</button></div></form>
<form method="post" action="{{ route('admin.orders.damage-decision',[$order,$case->id]) }}" class="stack-form" style="margin-top:10px">@csrf<label>Entscheidung<select name="decision"><option value="accept">Beschädigung anerkennen → kompletter Neustart</option><option value="reject">Nicht anerkennen → gleicher Artikel</option></select></label><label>Hinweis<textarea name="note" rows="2"></textarea></label><button class="btn primary">Entscheiden</button></form>
@endif
</div>
@endforeach
</div>
@endif

@if($digitalComponents->count())
<div class="panel"><h2>Digitale Bestandteile</h2>
@foreach($digitalComponents as $component)
<div class="operation-block"><div class="day-top"><strong>{{ $component->title }}</strong><span class="status">{{ strtoupper(str_replace('_',' ',$component->status)) }}</span></div>
@foreach($digitalVersions->get($component->id,collect()) as $version)
<div class="operation-row"><div><strong>V{{ $version->version_no }} · {{ strtoupper($version->submission_type) }}</strong><small>{{ $version->submitted_at ? \Carbon\Carbon::parse($version->submitted_at)->format('d.m.Y H:i') : '' }} @if($version->final_submission) · FINAL @endif</small></div>
@if($version->submission_type==='text')<details><summary>Text ansehen</summary><div class="notice" style="white-space:pre-wrap">{{ $version->text_content }}</div></details>@else<a class="btn secondary" href="{{ route('admin.orders.digital.download',[$order,$version->id]) }}">Datei herunterladen</a>@endif
</div>
@endforeach

@php($round=$revisionRounds->first(fn($r)=>$r->digital_component_id===$component->id && in_array($r->status,['open','submitted'],true)))
@if($round)<h4>Revision {{ $round->round_no }}</h4>@foreach($revisionItems->get($round->id,collect()) as $item)<form method="post" action="{{ route('admin.digital.revision-item',$item->id) }}" class="operation-row">@csrf<div><strong>{{ $item->description }}</strong><small>{{ strtoupper($item->status) }}</small></div><select name="status"><option value="done">Erledigt</option><option value="insufficient">Nicht ausreichend</option><option value="change_again">Erneut ändern</option></select><input name="admin_comment" placeholder="Kommentar"><button class="btn secondary">Speichern</button></form>@endforeach@endif

@if(in_array($component->status,['submitted','revision_required','draft'],true) && !$order->isTerminal())
<form method="post" action="{{ route('admin.orders.digital.review',[$order,$component->id]) }}" class="stack-form">@csrf
<label>Entscheidung<select name="decision"><option value="accepted">Akzeptieren</option><option value="revision">Revision anfordern</option><option value="partial">Teilweise akzeptieren</option><option value="rejected">Endgültig ablehnen</option></select></label>
<label>Teilbetrag (€)<input type="number" name="amount" min="0" step="0.01"></label>
<label>Grund / Anweisung<textarea name="reason" rows="3"></textarea></label>
<label>Revisionspunkte – einer pro Zeile<textarea name="revision_items_text" rows="4"></textarea></label>
<label>Revisionsfrist<input type="datetime-local" name="due_at"></label>
<button class="btn primary">Digitale Prüfung speichern</button>
</form>
@endif
</div>
@endforeach
</div>
@endif

@if($order->shipment)
<div class="panel"><h2>Versand</h2><dl class="meta-list"><div><dt>Status</dt><dd>{{ strtoupper($order->shipment->status) }}</dd></div><div><dt>Dienstleister</dt><dd>{{ $order->shipment->carrier ?: '–' }}</dd></div><div><dt>Tracking</dt><dd>{{ $order->shipment->tracking_number ?: '–' }}</dd></div><div><dt>Nachweisprüfung</dt><dd>{{ strtoupper($order->shipment->review_status) }}</dd></div></dl>
@foreach($order->shipment->evidences as $evidence)<a class="btn secondary" href="{{ route('admin.shipments.evidence',$evidence) }}">{{ $evidence->type==='package'?'Paketfoto':'Versandbeleg' }}</a>@endforeach
</div>
@endif

@if(in_array($order->status,['shipped','received'],true))
<div class="panel"><h2>Wareneingang</h2><form method="post" action="{{ route('admin.orders.goods-receipt',$order) }}" class="stack-form">@csrf<label>Status<select name="complete"><option value="1">Eingegangen</option><option value="0">Problem / unvollständig</option></select></label><label>Notiz<textarea name="note" rows="3"></textarea></label><button class="btn primary">Wareneingang speichern</button></form></div>
@endif

@if($order->status==='inspection')
<div class="panel"><h2>Finale Warenprüfung</h2><p>Kein Punkte- oder KO-System. Entscheide den Auftrag direkt.</p>
<form method="post" action="{{ route('admin.orders.goods-inspection',$order) }}" class="stack-form">@csrf
<label>Entscheidung<select name="result"><option value="accepted">Vollständig akzeptieren</option><option value="partial">Teilweise akzeptieren</option><option value="rejected">Endgültig ablehnen</option></select></label>
<label>Teilbetrag bei Teilannahme (€)<input type="number" name="amount" min="0" max="{{ $order->compensation_total }}" step="0.01"></label>
<label>Begründung<textarea name="reason" rows="3"></textarea></label>
<label>Mitteilung an Verkäuferin optional<textarea name="seller_message" rows="3"></textarea></label>
<label>Interne Abschlussnotiz optional<textarea name="internal_note" rows="3"></textarea></label>
<button class="btn primary">Abschlussprüfung speichern</button>
</form></div>
@endif

<div class="panel"><h2>Statushistorie</h2><div class="timeline">@foreach($order->statusHistory->sortByDesc('created_at') as $event)<div><span>{{ $event->created_at->format('d.m.Y H:i') }}</span><strong>{{ strtoupper(str_replace('_',' ',$event->to_status)) }}</strong><small>{{ $event->reason }}</small></div>@endforeach</div></div>
</section>

<aside>
@if(!$order->isTerminal())
<div class="panel"><h2>Manueller Zusatztag</h2><form method="post" action="{{ route('admin.orders.extra-day',$order) }}" class="stack-form">@csrf<label class="check"><input type="checkbox" name="paid" value="1"><span>Bezahlter Zusatztag</span></label><label>Betrag bei bezahlt (€)<input type="number" name="amount" min="0" step="0.01" value="0"></label><label>Grund optional<textarea name="reason" rows="3"></textarea></label><button class="btn primary wide">Tag am Ende anhängen</button></form></div>
@endif

@if(in_array($order->status,['precheck','precheck_resubmit','active','waiting_shipping','shipping_overdue','shipped','received','inspection','accepted'],true))
<div class="panel"><h2>Anforderungen ändern</h2><form method="post" action="{{ route('admin.orders.requirements',$order) }}" class="stack-form">@csrf<label>Neue Anforderung<textarea name="requirement_text" rows="4" required></textarea></label><label>Wirksam<select name="effective_mode"><option value="immediately">Sofort</option><option value="next_window">Ab nächstem Nachweisfenster</option><option value="next_day">Ab nächstem Kalendertag</option><option value="custom">Konkreter Zeitpunkt</option></select></label><label>Konkreter Zeitpunkt<input type="datetime-local" name="effective_at"></label><label>Zusatzvergütung (€)<input type="number" name="additional_compensation" min="0" step="0.01" value="0"></label><button class="btn secondary wide">Änderung speichern</button></form></div>
@endif

@if(!$order->isTerminal())
<div class="panel"><h2>Auftrag endgültig beenden</h2><form method="post" action="{{ route('admin.orders.status',$order) }}" class="stack-form">@csrf<label>Entscheidung<select name="status"><option value="cancelled">Abbrechen</option><option value="rejected">Endgültig ablehnen</option></select></label><label>Begründung<textarea name="reason" rows="4" required></textarea></label><label>Freizugebender Teilbetrag (€)<input type="number" name="compensation_amount" min="0" max="{{ $order->compensation_total }}" step="0.01" value="0"></label><button class="btn secondary wide">Entscheidung ausführen</button></form></div>
@endif

@if($order->conversation)<div class="panel"><h2>Kommunikation</h2><a class="btn secondary wide" href="{{ route('admin.messages.show',$order->conversation) }}">Auftragschat öffnen</a></div>@endif
</aside>
</div>
@endsection
