@extends('layouts.app')
@section('title','Vorprüfungen')
@section('content')
<div class="page-head"><span class="eyebrow">Administration</span><h1>Vorprüfungen</h1><p>Artikel und Voraussetzungen vor dem eigentlichen Auftragsstart prüfen.</p></div>
<div class="table-card"><table><thead><tr><th>Auftrag</th><th>Anbieterin</th><th>Angaben</th><th>Foto</th><th>Entscheidung</th></tr></thead><tbody>
@forelse($prechecks as $precheck)<tr><td><a href="{{ route('admin.orders.show',$precheck->order) }}">#{{ $precheck->order->order_number }}</a></td><td>{{ $precheck->order->user->first_name }} {{ $precheck->order->user->last_name }}</td><td><strong>{{ $precheck->item_type ?: 'Artikel' }}</strong><br>{{ $precheck->item_size }}<br><small>{{ $precheck->item_description }}</small></td><td>
@if($precheck->photo_path)<a href="{{ route('admin.prechecks.file',$precheck) }}">Aktuelles Foto öffnen</a>@endif
@php($history=(array)data_get($precheck->answers,'photo_history',[]))
@if(count($history))
<br><small>Historie:</small>
@foreach($history as $index=>$entry)
<br><a href="{{ route('admin.prechecks.history-file',[$precheck,$index]) }}">Version {{ $index+1 }}</a>
@if(!empty($entry['sha256']))<small> · SHA-256 {{ IlluminateSupportStr::limit($entry['sha256'],12,'…') }}</small>@endif
@endforeach
@endif
</td><td><form method="post" action="{{ route('admin.prechecks.review',$precheck) }}" class="stack-form">@csrf<select name="status"><option value="accepted">Freigeben</option><option value="resubmit">Neu anfordern</option><option value="rejected">Ablehnen</option></select><textarea name="admin_comment" rows="2"></textarea><button class="btn primary">Speichern</button></form></td></tr>
@empty<tr><td colspan="5" class="empty">Keine offenen Vorprüfungen.</td></tr>@endforelse
</tbody></table></div>{{ $prechecks->links() }}
@endsection
