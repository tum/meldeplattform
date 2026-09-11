{{-- 16px stroke chevron for the paginator's previous/next controls. Sized
     by .pagination-link svg in app.css, not by the markup. --}}
<svg aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    @if ($direction === 'left')
        <path d="M10 3 5 8l5 5"/>
    @else
        <path d="m6 3 5 5-5 5"/>
    @endif
</svg>
