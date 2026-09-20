@extends('layouts.app')
@section('title',$conversation->subject)
@section('content')
<div class="page-head split"><div><span class="eyebrow">Auftragschat</span><h1>{{ $conversation->subject }}</h1><p>Textkommunikation zu Auftrag #{{ $conversation->order->order_number }}. Nachweise und Dateien werden ausschließlich über die dafür vorgesehenen Auftragsfunktionen eingereicht.</p></div><a class="btn secondary" href="{{ route('orders.show',$conversation->order) }}">Zum Auftrag</a></div>
<div class="panel">@forelse($conversation->messages as $message)<div class="chat-message"><strong>{{ $message->user->isAdmin()?'Admin':$message->user->first_name }}</strong><small>{{ $message->created_at->format('d.m.Y H:i') }}@if($message->read_at) · gelesen@endif</small><p>{{ $message->body }}</p></div>@empty<div class="empty">Noch keine Nachrichten.</div>@endforelse</div>
@if(!$writeLocked)<form method="post" action="{{ route('messages.reply',$conversation) }}" class="panel stack-form" style="margin-top:16px">@csrf<label>Nachricht<textarea name="message" rows="5" required></textarea></label><button class="btn primary">Senden</button></form>@else<div class="notice" style="margin-top:16px">Der Auftrag ist archiviert. Der Chat bleibt lesbar, ist aber schreibgeschützt.</div>@endif
@endsection
