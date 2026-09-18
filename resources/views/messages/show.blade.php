@extends('layouts.app')
@section('title',$conversation->subject)
@section('content')
<div class="page-head split"><div><span class="eyebrow">Unterhaltung</span><h1>{{ $conversation->subject }}</h1><p>{{ $conversation->order ? 'Auftrag #'.$conversation->order->order_number : 'Allgemeine Anfrage' }}</p></div><a class="btn secondary" href="{{ route('messages.index') }}">← Nachrichten</a></div>

<div class="panel">
@foreach($conversation->messages as $message)
<div style="padding:14px 0;border-bottom:1px solid var(--line)">
<strong>{{ $message->user->isAdmin() ? 'Team' : $message->user->first_name }}</strong><small class="muted"> · {{ $message->created_at->format('d.m.Y H:i') }}</small>
@if($message->body)<p style="white-space:pre-wrap">{{ $message->body }}</p>@endif
@if($message->attachment_path)
<div class="notice"><a href="{{ route('messages.attachment',$message) }}">📎 {{ $message->attachment_original_name ?: 'Anhang öffnen' }}</a>@if($message->attachment_size)<small> · {{ number_format($message->attachment_size/1024,0,',','.') }} KB</small>@endif</div>
@endif
</div>
@endforeach
</div>

@if($conversation->status!=='closed')
<form method="post" enctype="multipart/form-data" action="{{ route('messages.reply',$conversation) }}" class="panel stack-form" style="margin-top:16px">@csrf
<label>Antwort<textarea name="message" rows="5"></textarea></label>
<label>Anhang optional<input type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf"></label>
<button class="btn primary">Antwort senden</button>
</form>
@endif
@endsection
