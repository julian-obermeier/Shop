@extends('layouts.app')
@section('title','Nachrichten')
@section('content')
<div class="page-head"><span class="eyebrow">Kommunikation</span><h1>Auftragsnachrichten</h1><p>Nachrichten sind ausschließlich innerhalb eines konkreten Auftrags möglich.</p></div>
<div class="wallet-layout">
<section class="table-card"><table><thead><tr><th>Auftrag</th><th>Status</th><th>Letzte Nachricht</th><th></th></tr></thead><tbody>
@forelse($conversations as $conversation)
<tr><td><strong>#{{ $conversation->order?->order_number }}</strong><br><small>{{ data_get($conversation->order?->offer_snapshot,'title') }}</small></td><td><span class="status {{ $conversation->status }}">{{ strtoupper($conversation->status) }}</span></td><td>{{ $conversation->last_message_at?->format('d.m.Y H:i') }}</td><td><a href="{{ route('messages.show',$conversation) }}">Öffnen →</a></td></tr>
@empty<tr><td colspan="4" class="empty">Noch keine auftragsbezogenen Unterhaltungen.</td></tr>@endforelse
</tbody></table>{{ $conversations->links() }}</section>

<aside class="panel"><h2>Nachricht zu Auftrag</h2>
<form method="post" enctype="multipart/form-data" action="{{ route('messages.store') }}" class="stack-form">@csrf
<label>Auftrag<select name="order_id" required><option value="">Auftrag auswählen</option>@foreach(auth()->user()->orders()->latest()->get() as $order)<option value="{{ $order->id }}">#{{ $order->order_number }} · {{ data_get($order->offer_snapshot,'title') }}</option>@endforeach</select></label>
<label>Nachricht<textarea name="message" rows="6"></textarea></label>
<label>Anhang optional<input type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf"><small>Bilder oder PDF, maximal 10 MB.</small></label>
<button class="btn primary wide">Nachricht senden</button>
</form></aside>
</div>
@endsection
