<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreschoolShortCycleRequest;
use App\Models\Enrollment;
use App\Services\PreschoolShortCycleService;
use Illuminate\Http\JsonResponse;

/**
 * @deprecated Legacy standalone controller for preschool short cycle.
 * Collection is now unified within CollectionController using manual_amounts.
 * Kept for backwards compatibility and concurrency test verification until post-UAT cleanup.
 */
class PreschoolShortCycleController extends Controller
{
    public function __construct(
        private readonly PreschoolShortCycleService $shortCycleService,
    ) {}

    public function preview(Enrollment $enrollment): JsonResponse
    {
        return response()->json($this->shortCycleService->preview($enrollment->id));
    }

    public function collect(PreschoolShortCycleRequest $request): JsonResponse
    {
        try {
            $result = $this->shortCycleService->collect(
                $request->validated(),
                (int) $request->user()->id
            );

            return response()->json($result, 201);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}