@php $microsoft = \App\Services\Auth\MspSignIn::enabled(); @endphp
<x-guest-layout>
    <h1>Sign in to {{ config('brand.name') }}</h1>
    <p class="pfc-lead">Manage your clients' pfSense and OPNsense firewalls, VPN access and security from one place.</p>

    @if (session('status'))
        <div class="pfc-note ok" role="status">{{ session('status') }}</div>
    @endif
    @error('microsoft')
        <div class="pfc-note err" role="alert">{{ $message }}</div>
    @enderror
    @error('magic')
        <div class="pfc-note err" role="alert">{{ $message }}</div>
    @enderror

    @if ($microsoft)
        <div style="margin-top: 24px">
            <a href="{{ route('login.microsoft') }}" class="pfc-btn-ms">
                <svg viewBox="0 0 23 23" aria-hidden="true"><path fill="#f25022" d="M1 1h10v10H1z"/><path fill="#7fba00" d="M12 1h10v10H12z"/><path fill="#00a4ef" d="M1 12h10v10H1z"/><path fill="#ffb900" d="M12 12h10v10H12z"/></svg>
                Sign in with Microsoft 365
            </a>
        </div>
        <div class="pfc-or" aria-hidden="true">or with email</div>
    @endif

    <form method="POST" action="{{ route('login') }}" id="pfc-login" @unless($microsoft) style="margin-top: 24px" @endunless>
        @csrf
        <div>
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required @unless($microsoft) autofocus @endunless autocomplete="username" inputmode="email" placeholder="you@company.com">
            @error('email')<p class="pfc-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <div class="pfc-row">
                <label for="password">Password</label>
                @if (Route::has('password.request'))
                    <a class="pfc-link" href="{{ route('password.request') }}">Forgot password?</a>
                @endif
            </div>
            <div class="pfc-pass">
                <input id="password" type="password" name="password" required autocomplete="current-password">
                <button type="button" id="pfc-toggle" aria-label="Show password" aria-pressed="false">
                    <svg class="pfc-eye" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <svg class="pfc-eye-off pfc-hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                </button>
            </div>
            @error('password')<p class="pfc-error">{{ $message }}</p>@enderror
        </div>

        <label for="remember_me" class="pfc-check">
            <input id="remember_me" type="checkbox" name="remember"> Keep me signed in on this device
        </label>

        <button type="submit" class="pfc-btn" id="pfc-submit">
            <svg class="pfc-spin pfc-hidden" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".25" stroke-width="4"/><path fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
            <span>Sign in</span>
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 4.5 21 12l-7.5 7.5M21 12H3"/></svg>
        </button>

        <button type="submit" formaction="{{ route('login.magic') }}" formnovalidate class="pfc-btn-ghost">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
            Email me a one-time sign-in link
        </button>
    </form>

    <script>
        (function () {
            var form = document.getElementById('pfc-login');
            var toggle = document.getElementById('pfc-toggle');
            var pass = document.getElementById('password');
            toggle.addEventListener('click', function () {
                var show = pass.type === 'password';
                pass.type = show ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                toggle.querySelector('.pfc-eye').classList.toggle('pfc-hidden', show);
                toggle.querySelector('.pfc-eye-off').classList.toggle('pfc-hidden', !show);
            });
            form.addEventListener('submit', function (e) {
                if (e.submitter && e.submitter.id !== 'pfc-submit') return;
                var btn = document.getElementById('pfc-submit');
                btn.disabled = true;
                btn.querySelector('.pfc-spin').classList.remove('pfc-hidden');
                btn.querySelector('span').textContent = 'Signing in…';
            });
        })();
    </script>
</x-guest-layout>
