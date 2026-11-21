<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\MagicLoginLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class MagicLoginController extends Controller
{
    public function send(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            // Generate signed URL (valid for 15 mins)
            // Signed without the host so the link still checks out behind a proxy or on another port.
            $url = url(URL::temporarySignedRoute(
                'login.magic.verify',
                now()->addMinutes(15),
                ['id' => $user->id],
                absolute: false
            ));

            Mail::to($user->email)->send(new MagicLoginLink($url));
        }

        // Fix HIGH-04: User Enumeration - Always return same message
        return back()->with('status', 'If an account exists for this email, we have sent a magic login link!');
    }

    /**
     * The link from the email only shows a "Continue" page. Mail scanners (Microsoft Defender Safe Links and
     * others) open links before the person does; signing in on GET let them use up the one-time link.
     */
    public function show(Request $request, $id)
    {
        if ($error = $this->problem($request)) {
            return redirect()->route('login')->withErrors(['magic' => $error]);
        }
        $user = User::find($id);
        if (!$user) {
            return redirect()->route('login')->withErrors(['magic' => 'This sign-in link is not valid. Ask for a new one below.']);
        }

        return view('auth.magic-confirm', ['user' => $user, 'action' => $request->getRequestUri()]);
    }

    public function verify(Request $request, $id)
    {
        if ($error = $this->problem($request)) {
            return redirect()->route('login')->withErrors(['magic' => $error]);
        }

        $signature = $request->query('signature');
        $cacheKey = 'magic_link_consumed_' . $signature;
        // An atomic insert prevents two simultaneous requests consuming one link.
        if (!Cache::add($cacheKey, true, now()->addMinutes(20))) {
            return redirect()->route('login')->withErrors(['magic' => 'This sign-in link was already used. Ask for a new one below.']);
        }

        $user = User::find($id);
        if (!$user) {
            return redirect()->route('login')->withErrors(['magic' => 'This sign-in link is not valid. Ask for a new one below.']);
        }

        if (!empty($user->two_factor_secret) && !is_null($user->two_factor_confirmed_at)) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended('/dashboard');
    }

    /** Why the link can't be used, or null when it can. */
    private function problem(Request $request): ?string
    {
        // Links sent before v0.12.1 were signed with the full address; accept both.
        $valid = $request->hasValidRelativeSignature() || $request->hasValidSignature();
        if (!$valid) {
            $expires = (int) $request->query('expires', 0);

            return $expires && $expires < time()
                ? 'This sign-in link has expired (links work for 15 minutes). Ask for a new one below.'
                : 'This sign-in link is not valid. Ask for a new one below.';
        }
        if (Cache::has('magic_link_consumed_' . $request->query('signature'))) {
            return 'This sign-in link was already used. Ask for a new one below.';
        }

        return null;
    }
}
