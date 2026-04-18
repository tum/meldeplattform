@extends('layouts.app')

@section('title', 'Dev Login')

@section('intro')
    <section class="page-intro">
        <div class="container">
            <h1>Dev-Login</h1>
            <p>Lokaler Bypass ohne Shibboleth – nur aktiv, wenn nicht in Produktion.</p>
        </div>
    </section>
@endsection

@section('content')
    <div class="alert alert-warning">
        <strong>Achtung:</strong> Dieses Formular umgeht die SAML-Authentifizierung.
        In Produktion ist es deaktiviert.
    </div>

    <form method="post" action="/dev/login" class="card" style="max-width: 520px;">
        @csrf

        <div class="form-group">
            <label for="uid">UID</label>
            <input type="text" name="uid" id="uid" required autofocus
                   value="{{ old('uid', $suggestedAdmins[0] ?? 'ge25bof') }}"
                   list="uid-suggestions">
            @if ($suggestedAdmins)
                <datalist id="uid-suggestions">
                    @foreach ($suggestedAdmins as $u)
                        <option value="{{ $u }}">
                    @endforeach
                </datalist>
                <span class="desc">Konfigurierte globale Admin-UIDs: {{ implode(', ', $suggestedAdmins) }}</span>
            @else
                <span class="desc">Tipp: setze <code>MELDE_ADMIN_USERS</code> in <code>.env</code>, damit deine UID globaler Admin ist.</span>
            @endif
        </div>

        <div class="form-group">
            <label for="name">Name (optional)</label>
            <input type="text" name="name" id="name" value="{{ old('name', 'Dev User') }}">
        </div>

        <div class="form-group">
            <label for="email">E-Mail (optional)</label>
            <input type="email" name="email" id="email" value="{{ old('email', 'dev@example.com') }}">
        </div>

        <div class="flex-between">
            <a class="button button-ghost" href="/">← Zurück</a>
            <button type="submit">Login</button>
        </div>

        @if ($errors->any())
            <div class="alert alert-error mt-3">{{ $errors->first() }}</div>
        @endif
    </form>
@endsection
