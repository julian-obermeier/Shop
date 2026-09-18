@extends('layouts.app')
@section('title','Nachrichten')
@section('content')
<div class="page-head"><span class="eyebrow">Kommunikation</span><h1>Nachrichten</h1><p>Auftragsbezogene Rückfragen und Support bleiben zentral dokumentiert.</p></div>
<div class="wallet-layout">
<section class="table-card"><table><thead><tr><th>Betreff</th><th>Auftrag</th><th>Status</th><th>Letzte Nachricht</th><th></th></tr></thead><tbody>
@forelse($conversations as $conversation)
<tr><td><strong>{{ $conversation->subject }}</strong></td><td>{{ $conversation->order?->order_number ? '#'.$conversation->order->order_number : 'Allgemein' }}</td><td><span class="status {{ $conversation->status }}">{{ strtoupper($conversation->status) }}</span></td><td>{{ $conversation->last_message_at?->format('d.m.Y H:i') }}</td><td><a href="{{ route('messages.show',$conversation) }}">Öffnen →</a></td></tr>
@empty<tr><td colspan="5" class="empty">Noch keine Unterhaltungen.</td></tr>@endforelse
</tbody></table>{{ $conversations->links() }}</section>

<aside class="panel"><h2>Neue Nachricht</h2>
<form method="post" enctype="multipart/form-data" action="{{ route('messages.store') }}" class="stack-form">@csrf
<label>Betreff<input name="subject" required></label>
<label>Auftrag<select name="order_id"><option value="">Allgemein</option>@foreach(auth()->user()->orders()->latest()->get() as $order)<option value="{{ $order->id }}">#{{ $order->order_number }} · {{ data_get($order->offer_snapshot,'title') }}</option>@endforeach</select></label>
<label>Nachricht<textarea name="message" rows="6"></textarea></label>
<label>Anhang optional<input type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf"><small>Bilder oder PDF, maximal 10 MB. Bilder werden von Metadaten bereinigt.</small></label>
<button class="btn primary wide">Nachricht senden</button>
</form></aside>
</div>
@endsection
