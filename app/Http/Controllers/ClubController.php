<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Section;
use App\Services\ClubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Fetch distinct sections attached to active clubs for reporting filters.
     * Accessible by manage_payments users.
     */
    public function clubSections(Request $request): JsonResponse
    {
        $sections = Section::select('sections.id', 'sections.name', 'sections.level_id', 'levels.name as level_name', 'levels.code as level_code')
            ->join('club_sections', 'club_sections.section_id', '=', 'sections.id')
            ->join('clubs', function ($join) {
                $join->on('clubs.id', '=', 'club_sections.club_id')
                    ->where('clubs.is_active', true);
            })
            ->join('levels', 'levels.id', '=', 'sections.level_id')
            ->distinct()
            ->orderBy('levels.id')
            ->orderBy('sections.name')
            ->get();

        // format as requested: { data: [...] }
        return response()->json(['data' => $sections]);
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
            return response()->json(['message' => 'تعطيل النادي لوجود اشتراكات مرتبطة به']);
        }

        $club->delete();
        return response()->json(null, 204);
    }
}
