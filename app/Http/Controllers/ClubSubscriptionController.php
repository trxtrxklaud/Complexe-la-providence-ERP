<?php

namespace App\Http\Controllers;

use App\Models\ClubSubscription;
use App\Services\ClubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ClubSubscriptionController extends Controller
{
    public function __construct(private readonly ClubService $clubService) {}

    public function index(Request $request): JsonResponse
    {
        $subscriptions = ClubSubscription::with([
            'student:id,first_name,last_name,student_code',
            'club:id,name,monthly_fee',
            'academicYear:id,name',
        ])
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('club_id'), fn ($q) => $q->where('club_id', $request->integer('club_id')))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($subscriptions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'start_date' => ['nullable', 'date'],
            'monthly_fee_override' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $subscription = $this->clubService->subscribeStudent(
                (int) $data['student_id'],
                (int) $data['club_id'],
                (int) $data['academic_year_id'],
                $data['start_date'] ?? null,
                isset($data['monthly_fee_override']) ? (float) $data['monthly_fee_override'] : null
            );

            return response()->json($subscription, 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(ClubSubscription $subscription): JsonResponse
    {
        $subscription->update(['status' => 'cancelled', 'end_date' => now()->toDateString()]);

        return response()->json(['message' => 'تم إلغاء اشتراك التلميذ في النادي']);
    }

    public function exclude(Request $request, ClubSubscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $excluded = $this->clubService->excludeStudent(
            $subscription,
            $request->user()?->id ?? 1,
            $data['reason'] ?? null
        );

        return response()->json([
            'message' => 'تم استبعاد التلميذ من متابعة النادي لهذه السنة الدراسية دون حذف بياناته أو مدفوعاته القديمة',
            'subscription' => $excluded,
        ]);
    }

    public function restore(ClubSubscription $subscription): JsonResponse
    {
        $restored = $this->clubService->restoreStudent($subscription);

        return response()->json([
            'message' => 'تمت إعادة التلميذ لمتابعة معلوم النادي',
            'subscription' => $restored,
        ]);
    }
}
