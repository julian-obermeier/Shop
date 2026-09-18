@extends('layouts.app')
@section('title','Auftrag #'.$order->order_number)
@section('content')
@php($allComplete=$order->days->isNotEmpty() && $order->days->every(fn($day)=>$day->proofs->whereIn('review_status',['pending','accepted'])->count() >= $day->required_proofs))
<div class="page-head split"><div><span class="eyebrow">Auftrag #{{ $order->order_number }}</span><h1>{{ data_get($order->offer_snapshot,'title') }}</h1><span class="status {{ $order->status }}">{{ strtoupper(str_replace('_',' ',$order->status)) }}</span></div><div class="headline-amount"><span>Vergütung</span><strong>{{ number_format($order->compensation_total,2,',','.') }} €</strong></div></div>

@if(in_array($order->status,['precheck','precheck_resubmit']))
<div class="panel" style="margin-bottom:18px">
<h2>Vorprüfung erforderlich</h2>
@if($order->precheck?->admin_comment)<div class="notice">{{ $order->precheck->admin_comment }}</div>@endif
<form method="post" enctype="multipart/form-data" action="{{ route('orders.precheck',$order) }}" class="form-grid">@csrf
<input type="hidden" name="existing_photo" value="{{ $order->precheck?->photo_path }}">
<label>Artikelart<input name="item_type" value="{{ old('item_type',$order->precheck?->item_type) }}"></label>
<label>Größe / Variante<input name="item_size" value="{{ old('item_size',$order->precheck?->item_size) }}"></label>
<label class="full">Beschreibung<textarea name="item_description" rows="4" required>{{ old('item_description',$order->precheck?->item_description) }}</textarea></label>
<label class="full">Prüffoto<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" @required(!$order->precheck?->photo_path)><small>Das Bild wird beim Upload neu gerendert und von Metadaten bereinigt.</small></label>
<div class="full"><button class="btn primary">Vorprüfung einreichen</button></div>
</form>
</div>
@endif

@if(in_array($order->status,['approved','waiting_start']))
<div class="panel start-panel"><div><h3>Bereit zum Start?</h3><p>Beim Start werden {{ data_get($order->offer_snapshot,'duration_days') }} Tage mit den jeweiligen Nachweispflichten erzeugt.</p></div><form method="post" action="{{ route('orders.start',$order) }}">@csrf<button class="btn primary">Erfüllungsphase starten</button></form></div>
@endif

@if($order->status==='active' && $allComplete)
<div class="panel start-panel"><div><h3>Alle Pflichtnachweise vorhanden</h3><p>Du kannst die Erfüllungsphase jetzt abschließen und anschließend den Versand melden.</p></div><form method="post" action="{{ route('orders.complete',$order) }}">@csrf<button class="btn primary">Erfüllungsphase abschließen</button></form></div>
@endif

@if(in_array($order->status,['waiting_shipping','shipping_overdue']))
<div class="panel" style="margin-bottom:18px">
<h2>Versand melden</h2>
@if($order->shipping_due_at)
<div class="notice">
Versandfrist: <strong>{{ $order->shipping_due_at->format('d.m.Y H:i') }} Uhr</strong>
@if($order->shipping_due_at->isPast()) · <strong>überschritten</strong>@endif
</div>
@endif
<form method="post" action="{{ route('orders.shipment',$order) }}" class="form-grid">@csrf
<label>Versanddienstleister<input name="carrier" placeholder="z. B. DHL" required></label>
<label>Trackingnummer<input name="tracking_number" required></label>
<div class="full"><button class="btn primary">Versand speichern</button></div>
</form>
</div>
@endif

@if($order->shipment)
<div class="panel" style="margin-bottom:18px"><h2>Versand</h2><dl class="meta-list"><div><dt>Dienstleister</dt><dd>{{ $order->shipment->carrier }}</dd></div><div><dt>Tracking</dt><dd>{{ $order->shipment->tracking_number }}</dd></div><div><dt>Status</dt><dd>{{ strtoupper($order->shipment->status) }}</dd></div></dl></div>
@endif

<div class="order-layout">
<section>
<div class="section-head"><div><span class="eyebrow">Fortschritt</span><h2>Tag für Tag</h2></div></div>
<div class="days">
@forelse($order->days as $day)
@php($validProofs=$day->proofs->whereIn('review_status',['pending','accepted'])->count())
<article class="day-card" id="nachweise">
<div class="day-top"><div><span class="day-number">Tag {{ $day->day_number }}</span><strong>{{ $day->date->format('d.m.Y') }}</strong></div><span>{{ $validProofs }}/{{ $day->required_proofs }} gültige/offene Nachweise</span></div>
<div class="proof-list">@foreach($day->proofs as $proof)<div><span>📎 {{ $proof->original_name }}</span><span class="status {{ $proof->review_status }}">{{ strtoupper($proof->review_status) }}</span></div>@endforeach</div>
@if($validProofs < $day->required_proofs && $order->user_id===auth()->id())
<form method="post" enctype="multipart/form-data" action="{{ route('proofs.store',$day) }}" class="upload-form">@csrf
<input type="file" name="proof" accept="image/jpeg,image/png,image/webp" required>
<button class="btn secondary">Nachweis hochladen</button>
</form>
@endif
</article>
@empty<div class="empty">Die Tagesübersicht wird beim Start des Auftrags erzeugt.</div>@endforelse
</div>
</section>

<aside>
<div class="panel">
<h3>Vereinbarte Konditionen</h3>
<dl class="meta-list">
<div><dt>Dauer</dt><dd>{{ data_get($order->offer_snapshot,'duration_days') }} Tage</dd></div>
<div><dt>Nachweise/Tag</dt><dd>{{ data_get($order->offer_snapshot,'proofs_per_day') }}</dd></div>
<div><dt>Mindestdauer</dt><dd>{{ data_get($order->offer_snapshot,'minimum_minutes_per_day') }} Min.</dd></div>
<div><dt>Versandfrist</dt><dd>{{ data_get($order->offer_snapshot,'shipping_deadline_hours') }} Std.</dd></div>
@if($order->shipping_due_at)<div><dt>Versand bis</dt><dd>{{ $order->shipping_due_at->format('d.m.Y H:i') }}</dd></div>@endif
</dl>

@if($order->fieldValues->count())
<h4>Deine Angaben</h4>
<dl class="meta-list">@foreach($order->fieldValues as $field)<div><dt>{{ $field->label }}</dt><dd>{{ $field->value==='1' && data_get($field->field_snapshot,'type')==='checkbox' ? 'Ja' : ($field->value ?: '–') }}</dd></div>@endforeach</dl>
@endif

@if($order->options->count())
<h4>Extras</h4><ul>@foreach($order->options as $option)<li>{{ $option->name }} · +{{ number_format($option->price_delta,2,',','.') }} €</li>@endforeach</ul>
@endif

<a class="btn secondary wide" href="{{ route('messages.index') }}">Nachricht zum Auftrag senden</a>
</div>
</aside>
</div>
@endsection
