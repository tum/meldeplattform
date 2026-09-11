{{-- Themed paginator, registered as the default in AppServiceProvider.
     Laravel's stock template is written against Tailwind, which this app
     doesn't ship, so its chevron SVGs (sized only by `w-5 h-5`) render at
     full width. This one only relies on app.css. --}}
@if ($paginator->hasPages())
    <nav class="pagination" aria-label="{{ __('pagination_nav') }}">
        <p class="pagination-summary">
            @if ($paginator->firstItem() !== null)
                {!! __('pagination_summary', [
                    'from' => '<strong>'.$paginator->firstItem().'</strong>',
                    'to' => '<strong>'.$paginator->lastItem().'</strong>',
                    'total' => '<strong>'.$paginator->total().'</strong>',
                ]) !!}
            @else
                {!! __('pagination_total', ['total' => '<strong>'.$paginator->total().'</strong>']) !!}
            @endif
        </p>

        <ul class="pagination-list">
            {{-- Previous --}}
            <li>
                @if ($paginator->onFirstPage())
                    <span class="pagination-link is-disabled" aria-disabled="true" aria-label="{{ __('pagination_previous') }}">
                        @include('partials.pagination-chevron', ['direction' => 'left'])
                    </span>
                @else
                    <a class="pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination_previous') }}">
                        @include('partials.pagination-chevron', ['direction' => 'left'])
                    </a>
                @endif
            </li>

            {{-- Page numbers; "..." gaps arrive as strings from the paginator. --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="pagination-ellipsis" aria-hidden="true">…</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li>
                            @if ($page == $paginator->currentPage())
                                <span class="pagination-link is-current" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="pagination-link" href="{{ $url }}" aria-label="{{ __('pagination_goto', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            {{-- Next --}}
            <li>
                @if ($paginator->hasMorePages())
                    <a class="pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination_next') }}">
                        @include('partials.pagination-chevron', ['direction' => 'right'])
                    </a>
                @else
                    <span class="pagination-link is-disabled" aria-disabled="true" aria-label="{{ __('pagination_next') }}">
                        @include('partials.pagination-chevron', ['direction' => 'right'])
                    </span>
                @endif
            </li>
        </ul>
    </nav>
@endif
