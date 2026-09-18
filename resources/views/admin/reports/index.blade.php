@extends('layouts.app')
@section('title','Berichte')
@section('content')
<div class="page-head split"><div><span class="eyebrow">Reporting</span><h1>Berichte & Exporte</h1><p>Operative Kennzahlen und exportierbare Rohdaten für weitere Auswertungen.</p></div><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn secondary" href="{{ route('admin.reports.export','orders') }}">Aufträge CSV</a><a class="btn secondary" href="{{ route('admin.reports.export','users') }}">Anbieterinnen CSV</a><a class="btn secondary" href="{{ route('admin.reports.export','payouts') }}">Auszahlungen CSV</a></div></div>
<div class="admin-stats">
<div><span>Anbieterinnen</span><strong>{{ $stats['providers'] }}</strong></div>
<div><span>Verifiziert</span><strong>{{ $stats['verified'] }}</strong></div>
<div><span>Aufträge gesamt</span><strong>{{ $stats['orders_total'] }}</strong></div>
<div><span>Aktive Aufträge</span><strong>{{ $stats['orders_active'] }}</strong></div>
<div><span>Freigegebene Vergütung</span><strong>{{ number_format($stats['compensation_total'],2,',','.') }} €</strong></div>
<div><span>Ausgezahlt</span><strong>{{ number_format($stats['payouts_paid'],2,',','.') }} €</strong></div>
<div><span>Nachweise</span><strong>{{ $stats['proofs_total'] }}</strong></div>
<div><span>Beanstandete Nachweise</span><strong>{{ $stats['proofs_rejected'] }}</strong></div>
</div>
<div class="wallet-layout">
<section class="table-card"><div class="card-head"><h2>Auftragsstatus</h2></div><table><thead><tr><th>Status</th><th>Anzahl</th></tr></thead><tbody>@foreach($statusCounts as $row)<tr><td>{{ strtoupper(str_replace('_',' ',$row->status)) }}</td><td>{{ $row->total }}</td></tr>@endforeach</tbody></table></section>
<aside class="table-card"><div class="card-head"><h2>Meistgenutzte Angebote</h2></div><table><thead><tr><th>Angebot</th><th>Aufträge</th></tr></thead><tbody>@foreach($topOffers as $offer)<tr><td>{{ $offer->title }}</td><td>{{ $offer->orders_count }}</td></tr>@endforeach</tbody></table></aside>
</div>
@endsection
