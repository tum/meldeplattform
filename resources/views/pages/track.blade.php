@extends('layouts.app')

@section('title', $appTitle.' – '.__('track_title'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('track_title') }}</h1>
            <p class="muted">{{ __('track_intro') }}</p>
        </div>
    </section>
@endsection

@section('content')
    <form method="post" action="{{ route('report.track.submit') }}" class="card" style="max-width: 520px;">
        @csrf

        <div class="form-group">
            <label for="code">{{ __('track_code_label') }}</label>
            {{-- Receipt codes are uppercase hex (issueReceiptCode), so they
                 contain A–F: a numeric inputmode would leave mobile reporters
                 unable to type their own code. --}}
            <input type="text" name="code" id="code" required autofocus
                   autocomplete="off" autocapitalize="characters" spellcheck="false"
                   value="{{ old('code') }}">
            @error('code')
                <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="text-right mt-3">
            <button type="submit">{{ __('track_submit') }}</button>
        </div>
    </form>
@endsection
