@extends('layouts.app')

@section('title', $appTitle.' – '.__('process_title'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <h1>{{ __('process_title') }}</h1>
            <p>{{ __('process_intro') }}</p>
        </div>
    </section>
@endsection

@section('content')
    {{-- The deadlines come from config so the graphic never drifts from what
         the reminder job actually enforces. --}}
    <ol class="process-steps" aria-label="{{ __('process_steps_label') }}">
        @foreach ([
            ['process_step1_title', 'process_step1_text', []],
            ['process_step2_title', 'process_step2_text', ['days' => $ackDays]],
            ['process_step3_title', 'process_step3_text', []],
            ['process_step4_title', 'process_step4_text', ['days' => $feedbackDays]],
            ['process_step5_title', 'process_step5_text', []],
        ] as [$title, $text, $params])
            <li class="process-step">
                <span class="process-step-number" aria-hidden="true">{{ $loop->iteration }}</span>
                <div>
                    <h2>{{ __($title, $params) }}</h2>
                    <p>{{ __($text, $params) }}</p>
                </div>
            </li>
        @endforeach
    </ol>

    <div class="info mt-5">
        <article class="card" style="max-width: 840px;">
            {!! $content !!}
        </article>
    </div>
@endsection
