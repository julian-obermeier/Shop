@extends('layouts.app')
@section('title','Admin Auftrag #'.$order->order_number)
@section('content')
@php
$inspectionConfig=data_get($order->current_requirements ?: $order->offer_snapshot,'inspection_config',[]);
$pointsAffect=(bool)data_get($inspectionConfig,'points_affect_compensation',false);
@endphp
<div class="page-head split">
<div>
<span class="eyebrow">Auftrag #{{ $order->order_number }}</span>
<h1>{{ data_get($order->offer_snapshot,'title') }}</h1>
<p>{{ $order->user->first_name }} {{ $order->user->last_name }} · {{ $order->user->email }}</p>
<span class="status {{ $order->status }}">{{ strtoupper(str_replace('_',' ',$order->status)) }}</span>
</div>
<div class="headline-amount"><span>Vereinbart</span><strong>{{ number_format($order->compensation_total,2,',','.') }} €</strong>@if($order->final_compensation!==null)<small>Final: {{ number_format($order->final_compensation,2,',','.') }} €</small>@endif</div>
</div>

<div class="admin-order-grid">
<section>
<div class="panel">
<h2>Termin & Ablauf</h2>
<dl class="meta-list">
<div><dt>Gewünschter Termin</dt><dd>{{ $order->proposed_start_date?->format('d.m.Y') ?: '–' }}</dd></div>
<div><dt>Bestätigter Aktivierungstag</dt><dd>{{ $order->confirmed_start_date?->format('d.m.Y') ?: '–' }}</dd></div>
<div><dt>Serie</dt><dd>{{ $order->series_number }}</dd></div>
<div><dt>Unterbrechungen</dt><dd>{{ $order->series_interruptions }}</dd></div>
<div><dt>Zuverlässigkeitsereignisse</dt><dd>{{ $order->reliability_issue_count }}</dd></div>
</dl>
@if($order->last_reliability_issue)<div class="notice">{{ $order->last_reliability_issue }}</div>@endif
</div>

@if($order->fieldValues->count())
<div class="panel"><h2>Auftragsangaben</h2><dl class="meta-list">@foreach($order->fieldValues as $field)<div><dt>{{ $field->label }}</dt><dd>{{ $field->value==='1' && data_get($field->field_snapshot,'type')==='checkbox' ? 'Ja' : ($field->value ?: '–') }}</dd></div>@endforeach</dl></div>
@endif

@if($order->precheck)
<div class="panel"><h2>Vorprüfung</h2>
<dl class="meta-list"><div><dt>Status</dt><dd>{{ strtoupper($order->precheck->status) }}</dd></div><div><dt>Artikel</dt><dd>{{ $order->precheck->item_type ?: '–' }}</dd></div><div><dt>Größe</dt><dd>{{ $order->precheck->item_size ?: '–' }}</dd></div></dl>
<p>{{ $order->precheck->item_description }}</p>
@if($order->precheck->photo_path)<a class="btn secondary" href="{{ route('admin.prechecks.file',$order->precheck) }}">Aktuelles Prüffoto öffnen</a>@endif
@php($precheckHistory=(array)data_get($order->precheck->answers,'photo_history',[]))
@if(count($precheckHistory))
<div class="notice" style="margin-top:10px">
<strong>Frühere Prüffotos</strong>
@foreach($precheckHistory as $index=>$entry)
<br><a href="{{ route('admin.prechecks.history-file',[$order->precheck,$index]) }}">Version {{ $index+1 }}</a>
@if(!empty($entry['sha256'])) · <small>SHA-256 {{ $entry['sha256'] }}</small>@endif
@endforeach
</div>
@endif
</div>
@endif

<div class="panel"><h2>Nachweise</h2>
@forelse($order->days->sortByDesc('series_number')->sortBy('day_number') as $day)
<div class="admin-day">
<div>
<strong>Serie {{ $day->series_number }} · {{ $day->day_number===0?'Start':('Tag '.$day->day_number) }}</strong>
<small>{{ $day->date->format('d.m.Y') }} · {{ strtoupper($day->status) }} · {{ $day->counts_toward_series?'zählt':'archiviert' }}</small>
@if($day->invalid_reason)<small>{{ $day->invalid_reason }}</small>@endif
</div>
<div class="proof-chips">
@foreach($day->proofs as $proof)
<div style="margin-bottom:8px">
<a href="{{ route('admin.proofs.file',$proof) }}">{{ $proof->window_key }} · Versuch {{ $proof->retry_number }} · {{ strtoupper($proof->review_status) }}</a>
@if($proof->text_value)<small> · Text: {{ $proof->text_value }}</small>@endif
@if(is_array($proof->proof_data))@foreach($proof->proof_data as $entry)<small> · {{ $entry['label']??'Pflichtangabe' }}: {{ $entry['value']??'–' }}</small>@endforeach @endif
@if($proof->review_comment)<small> · {{ $proof->review_comment }}</small>@endif
@if($proof->review_status==='rejected' && $proof->rejection_kind==='technical' && (int)$proof->retry_number>=2 && !$proof->extra_retry_granted)
<form method="post" action="{{ route('admin.proofs.extra-retry',$proof) }}" style="display:inline">@csrf<button class="btn secondary">Zusatzversuch +2h freigeben</button></form>
@endif
</div>
@endforeach
</div>
</div>
@empty<p class="muted">Noch keine Auftragstage vorhanden.</p>@endforelse
</div>

<div class="panel"><h2>Versand</h2>
<dl class="meta-list">
<div><dt>Versandfrist</dt><dd>{{ $order->shipping_due_at?->format('d.m.Y H:i') ?: 'noch nicht gesetzt' }}</dd></div>
@if($order->shipment)
<div><dt>Dienstleister</dt><dd>{{ $order->shipment->carrier }}</dd></div>
<div><dt>Tracking</dt><dd>{{ $order->shipment->tracking_number ?: '–' }}</dd></div>
<div><dt>Versendet</dt><dd>{{ $order->shipment->shipped_at?->format('d.m.Y H:i') }}</dd></div>
<div><dt>Nachweisprüfung</dt><dd>{{ strtoupper($order->shipment->review_status) }}</dd></div>
@endif
</dl>
@if($order->shipment)
@foreach($order->shipment->evidences->groupBy('attempt') as $attempt=>$evidences)
<div class="notice"><strong>Versuch {{ $attempt }}</strong><br>
@foreach($evidences as $evidence)<a href="{{ route('admin.shipments.evidence',$evidence) }}">{{ $evidence->type==='package'?'Paketfoto':'Versandbeleg' }}</a>@if(!$loop->last) · @endif @endforeach
</div>
@endforeach
<form method="post" action="{{ route('admin.shipments.review',$order->shipment) }}" class="stack-form">@csrf
<label>Versandnachweise<select name="review_status"><option value="accepted">Akzeptieren</option><option value="rejected">Ablehnen · 2h Nachreichung</option></select></label>
<label>Bei Ablehnung neu erforderlich<select name="resubmit_scope"><option value="receipt">Nur Versand-/Annahmebeleg</option><option value="package">Nur Paketfoto</option><option value="both">Paketfoto + Versand-/Annahmebeleg</option></select></label>
<label>Kommentar / Ablehnungsgrund<textarea name="review_comment" rows="3"></textarea></label>
<button class="btn secondary">Versandnachweise prüfen</button>
</form>
@endif
</div>

@if($order->goodsInspection)
<div class="panel"><h2>Finale Warenprüfung</h2>
<p><strong>Ergebnis:</strong> {{ strtoupper($order->goodsInspection->result) }} · Grundvergütung {{ number_format($order->goodsInspection->base_percentage,0) }} % · berechnet {{ number_format($order->goodsInspection->calculated_compensation,2,',','.') }} €</p>
@if($order->goodsInspection->reason)<div class="notice">{{ $order->goodsInspection->reason }}</div>@endif
@foreach(($order->goodsInspection->categories??[]) as $key=>$row)
@if(!str_starts_with((string)$key,'_'))<p><strong>{{ $row['label']??$key }}:</strong> {{ ($row['passed']??false)?'bestanden':'nicht bestanden' }} · {{ $row['points']??0 }}/10 @if($row['ko']??false) · KO@endif @if($row['comment']??null) · {{ $row['comment'] }}@endif</p>@endif
@endforeach
</div>
@endif

<div class="panel"><h2>Statushistorie</h2><div class="timeline">@foreach($order->statusHistory->sortByDesc('created_at') as $event)<div><span>{{ $event->created_at->format('d.m.Y H:i') }}</span><strong>{{ strtoupper(str_replace('_',' ',$event->to_status)) }}</strong><small>{{ $event->reason }}</small></div>@endforeach</div></div>
</section>

<aside>
@if($order->status==='requested')
<div class="panel"><h2>Anfrage entscheiden</h2>
<form method="post" action="{{ route('admin.orders.approve',$order) }}" class="stack-form">@csrf
<input type="hidden" name="start_date" value="{{ $order->proposed_start_date?->format('Y-m-d') }}">
<div class="notice">Wunschdatum der Anbieterin: <strong>{{ $order->proposed_start_date?->format('d.m.Y') }}</strong>. Dieses Formular bestätigt exakt dieses Datum. Für ein anderes Datum nutze den Gegenvorschlag darunter.</div>
<button class="btn primary wide">Auftrag + Wunschdatum bestätigen</button>
</form>
<hr>
<form method="post" action="{{ route('admin.orders.propose-date',$order) }}" class="stack-form">@csrf
<label>Alternativen Termin vorschlagen<input type="date" name="start_date" min="{{ now('Europe/Berlin')->format('Y-m-d') }}" required></label>
<button class="btn secondary wide">Termin vorschlagen</button>
</form>
<hr>
<form method="post" action="{{ route('admin.orders.reject-request',$order) }}" class="stack-form">@csrf
<label>Ablehnungsgrund optional<textarea name="reason" rows="3"></textarea></label>
<button class="btn secondary wide">Anfrage ablehnen</button>
</form>
</div>
@endif

@if($order->status==='awaiting_date_confirmation')
<div class="panel"><h2>Warten auf Terminbestätigung</h2><p>Vorgeschlagen: <strong>{{ $order->proposed_start_date?->format('d.m.Y') }}</strong>. Die Anbieterin muss den Termin ausdrücklich bestätigen.</p>
<form method="post" action="{{ route('admin.orders.reject-request',$order) }}" class="stack-form">@csrf
<label>Ablehnungsgrund optional<textarea name="reason" rows="3"></textarea></label>
<button class="btn secondary wide">Anfrage ablehnen</button>
</form></div>
@endif

@if($order->status==='request_rejected')
<div class="panel">
<h2>Abgelehnte Anfrage</h2>
<p>Diese Anfrage ist nicht endgültig abgeschlossen und darf vom Admin wieder geöffnet werden.</p>
<form method="post" action="{{ route('admin.orders.reopen-request',$order) }}">@csrf<button class="btn primary wide">Anfrage wieder öffnen</button></form>
</div>
@endif

@if(in_array($order->status,['shipped','received'],true))
<div class="panel"><h2>Wareneingang</h2>
<form method="post" action="{{ route('admin.orders.goods-receipt',$order) }}" class="stack-form">@csrf
<label>Wareneingang<select name="complete"><option value="1">Vollständig eingegangen</option><option value="0">Teilweise / Problem</option></select></label>
<label>Optionale Notiz<textarea name="note" rows="4"></textarea></label>
<button class="btn primary wide">Wareneingang erfassen</button>
</form>
</div>
@endif

@if($order->status==='inspection')
<div class="panel"><h2>Warenprüfung</h2>
<form method="post" action="{{ route('admin.orders.goods-inspection',$order) }}" class="stack-form">@csrf
@foreach(['appearance'=>'Aussehen','smell'=>'Geruch','taste'=>'Geschmack','proofs'=>'Nachweise','extras'=>'Extras'] as $key=>$label)
<fieldset style="border:1px solid var(--line);padding:12px;border-radius:12px">
<legend><strong>{{ $label }} @if(data_get($inspectionConfig,"categories.$key.ko",false)) · KO @endif</strong></legend>
<label>Status<select name="categories[{{ $key }}][passed]"><option value="1">Bestanden</option><option value="0">Nicht bestanden</option></select></label>
<label>Punkte 0–10<input type="number" name="categories[{{ $key }}][points]" min="0" max="10" value="10" required></label>
<label>Kommentar<textarea name="categories[{{ $key }}][comment]" rows="2"></textarea></label>
</fieldset>
@endforeach

@if(!$pointsAffect)
<label>Grundvergütung in %<input type="number" name="manual_base_percentage" min="0" max="100" step="0.01" value="100"></label>
@else
<div class="notice">Die Grundvergütung wird automatisch anhand der gespeicherten Punktebänder berechnet.</div>
@endif

@if($order->options->count())
<h3>Extras separat bewerten</h3>
@foreach($order->options as $option)
<fieldset style="border:1px solid var(--line);padding:12px;border-radius:12px">
<legend>{{ $option->name }} · {{ number_format($option->price_delta,2,',','.') }} €</legend>
<label class="check"><input type="checkbox" name="extras[{{ $option->id }}][fulfilled]" value="1"><span>Extra vollständig erfüllt</span></label>
<label>Kommentar<textarea name="extras[{{ $option->id }}][comment]" rows="2"></textarea></label>
</fieldset>
@endforeach
@endif

<label>Gesamtergebnis<select name="result"><option value="accepted">Annehmen und sofort vergüten</option><option value="rework">Korrektur / Nachbesserung</option><option value="rejected">Vollständig ablehnen</option></select></label>
<label>Grund bei Korrektur/Ablehnung<textarea name="reason" rows="4"></textarea></label>
<button class="btn primary wide">Warenprüfung abschließen</button>
</form>
</div>
@endif

@if($order->status==='paused')
<div class="panel">
<h2>Pause</h2>
<p>Vorheriger Status: <strong>{{ strtoupper(str_replace('_',' ',$order->paused_from_status ?: 'active')) }}</strong>.</p>
<form method="post" action="{{ route('admin.orders.resume',$order) }}" class="stack-form">@csrf
@if(in_array($order->paused_from_status,['approved','waiting_start'],true))
<label>Neuer Aktivierungstag<input type="date" name="activation_date" min="{{ now('Europe/Berlin')->format('Y-m-d') }}" value="{{ now('Europe/Berlin')->format('Y-m-d') }}"></label>
@endif
@if($order->paused_from_status==='active')
<div class="notice">Bei Fortsetzung einer bereits gestarteten Trageserie beginnt eine neue Serie wieder bei Tag 1.</div>
@elseif(in_array($order->paused_from_status,['waiting_shipping','shipping_overdue'],true))
<div class="notice">Bei Fortsetzung beginnt eine neue 24-Stunden-Versandfrist.</div>
@endif
<button class="btn primary wide">Auftrag fortsetzen</button>
</form>
</div>
@endif

@if(!$order->isTerminal() && !in_array($order->status,['requested','awaiting_date_confirmation'],true))
<div class="panel"><h2>Admin-Abbruch / Pause</h2>
<form method="post" action="{{ route('admin.orders.status',$order) }}" class="stack-form">@csrf
<label>Aktion<select name="status"><option value="paused">Pausieren</option><option value="cancelled">Abbrechen</option><option value="rejected">Beenden / ablehnen</option></select></label>
<label>Konkreter Grund<textarea name="reason" rows="4" required></textarea></label>
<label>Vergütung bei Abbruch (€)<input type="number" name="compensation_amount" min="0" max="{{ $order->compensation_total }}" step="0.01" value="0"></label>
<button class="btn secondary wide">Aktion ausführen</button>
</form>
</div>
@endif

@if(in_array($order->status,['precheck','precheck_resubmit','approved','waiting_start','active','paused','waiting_shipping','shipping_overdue','shipped','received','inspection','accepted'],true))
<div class="panel"><h2>Anforderungen nachträglich ändern</h2>
<form method="post" action="{{ route('admin.orders.requirements',$order) }}" class="stack-form">@csrf
<label>Neue verbindliche Anforderung<textarea name="requirement_text" rows="4" required></textarea></label>
<label>Wirksam<select name="effective_mode"><option value="immediately">Sofort</option><option value="next_window">Ab nächstem Nachweisfenster</option><option value="next_day">Ab nächstem Kalendertag</option><option value="custom">Konkreter Zeitpunkt</option></select></label>
<label>Konkreter Zeitpunkt optional<input type="datetime-local" name="effective_at"></label>
<label>Zusatzvergütung (€)<input type="number" name="additional_compensation" min="0" step="0.01" value="0"></label>
<button class="btn secondary wide">Anforderung verbindlich ändern</button>
</form>
</div>
@endif

@if($order->returnRequest)
<div class="panel"><h2>Rücksendung</h2>
<p>Status: <strong>{{ strtoupper($order->returnRequest->status) }}</strong><br>Frist: {{ $order->returnRequest->fulfillment_due_at?->format('d.m.Y H:i') }}</p>
@if($order->returnRequest->returned_at)<p>Zurückgesendet: <strong>{{ $order->returnRequest->returned_at->format('d.m.Y H:i') }}</strong></p>@endif
@if($order->returnRequest->tracking_number)<p>Tracking: <strong>{{ $order->returnRequest->tracking_number }}</strong></p>@endif
@if($order->returnRequest->method==='own_label' && $order->returnRequest->return_label_path)
<a class="btn secondary wide" href="{{ route('admin.returns.label',$order->returnRequest) }}">Rücksendeetikett öffnen</a>
@endif
@if($order->returnRequest->status==='awaiting_quote_payment')
<form method="post" action="{{ route('admin.returns.quote',$order->returnRequest) }}" class="stack-form">@csrf<label>Tatsächliche Versandkosten (€)<input type="number" name="amount" min="0.01" step="0.01" value="{{ $order->returnRequest->requested_shipping_cost }}"></label><button class="btn secondary">Kosten mitteilen</button></form>
@if($order->returnRequest->requested_shipping_cost)<form method="post" action="{{ route('admin.returns.confirm-payment',$order->returnRequest) }}">@csrf<button class="btn primary wide">Separate Zahlung bestätigt</button></form>@endif
@endif
@if($order->returnRequest->status==='ready')
<form method="post" action="{{ route('admin.returns.complete',$order->returnRequest) }}" class="stack-form">@csrf<label>Tracking optional<input name="tracking_number"></label><button class="btn primary wide">Rücksendung ausgeführt</button></form>
@endif
</div>
@endif
</aside>
</div>
@endsection
