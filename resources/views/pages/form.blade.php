@extends('layouts.app')

@section('title', $appTitle.' – '.$topic->name($lang))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ $topic->name($lang) }}</h1>
            <div class="topic-summary">{!! $topic->renderedSummary($lang) !!}</div>
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
            @if ($field->type->isDisplayOnly())
                {{-- Display-only Info block: operator-authored formatted text,
                     rendered like a topic summary. No label, input, or error. --}}
                <div class="form-group topic-summary">{!! $field->renderedDescription($lang) !!}</div>
                @continue
            @endif
            @php
                $name = (string) $field->id;
                // A Files field registers rules on both the bare key
                // (required|array) and the per-file key ({id}.{index}), so a
                // rejected second file reports under `{id}.1`. Look at the bare
                // key first, then any indexed one, so every rejected file
                // surfaces a message instead of only the first.
                $fieldError = $field->type === \App\Enums\FieldType::Files
                    ? ($errors->first($name) ?: $errors->first($name.'.*'))
                    : $errors->first($name);
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
                        {{-- `value` is required: with none, browsers post the
                             literal "on", which the field's `boolean` rule
                             rejects — making a ticked box unsubmittable. --}}
                        <input type="checkbox" id="field-{{ $field->id }}" name="{{ $name }}" value="1"
                               @checked(old($name))
                               @if ($field->required) required @endif>
                        @break
                    @case('files')
                        <input type="file" id="field-{{ $field->id }}" name="{{ $name }}[]" multiple
                               data-file-input
                               @if ($field->required) required @endif>
                        <span class="desc">{{ __('upload_limit', ['mb' => $maxUploadMb]) }}</span>
                        <span class="desc">{{ __('upload_metadata_warning') }}</span>
                        @break
                    @case('file')
                        <input type="file" id="field-{{ $field->id }}" name="{{ $name }}"
                               data-file-input
                               @if ($field->required) required @endif>
                        <span class="desc">{{ __('upload_limit', ['mb' => $maxUploadMb]) }}</span>
                        <span class="desc">{{ __('upload_metadata_warning') }}</span>
                        @break
                    @case('audio')
                        {{-- Oral reporting: record in-browser (MediaRecorder) or
                             upload an existing audio file. Both feed the same
                             single-file input the server stores. --}}
                        <div data-audio-recorder>
                            <div class="audio-recorder-controls" style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                                {{-- audio-recorder.js reads its status strings off
                                     these attributes; without them it falls back
                                     to hardcoded English. --}}
                                <button type="button" class="button button-small" data-audio-record hidden
                                        data-recording-label="{{ __('audio_recording') }}"
                                        data-denied-label="{{ __('audio_denied') }}">
                                    ● {{ __('audio_record') }}
                                </button>
                                <button type="button" class="button button-small button-danger" data-audio-stop hidden>
                                    ■ {{ __('audio_stop') }}
                                </button>
                                <span class="desc" data-audio-status></span>
                            </div>
                            <audio data-audio-preview controls hidden style="margin-top:.5rem; width:100%;"></audio>
                            <input type="file" id="field-{{ $field->id }}" name="{{ $name }}"
                                   accept="audio/*" data-file-input data-audio-input
                                   @if ($field->required) required @endif>
                        </div>
                        <span class="desc">{{ __('audio_hint') }}</span>
                        <span class="desc">{{ __('upload_limit', ['mb' => $maxUploadMb]) }}</span>
                        <span class="desc">{{ __('upload_metadata_warning') }}</span>
                        @break
                    @default
                        <input type="{{ $field->type->value }}" id="field-{{ $field->id }}" name="{{ $name }}"
                               value="{{ old($name) }}"
                               @if ($field->required) required @endif>
                @endswitch
                @if ($field->description($lang))
                    <span class="desc">{{ $field->description($lang) }}</span>
                @endif
                @if ($fieldError !== '')
                    <span class="field-error">{{ $fieldError }}</span>
                @endif
            </div>
        @endforeach

        @if ($topic->require_login)
            <div class="form-group">
                <label>{{ __('contact') }}</label>
                <p>{{ auth()->user()->email ?? auth()->user()->uid }}</p>
                <span class="desc">{{ __('login_required_identity_note') }}</span>
            </div>
        @else
            <div class="form-group">
                <label for="email">{{ __('emailLabel') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="you@example.com">
                <span class="desc">{{ __('emailDescription') }}</span>
                @error('email')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </div>
        @endif

        <hr>

        <div class="flex-between">
            <a class="button button-ghost" href="{{ route('home') }}">← {{ __('back') }}</a>
            <button type="submit">{{ __('send') }}</button>
        </div>
    </form>

    <script src="{{ asset('js/file-input.js') }}" defer></script>
    <script src="{{ asset('js/form-draft.js') }}" defer></script>
    <script src="{{ asset('js/audio-recorder.js') }}" defer></script>
@endsection
