<?php

namespace App\Http\Controllers\Vpn;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * "Download CFBTEL VPN for Windows": sends the browser straight to the installer (.msi) of the
 * newest CFBTEL VPN release on GitHub, so the links on the staff page never go stale.
 */
class WindowsAppController extends Controller
{
    private const ARCHES = ['amd64', 'arm64', 'x86'];

    public function download(Request $request)
    {
        $repo = (string) config('services.vpn_windows.repo', 'cfbtel/cfbtel-vpn-windows');
        $arch = in_array($request->query('arch'), self::ARCHES, true) ? $request->query('arch') : 'amd64';
        $assets = $this->latestInstallers($repo);

        return redirect()->away($assets[$arch] ?? "https://github.com/{$repo}/releases");
    }

    /** @return array<string,string> arch => installer URL of the newest release (test builds included). */
    private function latestInstallers(string $repo): array
    {
        $key = 'vpn.windows.installers.' . md5($repo);
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }
        try {
            $response = Http::timeout(8)->acceptJson()
                ->withHeaders(['User-Agent' => 'PFCentral'])
                ->get("https://api.github.com/repos/{$repo}/releases", ['per_page' => 10]);
        } catch (\Throwable) {
            return [];
        }
        if (!$response->successful()) {
            return [];
        }
        $found = [];
        foreach ((array) $response->json() as $release) {
            if (!empty($release['draft'])) {
                continue;
            }
            foreach ((array) ($release['assets'] ?? []) as $asset) {
                if (preg_match('/^cfbtelvpn-(amd64|arm64|x86)-[\d.]+\.msi$/', (string) ($asset['name'] ?? ''), $m)
                    && str_starts_with((string) ($asset['browser_download_url'] ?? ''), 'https://github.com/')) {
                    $found[$m[1]] = $asset['browser_download_url'];
                }
            }
            if ($found) {
                break; // GitHub lists the newest release first
            }
        }
        if ($found) {
            Cache::put($key, $found, 3600);
        }
        return $found;
    }
}
