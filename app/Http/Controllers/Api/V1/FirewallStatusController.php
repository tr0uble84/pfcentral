<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Firewall;
use App\Services\Integration\FirewallStatusSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FirewallStatusController extends Controller
{
    private function authorized(Request $request): Builder
    {
        return Firewall::query()->when(! $request->attributes->get('integration_all_companies'),
            fn ($query) => $query->where('company_id', $request->attributes->get('integration_company_id')));
    }

    public function show(Request $request, string $firewallId, FirewallStatusSnapshot $snapshots): JsonResponse
    {
        $firewall = $this->authorized($request)->where('id', $firewallId)->firstOrFail(['id', 'company_id', 'os_type']);

        return response()->json(['data' => $snapshots->read($firewall)]);
    }

    public function index(Request $request, FirewallStatusSnapshot $snapshots): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'company_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        if (isset($input['company_id']) && ! $request->attributes->get('integration_all_companies')) {
            abort_unless((int) $input['company_id'] === $request->attributes->get('integration_company_id'), 403);
        }
        $page = $this->authorized($request)
            ->when(isset($input['company_id']), fn ($query) => $query->where('company_id', $input['company_id']))
            ->orderBy('id')->paginate($input['per_page'] ?? 25, ['id', 'company_id', 'os_type'], 'page', $input['page'] ?? 1);

        return response()->json(['data' => $snapshots->readMany($page->getCollection()), 'meta' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }
}
