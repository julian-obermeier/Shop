@extends('layouts.app')
@section('title','Vorprüfungen')
@section('content')
<div class="page-head"><span class="eyebrow">Administration</span><h1>Vorprüfungen</h1><p>Artikel und Voraussetzungen vor dem eigentlichen Auftragsstart prüfen.</p></div>
<div class="table-card"><table><thead><tr><th>Auftrag</th><th>Anbieterin</th><th>Angaben</th><th>Foto</th><th>Entscheidung</th></tr></thead><tbody>
@forelse($prechecks as $precheck)<tr><td><a href="{{ route('admin.orders.show',$precheck->order) }}">#{{ $precheck->order->order_number }}</a></td><td>{{ $precheck->order->user->first_name }} {{ $precheck->order->user->last_name }}</td><td><strong>{{ $precheck->item_type ?: 'Artikel' }}</strong><br>{{ $precheck->item_size }}<br><small>{{ $precheck->item_description }}</small></td><td>@if($precheck->photo_path)<a href="{{ route('admin.prechecks.file',$precheck) }}">Sicher öffnen</a>@endif</td><td><form method="post" action="{{ route('admin.prechecks.review',$precheck) }}" class="stack-form">@csrf<select name="status"><option value="accepted">Freigeben</option><option value="resubmit">Neu anfordern</option><option value="rejected">Ablehnen</option></select><textarea name="admin_comment" rows="2"></textarea><button class="btn primary">Speichern</button></form></td></tr>
@empty<tr><td colspan="5" class="empty">Keine offenen Vorprüfungen.</td></tr>@endforelse
</tbody></table></div>{{ $prechecks->links() }}
@endsection
