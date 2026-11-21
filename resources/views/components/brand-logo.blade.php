{{-- CFBTEL logo with the product name. tone="light" for dark backgrounds; compact for narrow spaces like the sidebar. --}}
@props(['tone' => 'light', 'wordmark' => true, 'compact' => false])
@php $h = $compact ? '1.1rem' : '1.5rem'; @endphp
<span {{ $attributes }} style="display:inline-flex;align-items:center;gap:{{ $compact ? '.55rem' : '.75rem' }};user-select:none">
    <img src="{{ asset('images/cfbtel-logo.svg') }}" alt="{{ config('brand.owner') }}" style="height:{{ $h }};width:auto;flex-shrink:0">
    @if ($wordmark)
        <span aria-hidden="true" style="width:1px;height:{{ $h }};background:{{ $tone === 'light' ? 'rgba(255,255,255,.2)' : 'rgba(14,34,56,.2)' }}"></span>
        <span style="font-weight:700;font-size:{{ $compact ? '.9rem' : '1rem' }};letter-spacing:-.01em;white-space:nowrap;color:{{ $tone === 'light' ? '#fff' : '#0E2238' }}">{{ config('brand.name') }}</span>
    @endif
</span>
