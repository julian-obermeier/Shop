@extends('layouts.app')
@section('title','Systemeinstellungen')
@section('content')
<div class="page-head"><span class="eyebrow">Administration</span><h1>Systemeinstellungen</h1><p>Zentrale Plattformparameter ohne Codeänderung verwalten.</p></div>
<div class="panel" style="max-width:860px">
<form method="post" action="{{ route('admin.settings.update') }}" class="form-grid">@csrf @method('PUT')
<label>Plattformname<input name="site_name" value="{{ $settings['site_name'] }}" required></label>
<label>Mindestauszahlung (€)<input type="number" min="0" step="0.01" name="minimum_payout" value="{{ $settings['minimum_payout'] }}" required></label>
<label class="full">Support-E-Mail<input type="email" name="support_email" value="{{ $settings['support_email'] }}"></label>
<label class="check full"><input type="checkbox" name="proof_reminders_enabled" value="1" @checked($settings['proof_reminders_enabled'])><span>Automatische Erinnerungen bei fehlenden Tagesnachweisen aktivieren</span></label>
<label class="check full"><input type="checkbox" name="email_notifications_enabled" value="1" @checked($settings['email_notifications_enabled'])><span>Benachrichtigungen zusätzlich per E-Mail versenden</span></label>

<div class="full"><hr><h2>Aufbewahrung privater Dateien</h2><p class="notice">Technische Standardfristen. Vor Produktivbetrieb müssen die Werte an die tatsächlich erforderlichen gesetzlichen, steuerlichen und vertraglichen Aufbewahrungspflichten angepasst werden.</p></div>
<label>Identitätsunterlagen (Tage)<input type="number" min="1" max="3650" name="identity_retention_days" value="{{ $settings['identity_retention_days'] }}" required></label>
<label>Vorprüfungsbilder (Tage)<input type="number" min="1" max="3650" name="precheck_retention_days" value="{{ $settings['precheck_retention_days'] }}" required></label>
<label>Nachweisbilder (Tage)<input type="number" min="1" max="3650" name="proof_retention_days" value="{{ $settings['proof_retention_days'] }}" required></label>
<label>Nachrichtenanhänge (Tage)<input type="number" min="1" max="3650" name="message_attachment_retention_days" value="{{ $settings['message_attachment_retention_days'] }}" required></label>

<div class="full"><button class="btn primary">Einstellungen speichern</button></div>
</form>
</div>
@endsection
