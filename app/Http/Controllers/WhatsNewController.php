<?php

namespace App\Http\Controllers;

use App\Support\ReleaseNotes;
use Illuminate\Http\Request;

class WhatsNewController extends Controller
{
    public function index()
    {
        $versions = collect(ReleaseNotes::all())->keys()->take(12)
            ->mapWithKeys(fn ($v) => [$v => ReleaseNotes::for($v)]);

        return view('whats-new', ['versions' => $versions, 'current' => ReleaseNotes::currentVersion()]);
    }

    public function seen(Request $request)
    {
        $request->user()->forceFill(['whats_new_seen' => ReleaseNotes::currentVersion()])->save();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }
}
