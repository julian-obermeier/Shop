@extends('layouts.app')
@section('title','Meine Aufträge')
@section('content')
<div class="page-head"><div><span class="eyebrow">Aufträge</span><h1>Meine Aufträge</h1><p>Von der Annahme bis zur Vergütungsfreigabe – jeder Schritt bleibt nachvollziehbar.</p></div></div>
<div class="table-card"><table><thead><tr><th>Auftrag</th><th>Angebot</th><th>Status</th><th>Zeitraum</th><th>Vergütung</th><th></th></tr></thead><tbody>@forelse($orders as $order)<tr><td><strong>#{{ $order->order_number }}</strong></td><td>{{ data_get($order->offer_snapshot,'title') }}</td><td><span class="status {{ $order->status }}">{{ strtoupper(str_replace('_',' ',$order->status)) }}</span></td><td>{{ $order->start_date?->format('d.m.Y') ?? '–' }} – {{ $order->end_date?->format('d.m.Y') ?? '–' }}</td><td><strong>{{ number_format($order->compensation_total,2,',','.') }} €</strong></td><td><a href="{{ route('orders.show',$order) }}">Öffnen →</a></td></tr>@empty<tr><td colspan="6" class="empty">Noch keine Aufträge vorhanden.</td></tr>@endforelse</tbody></table></div>{{ $orders->links() }}
@endsection
