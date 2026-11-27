<?php

namespace App\Http\Controllers\Vpn;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * "Download CFBTEL VPN for Mac": sends the browser straight to the installer (.pkg, Apple silicon and Intel)
 * of the newest CFBTEL VPN for Mac release on GitHub, so the links on the staff page never go stale.
 */
class MacAppController extends Controller
{
    public function download()
    {
        $repo = (string) config('services.vpn_macos.repo', 'cfbtel/cfbtel-vpn-macos');

        return redirect()->away($this->latestInstaller($repo) ?? "https://github.com/{$repo}/releases");
    }

    /** The installer URL of the newest release that has one, or null. */
    private function latestInstaller(string $repo): ?string
    {
        $key = 'vpn.macos.installer.' . md5($repo);
        if (is_string($cached = Cache::get($key))) {
            return $cached;
        }
        try {
            $response = Http::timeout(8)->acceptJson()
                ->withHeaders(['User-Agent' => 'PFCentral'])
                ->get("https://api.github.com/repos/{$repo}/releases", ['per_page' => 10]);
        } catch (\Throwable) {
            return null;
        }
        if (!$response->successful()) {
            return null;
        }
        foreach ((array) $response->json() as $release) {
            if (!empty($release['draft'])) {
                continue;
            }
            foreach ((array) ($release['assets'] ?? []) as $asset) {
                $url = (string) ($asset['browser_download_url'] ?? '');
                if (preg_match('/^CFBTEL-VPN-[\d.]+\.pkg$/', (string) ($asset['name'] ?? '')) && str_starts_with($url, 'https://github.com/')) {
                    Cache::put($key, $url, 3600); // GitHub lists the newest release first
                    return $url;
                }
            }
        }
        return null;
    }
}
