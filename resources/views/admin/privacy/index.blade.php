@extends('layouts.app')
@section('title','Datenschutzanfragen')
@section('content')
<div class="page-head"><span class="eyebrow">Administration</span><h1>Datenschutzanfragen</h1><p>Export-/Löschanfragen prüfen und dokumentiert bearbeiten.</p></div>
<div class="table-card"><table><thead><tr><th>Anbieterin</th><th>Typ</th><th>Status</th><th>Eingang</th><th>Bearbeiter</th><th></th></tr></thead><tbody>
@forelse($requests as $item)
<tr>
<td>{{ $item->user->first_name }} {{ $item->user->last_name }}<br><small>{{ $item->user->email }}</small></td>
<td>{{ strtoupper($item->type) }}</td>
<td><span class="status {{ $item->status }}">{{ strtoupper($item->status) }}</span></td>
<td>{{ $item->created_at->format('d.m.Y H:i') }}</td>
<td>{{ $item->reviewer?->email ?: '–' }}</td>
<td><a href="{{ route('admin.privacy.show',$item) }}">Öffnen →</a></td>
</tr>
@empty<tr><td colspan="6" class="empty">Keine Datenschutzanfragen vorhanden.</td></tr>@endforelse
</tbody></table></div>{{ $requests->links() }}
@endsection
