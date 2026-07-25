<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLevelRequest;
use App\Http\Requests\UpdateLevelRequest;
use App\Models\Level;
use Illuminate\Http\JsonResponse;

class LevelController extends Controller
{
    public function index(): JsonResponse
    {
        $levels = Level::with(['sections' => function ($query) {
            $query->withCount(['enrollments as active_enrollments_count' => function ($q) {
                $q->where('status', 'active');
            }])->orderBy('name');
        }])
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json($levels);
    }

    public function store(StoreLevelRequest $request): JsonResponse
    {
        $level = Level::create($request->validated());

        return response()->json($level->load('sections'), 201);
    }

    public function update(UpdateLevelRequest $request, Level $level): JsonResponse
    {
        $level->update($request->validated());

        return response()->json($level->fresh()->load('sections'));
    }

    public function destroy(Level $level): JsonResponse
    {
        if ($level->enrollments()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف مستوى مرتبط بتسجيلات تلاميذ.',
            ], 422);
        }

        if ($level->sections()->exists()) {
            return response()->json([
                'message' => 'احذف أقسام هذا المستوى أولاً.',
            ], 422);
        }

        $level->delete();

        return response()->json(['message' => 'تم حذف المستوى.']);
    }
}
