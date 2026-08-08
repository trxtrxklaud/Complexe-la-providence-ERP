<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Services\ClubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClubController extends Controller
{
    public function __construct(private readonly ClubService $clubService) {}

    public function index(Request $request): JsonResponse
    {
        $clubs = Club::with(['levels:id,name,code', 'feeCategory:id,name'])
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return response()->json($clubs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'fee_category_id' => ['nullable', 'integer', 'exists:fee_categories,id'],
            'monthly_fee' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'level_ids' => ['nullable', 'array'],
            'level_ids.*' => ['integer', 'exists:levels,id'],
        ]);

        $club = $this->clubService->createClub($data, $data['level_ids'] ?? []);

        return response()->json($club, 201);
    }

    public function show(Club $club): JsonResponse
    {
        return response()->json($club->load(['levels:id,name,code', 'feeCategory:id,name']));
    }

    public function update(Request $request, Club $club): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'fee_category_id' => ['nullable', 'integer', 'exists:fee_categories,id'],
            'monthly_fee' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'level_ids' => ['nullable', 'array'],
            'level_ids.*' => ['integer', 'exists:levels,id'],
        ]);

        $updated = $this->clubService->updateClub($club, $data, $data['level_ids'] ?? null);

        return response()->json($updated);
    }

    public function destroy(Club $club): JsonResponse
    {
        if ($club->subscriptions()->exists()) {
            $club->update(['is_active' => false]);
            return response()->json(['message' => 'تم تعطيل النادي لوجود اشتراكات مرتبطة به']);
        }

        $club->delete();
        return response()->json(null, 204);
    }
}
