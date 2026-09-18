@extends('layouts.app')
@section('title','Admin Auftrag #'.$order->order_number)
@section('content')
<div class="page-head split">
<div><span class="eyebrow">Auftrag #{{ $order->order_number }}</span><h1>{{ data_get($order->offer_snapshot,'title') }}</h1><p>{{ $order->user->first_name }} {{ $order->user->last_name }} · {{ $order->user->email }}</p><span class="status {{ $order->status }}">{{ strtoupper(str_replace('_',' ',$order->status)) }}</span></div>
<div class="headline-amount"><span>Vergütung</span><strong>{{ number_format($order->compensation_total,2,',','.') }} €</strong></div>
</div>

<div class="admin-order-grid">
<section>
@if($order->fieldValues->count())
<div class="panel"><h2>Auftragsangaben</h2><dl class="meta-list">@foreach($order->fieldValues as $field)<div><dt>{{ $field->label }}</dt><dd>{{ $field->value==='1' && data_get($field->field_snapshot,'type')==='checkbox' ? 'Ja' : ($field->value ?: '–') }}</dd></div>@endforeach</dl></div>
@endif

@if($order->precheck)
<div class="panel"><h2>Vorprüfung</h2><dl class="meta-list"><div><dt>Status</dt><dd>{{ strtoupper($order->precheck->status) }}</dd></div><div><dt>Artikel</dt><dd>{{ $order->precheck->item_type ?: '–' }}</dd></div><div><dt>Größe</dt><dd>{{ $order->precheck->item_size ?: '–' }}</dd></div></dl><p>{{ $order->precheck->item_description }}</p>@if($order->precheck->photo_path)<a class="btn secondary" href="{{ route('admin.prechecks.file',$order->precheck) }}">Prüffoto öffnen</a>@endif</div>
@endif

<div class="panel"><h2>Versand & Frist</h2>
<dl class="meta-list">
<div><dt>Versandfrist</dt><dd>{{ $order->shipping_due_at?->format('d.m.Y H:i') ?: 'noch nicht gesetzt' }}</dd></div>
@if($order->shipment)
<div><dt>Dienstleister</dt><dd>{{ $order->shipment->carrier }}</dd></div>
<div><dt>Tracking</dt><dd>{{ $order->shipment->tracking_number }}</dd></div>
<div><dt>Versendet</dt><dd>{{ $order->shipment->shipped_at?->format('d.m.Y H:i') }}</dd></div>
@endif
</dl>
@if(!$order->shipment && $order->shipping_due_at?->isPast())<div class="flash error">Versandfrist überschritten.</div>@endif
</div>

<div class="panel"><div class="section-head"><div><span class="eyebrow">Prüfung</span><h2>Nachweise</h2></div></div>
@forelse($order->days as $day)
@php($validProofs=$day->proofs->whereIn('review_status',['pending','accepted'])->count())
<div class="admin-day"><div><strong>Tag {{ $day->day_number }}</strong><small>{{ $day->date->format('d.m.Y') }} · {{ $validProofs }}/{{ $day->required_proofs }} gültig/offen</small></div><div class="proof-chips">@foreach($day->proofs as $proof)<a href="{{ route('admin.proofs.file',$proof) }}">{{ $proof->original_name }} · {{ $proof->review_status }}</a>@endforeach</div></div>
@empty<p class="muted">Noch keine Auftragstage vorhanden.</p>@endforelse
</div>

<div class="panel"><h2>Statushistorie</h2><div class="timeline">@foreach($order->statusHistory->sortByDesc('created_at') as $event)<div><span>{{ $event->created_at->format('d.m.Y H:i') }}</span><strong>{{ strtoupper(str_replace('_',' ',$event->to_status)) }}</strong><small>{{ $event->reason }}</small></div>@endforeach</div></div>
</section>

<aside>
@if(in_array($order->status,['shipped','received']))
<div class="panel"><h2>Wareneingang</h2><form method="post" action="{{ route('admin.orders.goods-receipt',$order) }}" class="stack-form">@csrf
<label>Wareneingang<select name="complete"><option value="1">Vollständig</option><option value="0">Teilweise / Problem</option></select></label>
<label>Notiz<textarea name="note" rows="4"></textarea></label>
<button class="btn primary wide">Wareneingang erfassen</button>
</form></div>
@endif

<div class="panel"><h2>Status ändern</h2><form method="post" action="{{ route('admin.orders.status',$order) }}" class="stack-form">@csrf
<label>Status<select name="status">@foreach(['precheck','precheck_resubmit','approved','active','waiting_shipping','shipping_overdue','shipped','received','inspection','accepted','compensation_released','completed','cancelled','rejected','dispute'] as $status)<option value="{{ $status }}" @selected($order->status===$status)>{{ strtoupper(str_replace('_',' ',$status)) }}</option>@endforeach</select></label>
<label>Grund / Notiz<textarea name="reason" rows="4"></textarea></label>
<button class="btn secondary wide">Status speichern</button>
</form></div>

<div class="panel danger-zone"><h2>Vergütung</h2><p>Nach erfolgreicher Wareneingangs- und Abschlussprüfung wird die Vormerkung in verfügbares Guthaben umgebucht.</p><form method="post" action="{{ route('admin.orders.release',$order) }}">@csrf<button class="btn primary wide" @disabled(!in_array($order->status,['inspection','accepted'],true))>Vergütung freigeben</button></form></div>
</aside>
</div>
@endsection
