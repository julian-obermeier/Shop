@extends('layouts.app')
@section('title','Kategorien')
@section('content')
<div class="page-head"><span class="eyebrow">Administration</span><h1>Kategorien</h1><p>Produktarten zentral verwalten, ohne den Anwendungscode zu ändern.</p></div>
<div class="wallet-layout">
<section class="table-card"><table><thead><tr><th>Name</th><th>Icon</th><th>Angebote</th><th>Status</th><th>Bearbeiten</th></tr></thead><tbody>
@foreach($categories as $category)<tr><td><strong>{{ $category->name }}</strong><br><small>{{ $category->slug }}</small></td><td style="font-size:24px">{{ $category->icon }}</td><td>{{ $category->offers_count }}</td><td><span class="status {{ $category->active?'accepted':'pending' }}">{{ $category->active?'AKTIV':'INAKTIV' }}</span></td><td><form method="post" action="{{ route('admin.categories.update',$category) }}" class="stack-form">@csrf @method('PUT')<input name="name" value="{{ $category->name }}" required><input name="icon" value="{{ $category->icon }}" placeholder="Icon"><label class="check"><input type="checkbox" name="active" value="1" @checked($category->active)><span>Aktiv</span></label><button class="btn secondary">Speichern</button></form></td></tr>@endforeach
</tbody></table></section>
<aside class="panel"><h2>Neue Kategorie</h2><form method="post" action="{{ route('admin.categories.store') }}" class="stack-form">@csrf<label>Name<input name="name" required></label><label>Icon<input name="icon" placeholder="z. B. 🧦"></label><label class="check"><input type="checkbox" name="active" value="1" checked><span>Aktiv</span></label><button class="btn primary wide">Kategorie anlegen</button></form></aside>
</div>
@endsection
