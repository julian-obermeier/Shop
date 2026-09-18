@extends('layouts.app')
@section('title','Verifizierungen')
@section('content')
<div class="page-head"><span class="eyebrow">Administration</span><h1>Verifizierungen</h1><p>Ausweisdokumente werden ausschließlich über geschützte Admin-Routen geöffnet.</p></div>
<div class="table-card"><table><thead><tr><th>Anbieterin</th><th>Eingereicht</th><th>Dateien</th><th>Entscheidung</th></tr></thead><tbody>
@forelse($verifications as $verification)<tr><td><strong>{{ $verification->user->first_name }} {{ $verification->user->last_name }}</strong><br><small>{{ $verification->user->email }}</small></td><td>{{ $verification->created_at->format('d.m.Y H:i') }}</td><td><a href="{{ route('admin.verifications.file',[$verification,'front']) }}">Vorderseite</a>@if($verification->document_back_path) · <a href="{{ route('admin.verifications.file',[$verification,'back']) }}">Rückseite</a>@endif</td><td><form method="post" action="{{ route('admin.verifications.review',$verification) }}" class="stack-form">@csrf<select name="status"><option value="accepted">Akzeptieren</option><option value="rejected">Ablehnen</option></select><textarea name="admin_comment" rows="2" placeholder="Kommentar"></textarea><button class="btn primary">Speichern</button></form></td></tr>
@empty<tr><td colspan="4" class="empty">Keine offenen Verifizierungen.</td></tr>@endforelse
</tbody></table></div>{{ $verifications->links() }}
@endsection
