@extends('layouts.app')
@section('title',$offer->exists?'Angebot bearbeiten':'Neues Angebot')
@section('content')
<div class="page-head"><div><span class="eyebrow">Angebotseditor</span><h1>{{ $offer->exists?'Angebot bearbeiten':'Neues Angebot erstellen' }}</h1><p>Vergütung, Laufzeit, Verfügbarkeit, Bild, Nachweise, Zusatzoptionen und eigene Eingabefelder lassen sich ohne Code konfigurieren.</p></div></div>

<form method="post" enctype="multipart/form-data" action="{{ $offer->exists?route('admin.offers.update',$offer):route('admin.offers.store') }}" class="editor-grid" data-option-editor>
@csrf @if($offer->exists)@method('PUT')@endif

<section class="panel">
<h2>Grunddaten</h2>
<div class="form-grid">
<label>Titel<input name="title" value="{{ old('title',$offer->title) }}" required></label>
<label>Kategorie<select name="category_id" required>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id',$offer->category_id)==$category->id)>{{ $category->name }}</option>@endforeach</select></label>
<label class="full">Kurzbeschreibung<input name="short_description" value="{{ old('short_description',$offer->short_description) }}"></label>
<label class="full">Beschreibung<textarea name="description" rows="6">{{ old('description',$offer->description) }}</textarea></label>
<label>Grundvergütung (€)<input type="number" step="0.01" min="0" name="base_compensation" value="{{ old('base_compensation',$offer->base_compensation??40) }}" required></label>
<label>Dauer (Tage)<input type="number" min="1" name="duration_days" value="{{ old('duration_days',$offer->duration_days??5) }}" required></label>
<label>Mindestdauer/Tag (Min.)<input type="number" min="0" max="1440" name="minimum_minutes_per_day" value="{{ old('minimum_minutes_per_day',$offer->minimum_minutes_per_day??480) }}" required></label>
<label>Nachweise/Tag<input type="number" min="0" max="20" name="proofs_per_day" value="{{ old('proofs_per_day',$offer->proofs_per_day??2) }}" required></label>
<label>Versandfrist (Std.)<input type="number" min="1" name="shipping_deadline_hours" value="{{ old('shipping_deadline_hours',$offer->shipping_deadline_hours??24) }}" required></label>
<label>Max. Aufträge<input type="number" min="1" name="capacity" value="{{ old('capacity',$offer->capacity) }}" placeholder="unbegrenzt"></label>
<label>Verfügbar ab<input type="datetime-local" name="available_from" value="{{ old('available_from',$offer->available_from?->format('Y-m-d\TH:i')) }}"></label>
<label>Verfügbar bis<input type="datetime-local" name="available_until" value="{{ old('available_until',$offer->available_until?->format('Y-m-d\TH:i')) }}"></label>
<label class="full">Angebotsbild<input type="file" name="image" accept="image/jpeg,image/png,image/webp"><small>Wird beim Upload neu gerendert und von Metadaten bereinigt.</small></label>
@if($offer->image_path)<div class="full"><img src="{{ asset('storage/'.$offer->image_path) }}" alt="{{ $offer->title }}" style="max-width:320px;border-radius:16px"></div>@endif
<label class="check"><input type="checkbox" name="requires_precheck" value="1" @checked(old('requires_precheck',$offer->requires_precheck))><span>Vorprüfung erforderlich</span></label>
<label class="check"><input type="checkbox" name="active" value="1" @checked(old('active',$offer->active))><span>Angebot aktiv veröffentlichen</span></label>
<label class="full">Regeln – eine pro Zeile<textarea name="rules_text" rows="6">{{ old('rules_text',is_array($offer->rules)?implode("
",$offer->rules):'') }}</textarea></label>
</div>
</section>

<section class="panel">
<div class="section-head"><div><span class="eyebrow">Extras</span><h2>Zusatzoptionen & Abhängigkeiten</h2></div><button type="button" class="btn secondary" data-add-option>+ Option</button></div>
<p class="muted">Bei „benötigt“ bzw. „schließt aus“ die exakten Namen anderer Optionen kommasepariert eintragen.</p>
<div data-options>
@foreach(old('options',$optionRows) as $i=>$option)
<div class="option-editor-row" style="grid-template-columns:1.1fr .7fr .7fr .7fr .7fr 1.1fr 1.1fr auto">
<input type="hidden" name="options[{{ $i }}][id]" value="{{ $option['id']??'' }}">
<label>Name<input name="options[{{ $i }}][name]" value="{{ $option['name']??'' }}"></label>
<label>Aufpreis (€)<input type="number" step="0.01" name="options[{{ $i }}][price_delta]" value="{{ $option['price_delta']??0 }}"></label>
<label>Extra-Nachweise/Tag<input type="number" min="0" name="options[{{ $i }}][extra_proofs_per_day]" value="{{ $option['extra_proofs_per_day']??0 }}"></label>
<label>Extra-Tage<input type="number" min="0" name="options[{{ $i }}][extra_duration_days]" value="{{ $option['extra_duration_days']??0 }}"></label>
<label>Mindestlaufzeit<input type="number" min="0" name="options[{{ $i }}][min_duration_days]" value="{{ $option['min_duration_days']??0 }}"></label>
<label>Benötigt Optionen<input name="options[{{ $i }}][requires_names]" value="{{ $option['requires_names']??'' }}"></label>
<label>Schließt Optionen aus<input name="options[{{ $i }}][excludes_names]" value="{{ $option['excludes_names']??'' }}"></label>
<label class="check"><input type="checkbox" name="options[{{ $i }}][required]" value="1" @checked($option['required']??false)><span>Pflicht</span></label>
<label class="wide" style="grid-column:1/-2">Beschreibung<input name="options[{{ $i }}][description]" value="{{ $option['description']??'' }}"></label>
<button type="button" class="icon-btn" data-remove-option>×</button>
</div>
@endforeach
</div>
</section>

<section class="panel" data-field-editor>
<div class="section-head"><div><span class="eyebrow">Formular</span><h2>Dynamische Angebotsfelder</h2></div><button type="button" class="btn secondary" data-add-field>+ Feld</button></div>
<p class="muted">Diese Angaben muss die Anbieterin bei der Annahme ausfüllen. Die Werte werden im Auftrag eingefroren.</p>
<div data-fields>
@foreach(old('fields',$fieldRows) as $i=>$field)
<div class="option-editor-row">
<input type="hidden" name="fields[{{ $i }}][id]" value="{{ $field['id']??'' }}">
<label>Bezeichnung<input name="fields[{{ $i }}][label]" value="{{ $field['label']??'' }}"></label>
<label>Typ<select name="fields[{{ $i }}][type]">@foreach(['text'=>'Text','textarea'=>'Textbereich','number'=>'Zahl','select'=>'Auswahl','radio'=>'Radio','checkbox'=>'Ja/Nein'] as $type=>$label)<option value="{{ $type }}" @selected(($field['type']??'text')===$type)>{{ $label }}</option>@endforeach</select></label>
<label class="wide">Hilfetext<input name="fields[{{ $i }}][help_text]" value="{{ $field['help_text']??'' }}"></label>
<label class="wide">Auswahlwerte – eine pro Zeile<textarea name="fields[{{ $i }}][options_text]" rows="3">{{ $field['options_text']??'' }}</textarea></label>
<label class="check"><input type="checkbox" name="fields[{{ $i }}][required]" value="1" @checked($field['required']??false)><span>Pflichtfeld</span></label>
<button type="button" class="icon-btn" data-remove-field>×</button>
</div>
@endforeach
</div>
</section>

<div class="editor-actions"><button class="btn primary">Angebot speichern</button></div>
</form>
@endsection
