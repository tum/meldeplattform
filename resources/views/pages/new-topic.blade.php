@extends('layouts.app')

@section('title', $appTitle.' – '.__('create_topic'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('create_topic') }}</h1>
            <p>{{ __('create_topic_summary') }}</p>
        </div>
    </section>
@endsection

@section('content')
    <form id="topic-form" class="card">
        @csrf
        <div id="topic-form-body">
            <p class="muted">Loading…</p>
        </div>

        <hr>
        <div class="flex-between">
            <a class="button button-ghost" href="{{ route('home') }}">{{ __('back') }}</a>
            <div>
                <span id="save-status" class="muted" style="margin-right: 1rem;"></span>
                <button type="submit">{{ __('create_topic') }}</button>
            </div>
        </div>
    </form>

    @php
        // Field-type options shown in the admin editor's Type <select>.
        // Order matches the FieldType enum so new cases here are caught by
        // PHPStan if the enum changes shape.
        $fieldTypes = collect(\App\Enums\FieldType::cases())->map(fn ($c) => [
            'value' => $c->value,
            'label' => __('field_type_'.$c->value),
        ])->values()->all();

        $newTopicBootstrap = [
            'topicID' => $topic?->id ?? 0,
            'fieldTypes' => $fieldTypes,
            'tr' => [
                'general' => __('general'),
                'questions' => __('questions'),
                'admins' => __('admins'),
                'admins_desc' => __('admins_desc'),
                'contactEmail' => __('contactEmail'),
                'summary' => __('summary'),
                'de' => __('german'),
                'en' => __('english'),
                'type' => __('type'),
                'description' => __('description'),
                'required' => __('required'),
                'delete' => __('delete'),
                'addField' => __('add_field'),
                'addAdmin' => __('add_admin'),
                'addOption' => __('add_option'),
                'selectOpts' => __('select_options_label'),
                'savedOk' => __('topic_saved'),
                'savedErr' => __('topic_saved_error'),
                'name' => 'Name',
            ],
        ];
    @endphp
    {{-- Non-executable JSON data island: browsers never parse this as script,
         so it is allowed under a CSP that forbids 'unsafe-inline' for scripts. --}}
    <script type="application/json" id="new-topic-bootstrap">@json($newTopicBootstrap)</script>
    <script src="{{ asset('js/new-topic.js') }}" defer></script>
@endsection
