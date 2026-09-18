@extends('layouts.app')
@section('title','Nachrichtenverwaltung')
@section('content')
<div class="page-head"><span class="eyebrow">Administration</span><h1>Nachrichten</h1></div>
<div class="table-card"><table><thead><tr><th>Anbieterin</th><th>Betreff</th><th>Auftrag</th><th>Status</th><th>Letzte Nachricht</th><th></th></tr></thead><tbody>
@foreach($conversations as $conversation)<tr><td>{{ $conversation->user->first_name }} {{ $conversation->user->last_name }}</td><td><strong>{{ $conversation->subject }}</strong></td><td>{{ $conversation->order?->order_number ? '#'.$conversation->order->order_number : '–' }}</td><td><span class="status {{ $conversation->status }}">{{ strtoupper($conversation->status) }}</span></td><td>{{ $conversation->last_message_at?->format('d.m.Y H:i') }}</td><td><a href="{{ route('admin.messages.show',$conversation) }}">Öffnen →</a></td></tr>@endforeach
</tbody></table></div>{{ $conversations->links() }}
@endsection
