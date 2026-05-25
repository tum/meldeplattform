@extends('layouts.app')

@section('title', $appTitle.' – '.$topic->name($lang))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="/" class="crumb">{{ __('back') }}</a>
            <h1>{{ $topic->name($lang) }}</h1>
            <p>{{ $topic->summary($lang) }}</p>
        </div>
    </section>
@endsection

@section('content')
    @if ($errors->any())
        <div class="alert alert-error">{{ __('please_fix_errors') }}</div>
    @endif

    <div class="alert alert-info draft-restored" data-draft-note hidden>{{ __('draft_restored') }}</div>

    <form method="post" action="{{ route('form.submit') }}" enctype="multipart/form-data" class="card"
          data-draft-key="form-draft:{{ $topic->id }}" style="max-width: 820px;">
        @csrf
        <input type="hidden" name="topic" value="{{ $topic->id }}">

        @foreach ($topic->fields as $field)
            @php
                $name = (string) $field->id;
                $errorKey = $field->type->isFileUpload() && $field->type === \App\Enums\FieldType::Files
                    ? $name.'.0'
                    : $name;
            @endphp
            <div class="form-group">
                <label for="field-{{ $field->id }}">
                    {{ $field->name($lang) }}
                    @if ($field->required)
                        <span style="color: var(--tum-red);" aria-label="required">*</span>
                    @endif
                </label>

                @switch($field->type->value)
                    @case('textarea')
                        <textarea id="field-{{ $field->id }}" name="{{ $name }}"
                                  @if ($field->required) required @endif>{{ old($name) }}</textarea>
                        @break
                    @case('select')
                        <select id="field-{{ $field->id }}" name="{{ $name }}"
                                @if ($field->required) required @endif>
                            <option value="" disabled @if (! old($name)) selected @endif>—</option>
                            @foreach (($field->choices ?? []) as $choice)
                                <option value="{{ $choice }}" @selected(old($name) === $choice)>{{ $choice }}</option>
                            @endforeach
                        </select>
                        @break
                    @case('checkbox')
                        <input type="checkbox" id="field-{{ $field->id }}" name="{{ $name }}"
                               @checked(old($name))
                               @if ($field->required) required @endif>
                        @break
                    @case('files')
                        <input type="file" id="field-{{ $field->id }}" name="{{ $name }}[]" multiple
                               data-file-input
                               @if ($field->required) required @endif>
                        <span class="desc">{{ __('upload_limit', ['mb' => $maxUploadMb]) }}</span>
                        @break
                    @case('file')
                        <input type="file" id="field-{{ $field->id }}" name="{{ $name }}"
                               data-file-input
                               @if ($field->required) required @endif>
                        <span class="desc">{{ __('upload_limit', ['mb' => $maxUploadMb]) }}</span>
                        @break
                    @default
                        <input type="{{ $field->type->value }}" id="field-{{ $field->id }}" name="{{ $name }}"
                               value="{{ old($name) }}"
                               @if ($field->required) required @endif>
                @endswitch
                @if ($field->description($lang))
                    <span class="desc">{{ $field->description($lang) }}</span>
                @endif
                @error($errorKey)
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </div>
        @endforeach

        <div class="form-group">
            <label for="email">{{ __('emailLabel') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="you@example.com">
            <span class="desc">{{ __('emailDescription') }}</span>
            @error('email')
                <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <hr>

        <div class="flex-between">
            <a class="button button-ghost" href="/">← {{ __('back') }}</a>
            <button type="submit">{{ __('send') }}</button>
        </div>
    </form>

    <script src="{{ asset('js/file-input.js') }}" defer></script>
    <script src="{{ asset('js/form-draft.js') }}" defer></script>
@endsection
