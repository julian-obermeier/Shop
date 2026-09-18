@extends('layouts.app')
@section('title','Anbieterinnen')
@section('content')
<div class="page-head"><span class="eyebrow">Administration</span><h1>Anbieterinnen</h1><p>Benutzerakten, Status, Verifizierung und Einschränkungen.</p></div>
<form class="filters" method="get"><input name="q" value="{{ request('q') }}" placeholder="Name oder E-Mail"><select name="status"><option value="">Alle Status</option><option value="active" @selected(request('status')==='active')>Aktiv</option><option value="inactive" @selected(request('status')==='inactive')>Deaktiviert</option><option value="deleted" @selected(request('status')==='deleted')>Gelöscht/anonymisiert</option></select><button class="btn secondary">Filtern</button></form>
<div class="table-card"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Verifiziert</th><th>Status</th><th>Aufträge</th><th></th></tr></thead><tbody>
@foreach($users as $user)<tr><td><strong>{{ $user->first_name }} {{ $user->last_name }}</strong></td><td>{{ $user->email }}</td><td>{{ $user->hasVerifiedEmail()?'Ja':'Nein' }}</td><td><span class="status {{ $user->status==='active'?'accepted':'rejected' }}">{{ strtoupper($user->status) }}</span></td><td>{{ $user->orders_count }}</td><td><a href="{{ route('admin.users.show',$user) }}">Akte öffnen →</a></td></tr>@endforeach
</tbody></table></div>{{ $users->links() }}
@endsection
