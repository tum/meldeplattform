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
    <form method="post" action="{{ route('form.submit') }}" enctype="multipart/form-data" class="card" style="max-width: 820px;">
        @csrf
        <input type="hidden" name="topic" value="{{ $topic->id }}">

        <div class="form-group">
            <label for="email">{{ __('emailLabel') }}</label>
            <input id="email" type="email" name="email" placeholder="you@example.com">
            <span class="desc">{{ __('emailDescription') }}</span>
        </div>

        @foreach ($topic->fields as $field)
            <div class="form-group">
                <label for="field-{{ $field->id }}">
                    {{ $field->name($lang) }}
                    @if ($field->required)
                        <span style="color: var(--tum-red);" aria-label="required">*</span>
                    @endif
                </label>

                @switch($field->type)
                    @case('textarea')
                        <textarea id="field-{{ $field->id }}" name="{{ $field->id }}"
                                  @if ($field->required) required @endif></textarea>
                        @break
                    @case('select')
                        <select id="field-{{ $field->id }}" name="{{ $field->id }}"
                                @if ($field->required) required @endif>
                            <option value="" disabled selected>—</option>
                            @foreach (($field->choices ?? []) as $choice)
                                <option value="{{ $choice }}">{{ $choice }}</option>
                            @endforeach
                        </select>
                        @break
                    @case('checkbox')
                        <input type="checkbox" id="field-{{ $field->id }}" name="{{ $field->id }}"
                               @if ($field->required) required @endif>
                        @break
                    @case('files')
                        <input type="file" id="field-{{ $field->id }}" name="{{ $field->id }}[]" multiple
                               @if ($field->required) required @endif>
                        @break
                    @case('file')
                        <input type="file" id="field-{{ $field->id }}" name="{{ $field->id }}"
                               @if ($field->required) required @endif>
                        @break
                    @default
                        <input type="{{ $field->type }}" id="field-{{ $field->id }}" name="{{ $field->id }}"
                               @if ($field->required) required @endif>
                @endswitch
                @if ($field->description($lang))
                    <span class="desc">{{ $field->description($lang) }}</span>
                @endif
            </div>
        @endforeach

        <hr>

        <div class="flex-between">
            <a class="button button-ghost" href="/">← {{ __('back') }}</a>
            <button type="submit">{{ __('send') }}</button>
        </div>
    </form>
@endsection
