@extends('layouts.app')
@section('title','Systemeinstellungen')
@section('content')
<div class="page-head"><span class="eyebrow">Administration</span><h1>Systemeinstellungen</h1><p>Zentrale Plattformparameter ohne Codeänderung verwalten.</p></div>
<div class="panel" style="max-width:760px">
<form method="post" action="{{ route('admin.settings.update') }}" class="form-grid">@csrf @method('PUT')
<label>Plattformname<input name="site_name" value="{{ $settings['site_name'] }}" required></label>
<label>Mindestauszahlung (€)<input type="number" min="0" step="0.01" name="minimum_payout" value="{{ $settings['minimum_payout'] }}" required></label>
<label class="full">Support-E-Mail<input type="email" name="support_email" value="{{ $settings['support_email'] }}"></label>
<label class="check full"><input type="checkbox" name="proof_reminders_enabled" value="1" @checked($settings['proof_reminders_enabled'])><span>Automatische Erinnerungen bei fehlenden Tagesnachweisen aktivieren</span></label>
<label class="check full"><input type="checkbox" name="email_notifications_enabled" value="1" @checked($settings['email_notifications_enabled'])><span>Benachrichtigungen zusätzlich per E-Mail versenden</span></label>
<div class="full"><button class="btn primary">Einstellungen speichern</button></div>
</form>
</div>
@endsection
