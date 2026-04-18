@extends('layouts.app')

@section('title', $appTitle.' – '.$pageTitle)

@section('intro')
    <section class="page-intro">
        <div class="container">
            <h1>{{ $pageTitle }}</h1>
        </div>
    </section>
@endsection

@section('content')
    <div class="info">
        <article class="card" style="max-width: 840px;">
            {!! $content !!}
        </article>
    </div>
@endsection
