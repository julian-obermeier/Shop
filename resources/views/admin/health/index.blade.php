@extends('layouts.app')
@section('title','Systemzustand')
@section('content')
<div class="page-head split"><div><span class="eyebrow">Betrieb</span><h1>Systemzustand</h1><p>Letzte Prüfung: {{ $result['checked_at']->format('d.m.Y H:i:s') }}</p></div><span class="status {{ $result['ok']?'accepted':'rejected' }}">{{ $result['ok']?'SYSTEM OK':'FEHLER ERKANNT' }}</span></div>
<div class="table-card"><table><thead><tr><th>Prüfung</th><th>Status</th><th>Detail</th></tr></thead><tbody>
@foreach($result['checks'] as $check)
<tr><td><strong>{{ $check['name'] }}</strong></td><td><span class="status {{ $check['status']==='ok'?'accepted':($check['status']==='error'?'rejected':'pending') }}">{{ strtoupper($check['status']) }}</span></td><td>{{ $check['detail'] }}</td></tr>
@endforeach
</tbody></table></div>
<div class="notice" style="margin-top:16px">Diese Prüfung testet Datenbankzugriff und tatsächliches Schreiben/Lesen/Löschen in den relevanten Storage-Bereichen. Externe Mailzustellung wird hier bewusst nicht ausgelöst.</div>
@endsection
