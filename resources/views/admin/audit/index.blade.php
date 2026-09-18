@extends('layouts.app')
@section('title','Audit-Log')
@section('content')
<div class="page-head"><span class="eyebrow">Sicherheit & Nachvollziehbarkeit</span><h1>Audit-Log</h1><p>Kritische administrative Änderungen bleiben nachvollziehbar protokolliert.</p></div>
<form class="filters" method="get"><input name="action" value="{{ request('action') }}" placeholder="Aktion filtern"><input type="number" name="user_id" value="{{ request('user_id') }}" placeholder="Admin-ID"><button class="btn secondary">Filtern</button></form>
<div class="table-card"><table><thead><tr><th>Zeit</th><th>Benutzer</th><th>Aktion</th><th>Objekt</th><th>IP</th><th>Änderung</th></tr></thead><tbody>
@foreach($logs as $log)<tr><td>{{ $log->created_at->format('d.m.Y H:i:s') }}</td><td>{{ $log->user?->email ?: 'System' }}</td><td><strong>{{ $log->action }}</strong></td><td>{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</td><td>{{ $log->ip_address }}</td><td><details><summary>Anzeigen</summary><pre style="white-space:pre-wrap;max-width:500px">{{ json_encode(['before'=>$log->before,'after'=>$log->after],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details></td></tr>@endforeach
</tbody></table></div>{{ $logs->links() }}
@endsection
