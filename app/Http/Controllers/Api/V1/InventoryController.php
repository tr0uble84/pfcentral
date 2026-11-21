<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Firewall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class InventoryController extends Controller
{
    private const COMPANY_FIELDS = ['id', 'name', 'description', 'created_at', 'updated_at'];
    private const FIREWALL_FIELDS = ['id', 'company_id', 'name', 'os_type', 'netgate_id', 'description', 'created_at', 'updated_at'];

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'company_id' => $request->attributes->get('integration_company_id'),
            'all_companies' => $request->attributes->get('integration_all_companies'),
            'abilities' => $request->attributes->get('integration_all_companies')
                ? ['inventory:read', 'inventory:read:all'] : ['inventory:read'],
        ]]);
    }

    public function companies(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->companiesQuery($request)
            ->orderBy('id')
            ->get(self::COMPANY_FIELDS)]);
    }

    public function company(Request $request, string $companyId): JsonResponse
    {
        return response()->json(['data' => $this->companiesQuery($request)
            ->where('id', $companyId)->firstOrFail(self::COMPANY_FIELDS)]);
    }

    public function firewalls(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'company_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        if (isset($input['company_id']) && ! $request->attributes->get('integration_all_companies')) {
            abort_unless((int) $input['company_id'] === $request->attributes->get('integration_company_id'), 403);
        }
        $page = $this->firewallsQuery($request)
            ->when(isset($input['company_id']), fn ($query) => $query->where('company_id', $input['company_id']))
            ->orderBy('id')->paginate($input['per_page'] ?? 25, self::FIREWALL_FIELDS, 'page', $input['page'] ?? 1);

        // Return pagination numbers, without links derived from an untrusted Host header.
        return response()->json(['data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }

    public function firewall(Request $request, string $firewallId): JsonResponse
    {
        // Query the tenant first; do not use the existing unscoped dual-ID route binding.
        return response()->json(['data' => $this->firewallsQuery($request)
            ->where('id', $firewallId)->firstOrFail(self::FIREWALL_FIELDS)]);
    }

    public function openapi(): JsonResponse
    {
        return response()->json(json_decode(file_get_contents(base_path('docs/openapi-v1.json')), true, 512, JSON_THROW_ON_ERROR));
    }

    private function companiesQuery(Request $request): Builder
    {
        return Company::query()->when(! $request->attributes->get('integration_all_companies'),
            fn ($query) => $query->whereKey($request->attributes->get('integration_company_id')));
    }

    private function firewallsQuery(Request $request): Builder
    {
        return Firewall::query()->when(! $request->attributes->get('integration_all_companies'),
            fn ($query) => $query->where('company_id', $request->attributes->get('integration_company_id')));
    }
}
