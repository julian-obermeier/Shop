@extends('layouts.app')
@section('title','Nicht zuordenbare Sendungen')
@section('content')
<div class="page-head">
<div>
<span class="eyebrow">Wareneingang</span>
<h1>Nicht zuordenbare Sendungen</h1>
<p>Sendungen ohne eindeutige Auftragszuordnung werden dauerhaft dokumentiert. Es gibt keine nachträgliche Zuordnungsfrist und keine automatische Rücksendung.</p>
</div>
</div>

<div class="admin-order-grid">
<section>
<form class="filters" method="get">
<input name="q" value="{{ request('q') }}" placeholder="Absender, Tracking oder Dienstleister">
<button class="btn secondary">Suchen</button>
</form>

<div class="table-card">
<table>
<thead><tr><th>Eingang</th><th>Absender</th><th>Versand</th><th>Zuordnungsversuch</th><th>Status</th></tr></thead>
<tbody>
@forelse($shipments as $shipment)
<tr>
<td><strong>{{ $shipment->received_at->format('d.m.Y H:i') }}</strong><br><small>Erfasst von {{ $shipment->recorder?->first_name }} {{ $shipment->recorder?->last_name }}</small></td>
<td>{{ $shipment->sender_name ?: 'unbekannt' }}<br><small>{{ $shipment->sender_address ?: 'keine Adresse erkennbar' }}</small></td>
<td>{{ $shipment->carrier ?: '–' }}<br><small>{{ $shipment->tracking_number ?: 'kein Tracking' }}@if($shipment->shipping_date) · {{ $shipment->shipping_date->format('d.m.Y') }}@endif</small></td>
<td><div style="white-space:pre-wrap">{{ $shipment->matching_attempt }}</div>@if($shipment->notes)<small>{{ $shipment->notes }}</small>@endif</td>
<td><span class="status rejected">NICHT ZUORDENBAR</span><br><small>keine Vergütung · Ware verbleibt beim Betreiber</small></td>
</tr>
@empty
<tr><td colspan="5" class="empty">Keine nicht zuordenbaren Sendungen dokumentiert.</td></tr>
@endforelse
</tbody>
</table>
</div>
{{ $shipments->links() }}
</section>

<aside>
<div class="panel">
<h2>Sendung dokumentieren</h2>
<p>Vor dieser Dokumentation muss versucht worden sein, die Sendung anhand von Absender, Tracking, Versanddatum und offenen Aufträgen eindeutig zuzuordnen.</p>
<form method="post" action="{{ route('admin.unassigned-shipments.store') }}" class="stack-form">@csrf
<label>Eingangszeit<input type="datetime-local" name="received_at" value="{{ now('Europe/Berlin')->format('Y-m-d\TH:i') }}" required></label>
<label>Absendername<input name="sender_name"></label>
<label>Absenderanschrift<textarea name="sender_address" rows="2"></textarea></label>
<label>Versanddienstleister<input name="carrier"></label>
<label>Trackingnummer<input name="tracking_number"></label>
<label>Versanddatum<input type="date" name="shipping_date"></label>
<label>Durchgeführter Zuordnungsversuch<textarea name="matching_attempt" rows="5" required placeholder="Welche offenen Aufträge, Absenderdaten, Trackinginformationen und Versanddaten wurden geprüft?"></textarea></label>
<label>Zusätzliche Dokumentation<textarea name="notes" rows="3"></textarea></label>
<div class="notice">Nach Speicherung gilt die Sendung als nicht eindeutig zuordenbar: keine Vergütung, keine automatische Rücksendung und keine nachträgliche Zuordnungsfrist.</div>
<button class="btn primary wide">Als nicht zuordenbar dokumentieren</button>
</form>
</div>
</aside>
</div>
@endsection
