@extends('layouts.app')
@section('title','Admin-Team')
@section('content')
<div class="page-head"><span class="eyebrow">Sicherheit & Rollen</span><h1>Admin-Team</h1><p>Administrationskonten mit rollenbasierten Rechten. Für alle Konten ist beim Login E-Mail-2FA verpflichtend.</p></div>
<div class="wallet-layout">
<section class="table-card"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Status</th><th>Bearbeiten</th></tr></thead><tbody>
@foreach($admins as $admin)
<tr>
<td><strong>{{ $admin->first_name }} {{ $admin->last_name }}</strong></td>
<td>{{ $admin->email }}</td>
<td><span class="status">{{ strtoupper($admin->role) }}</span></td>
<td><span class="status {{ $admin->status==='active'?'accepted':'rejected' }}">{{ strtoupper($admin->status) }}</span></td>
<td>
@if($admin->id!==auth()->id() && !($admin->role==='superadmin' && auth()->user()->role!=='superadmin'))
<form method="post" action="{{ route('admin.admin-users.update',$admin) }}" class="stack-form">@csrf @method('PUT')
<select name="role">
@if(auth()->user()->role==='superadmin')<option value="superadmin" @selected($admin->role==='superadmin')>Superadmin</option>@endif
<option value="admin" @selected($admin->role==='admin')>Admin</option>
<option value="staff" @selected($admin->role==='staff')>Staff</option>
<option value="accounting" @selected($admin->role==='accounting')>Accounting</option>
</select>
<select name="status"><option value="active" @selected($admin->status==='active')>Aktiv</option><option value="suspended" @selected($admin->status==='suspended')>Gesperrt</option></select>
<button class="btn secondary">Speichern</button>
</form>
@else<span class="muted">Geschützt</span>@endif
</td>
</tr>
@endforeach
</tbody></table></section>
<aside class="panel"><h2>Administrationskonto anlegen</h2>
<form method="post" action="{{ route('admin.admin-users.store') }}" class="stack-form">@csrf
<label>Vorname<input name="first_name" required></label>
<label>Nachname<input name="last_name" required></label>
<label>E-Mail<input type="email" name="email" required></label>
<label>Rolle<select name="role">@if(auth()->user()->role==='superadmin')<option value="superadmin">Superadmin</option>@endif<option value="admin">Admin</option><option value="staff">Staff</option><option value="accounting">Accounting</option></select></label>
<label>Passwort<input type="password" name="password" minlength="12" required></label>
<label>Passwort wiederholen<input type="password" name="password_confirmation" minlength="12" required></label>
<button class="btn primary wide">Konto anlegen</button>
</form></aside>
</div>
@endsection
