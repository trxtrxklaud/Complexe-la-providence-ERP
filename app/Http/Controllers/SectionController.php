<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sections = Section::with('level')
            ->when($request->filled('level_id'), function ($query) use ($request) {
                $query->where('level_id', $request->integer('level_id'));
            })
            ->withCount(['enrollments as active_enrollments_count' => function ($q) {
                $q->where('status', 'active');
            }])
            ->orderBy('level_id')
            ->orderBy('name')
            ->get();

        return response()->json($sections);
    }

    public function store(StoreSectionRequest $request): JsonResponse
    {
        $section = Section::create($request->validated());
        $section->active_enrollments_count = 0;

        return response()->json($section, 201);
    }

    public function update(UpdateSectionRequest $request, Section $section): JsonResponse
    {
        $data = $request->validated();

        $currentCount = $section->enrollments()->where('status', 'active')->count();
        if (isset($data['capacity']) && $data['capacity'] < $currentCount) {
            return response()->json([
                'message' => "لا يمكن جعل السعة أقل من عدد التلاميذ المسجّلين حالياً ({$currentCount}).",
            ], 422);
        }

        $section->update($data);

        return response()->json($section->fresh());
    }

    public function destroy(Section $section): JsonResponse
    {
        if ($section->enrollments()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف قسم يحتوي على تسجيلات تلاميذ.',
            ], 422);
        }

        $section->delete();

        return response()->json(['message' => 'تم حذف القسم.']);
    }
}
