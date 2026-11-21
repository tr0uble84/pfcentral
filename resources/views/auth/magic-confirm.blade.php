<x-guest-layout>
    <h1>Continue to {{ config('brand.name') }}</h1>
    <p class="pfc-lead">You opened a one-time sign-in link for <strong style="color:#fff">{{ $user->email }}</strong>.</p>

    <form method="POST" action="{{ $action }}" style="margin-top: 24px">
        @csrf
        <button type="submit" class="pfc-btn">
            <span>Continue as {{ $user->name }}</span>
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 4.5 21 12l-7.5 7.5M21 12H3"/></svg>
        </button>
        <a href="{{ route('login') }}" class="pfc-btn-ghost">Not you? Back to sign in</a>
    </form>
    <p style="margin-top: 18px; font-size: 13px; color: #94A3B8">The link works once and expires 15 minutes after it was sent.</p>
</x-guest-layout>
