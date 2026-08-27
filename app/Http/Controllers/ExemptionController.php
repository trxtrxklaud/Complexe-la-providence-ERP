<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExemptionResource;
use App\Models\ClubMonthlyDiscount;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\MonthlyDiscount;
use App\Services\ClubMonthlyDiscountService;
use App\Services\MonthlyDiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ExemptionController extends Controller
{
    public function __construct(
        private readonly MonthlyDiscountService $monthlyService,
        private readonly ClubMonthlyDiscountService $clubService
    ) {}

    /**
     * GET /api/exemptions
     * استرجاع كافة الإعفاءات لجميع التلاميذ مع الفلترة وإحصائيات عامة.
     */
    public function allExemptions(Request $request): JsonResponse
    {
        $academicYearId = $request->query('academic_year_id');
        $sectionId = $request->query('section_id');
        $discountType = $request->query('discount_type');
        $status = $request->query('status', 'all');
        $search = $request->query('search');

        $monthlyQuery = MonthlyDiscount::query()
            ->with(['creator:id,first_name,last_name', 'canceller:id,first_name,last_name', 'academicYear', 'enrollment.student', 'enrollment.section', 'enrollment.level']);

        $clubQuery = ClubMonthlyDiscount::query()
            ->with(['subscription.club', 'subscription.student.enrollments.section', 'subscription.student.enrollments.level', 'creator:id,first_name,last_name', 'canceller:id,first_name,last_name', 'academicYear']);

        if ($academicYearId) {
            $monthlyQuery->where('academic_year_id', $academicYearId);
            $clubQuery->where('academic_year_id', $academicYearId);
        }

        if ($sectionId) {
            $monthlyQuery->whereHas('enrollment', fn($q) => $q->where('section_id', $sectionId));
            $clubQuery->whereHas('subscription.student.enrollments', function ($q) use ($sectionId, $academicYearId) {
                $q->where('section_id', $sectionId);
                if ($academicYearId) {
                    $q->where('academic_year_id', $academicYearId);
                }
            });
        }

        if ($discountType && $discountType !== 'all') {
            $monthlyQuery->where('discount_type', $discountType);
            $clubQuery->where('discount_type', $discountType);
        }

        if ($status === 'active') {
            $monthlyQuery->whereNull('cancelled_at');
            $clubQuery->whereNull('cancelled_at');
        } elseif ($status === 'cancelled') {
            $monthlyQuery->whereNotNull('cancelled_at');
            $clubQuery->whereNotNull('cancelled_at');
        }

        if ($search && trim($search) !== '') {
            $monthlyQuery->whereHas('enrollment.student', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('student_code', 'like', "%{$search}%");
            });
            $clubQuery->whereHas('subscription.student', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('student_code', 'like', "%{$search}%");
            });
        }

        $monthlyDiscounts = $monthlyQuery->orderByDesc('id')->get();
        $clubDiscounts = $clubQuery->orderByDesc('id')->get();

        $all = $monthlyDiscounts->concat($clubDiscounts)->sortByDesc('id')->values();

        $totalActiveTuitionWaiver = $monthlyDiscounts->whereNull('cancelled_at')->where('discount_type', 'full_waiver')->count();
        $totalActiveClubWaiver = $clubDiscounts->whereNull('cancelled_at')->where('discount_type', 'full_waiver')->count();
        $totalActiveHumanitarian = $monthlyDiscounts->whereNull('cancelled_at')->where('discount_type', 'humanitarian_fixed')->count()
            + $clubDiscounts->whereNull('cancelled_at')->where('discount_type', 'humanitarian_fixed')->count();

        return response()->json([
            'stats' => [
                'total_exemptions' => $all->whereNull('cancelled_at')->count(),
                'tuition_full_waivers' => $totalActiveTuitionWaiver,
                'club_full_waivers' => $totalActiveClubWaiver,
                'humanitarian_discounts' => $totalActiveHumanitarian,
            ],
            'data' => ExemptionResource::collection($all),
        ]);
    }

    /**
     * GET /api/enrollments/{enrollment}/exemptions
     * استرجاع كافة إعفاءات التلميذ (الشهري والنوادي) لنفس التسجيل.
     */
    public function index(Enrollment $enrollment): JsonResponse
    {
        $monthlyDiscounts = $enrollment->monthlyDiscounts()
            ->with(['creator:id,first_name,last_name', 'canceller:id,first_name,last_name', 'academicYear', 'enrollment.student'])
            ->orderByDesc('id')
            ->get();

        $studentId = $enrollment->student_id;
        $academicYearId = $enrollment->academic_year_id;

        $clubDiscounts = ClubMonthlyDiscount::query()
            ->whereHas('subscription', function ($q) use ($studentId, $academicYearId) {
                $q->where('student_id', $studentId)
                  ->where('academic_year_id', $academicYearId);
            })
            ->with(['subscription.club', 'subscription.student', 'creator:id,first_name,last_name', 'canceller:id,first_name,last_name', 'academicYear'])
            ->orderByDesc('id')
            ->get();

        $all = $monthlyDiscounts->concat($clubDiscounts)->sortByDesc('id')->values();

        return response()->json([
            'enrollment_id'      => $enrollment->id,
            'student_id'         => $enrollment->student_id,
            'data'               => ExemptionResource::collection($all),
            'monthly_exemptions' => ExemptionResource::collection($monthlyDiscounts),
            'club_exemptions'    => ExemptionResource::collection($clubDiscounts),
        ]);
    }

    /**
     * POST /api/enrollments/{enrollment}/exemptions/monthly
     * تسجيل إعفاء شهري دراسي للتلميذ.
     */
    public function storeMonthly(Request $request, Enrollment $enrollment): JsonResponse
    {
        $data = $request->validate([
            'discount_type'  => ['required', 'string', 'in:full_waiver,humanitarian_fixed,normal_monthly'],
            'monthly_amount' => ['nullable', 'numeric', 'min:0'],
            'start_month'    => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'end_month'      => ['required', 'string', 'regex:/^\d{4}-\d{2}$/', 'gte:start_month'],
            'reason'         => ['required', 'string', 'max:500'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ], [
            'discount_type.required' => 'نوع الإعفاء إجباري',
            'discount_type.in'       => 'نوع الإعفاء غير صحيح',
            'start_month.required'   => 'شهر البداية إجباري',
            'start_month.regex'      => 'صيغة شهر البداية يجب أن تكون YYYY-MM',
            'end_month.required'     => 'شهر النهاية إجباري',
            'end_month.regex'        => 'صيغة شهر النهاية يجب أن تكون YYYY-MM',
            'end_month.gte'          => 'شهر النهاية يجب أن يكون مساوياً أو بعد شهر البداية',
            'reason.required'        => 'سبب الإعفاء إجباري',
            'reason.max'             => 'سبب الإعفاء طويل جداً',
        ]);

        if ($data['start_month'] > $data['end_month']) {
            return response()->json(['message' => 'شهر النهاية يجب أن يكون مساوياً أو بعد شهر البداية'], 422);
        }

        if ($data['discount_type'] !== MonthlyDiscount::TYPE_FULL_WAIVER && (! isset($data['monthly_amount']) || (float) $data['monthly_amount'] <= 0)) {
            return response()->json(['message' => 'مبلغ التخفيض إجباري ويجب أن يكون أكبر من الصفر'], 422);
        }

        try {
            $discount = $this->monthlyService->createDiscount(
                $enrollment->id,
                $data['discount_type'],
                isset($data['monthly_amount']) ? (float) $data['monthly_amount'] : null,
                $data['reason'],
                $data['notes'] ?? null,
                $request->user()?->id,
                $data['start_month'],
                $data['end_month']
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'تم تسجيل الإعفاء الشهري بنجاح',
            'data'    => new ExemptionResource($discount->fresh(['creator', 'enrollment.student', 'academicYear'])),
        ], 201);
    }

    /**
     * POST /api/enrollments/{enrollment}/exemptions/club/{clubSubscription}
     * تسجيل إعفاء نادي مدرسي للتلميذ.
     */
    public function storeClub(Request $request, Enrollment $enrollment, ClubSubscription $clubSubscription): JsonResponse
    {
        if ($clubSubscription->student_id !== $enrollment->student_id) {
            return response()->json(['message' => 'اشتراك النادي المحدد لا يتبع هذا التلميذ'], 422);
        }

        $data = $request->validate([
            'discount_type'  => ['required', 'string', 'in:full_waiver,humanitarian_fixed'],
            'monthly_amount' => ['nullable', 'numeric', 'min:0'],
            'start_month'    => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'end_month'      => ['required', 'string', 'regex:/^\d{4}-\d{2}$/', 'gte:start_month'],
            'reason'         => ['required', 'string', 'max:500'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ], [
            'discount_type.required' => 'نوع الإعفاء إجباري',
            'discount_type.in'       => 'نوع الإعفاء غير صحيح',
            'start_month.required'   => 'شهر البداية إجباري',
            'start_month.regex'      => 'صيغة شهر البداية يجب أن تكون YYYY-MM',
            'end_month.required'     => 'شهر النهاية إجباري',
            'end_month.regex'        => 'صيغة شهر النهاية يجب أن تكون YYYY-MM',
            'end_month.gte'          => 'شهر النهاية يجب أن يكون مساوياً أو بعد شهر البداية',
            'reason.required'        => 'سبب الإعفاء إجباري',
            'reason.max'             => 'سبب الإعفاء طويل جداً',
        ]);

        if ($data['start_month'] > $data['end_month']) {
            return response()->json(['message' => 'شهر النهاية يجب أن يكون مساوياً أو بعد شهر البداية'], 422);
        }

        if ($data['discount_type'] !== ClubMonthlyDiscount::TYPE_FULL_WAIVER && (! isset($data['monthly_amount']) || (float) $data['monthly_amount'] <= 0)) {
            return response()->json(['message' => 'مبلغ التخفيض إجباري ويجب أن يكون أكبر من الصفر'], 422);
        }

        try {
            $discount = $this->clubService->createDiscount(
                $clubSubscription->id,
                $data['discount_type'],
                isset($data['monthly_amount']) ? (float) $data['monthly_amount'] : null,
                $data['reason'],
                $data['notes'] ?? null,
                $request->user()?->id,
                $data['start_month'],
                $data['end_month']
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'تم تسجيل إعفاء النادي بنجاح',
            'data'    => new ExemptionResource($discount->fresh(['creator', 'subscription.student', 'subscription.club', 'academicYear'])),
        ], 201);
    }

    /**
     * DELETE /api/exemptions/monthly/{monthlyDiscount}
     * إلغاء إعفاء شهري دراسي مع توثيق سبب الإلغاء.
     */
    public function cancelMonthly(Request $request, MonthlyDiscount $monthlyDiscount): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'سبب الإلغاء إجباري',
            'reason.max'      => 'سبب الإلغاء طويل جداً',
        ]);

        try {
            $cancelled = $this->monthlyService->cancel($monthlyDiscount->id, $data['reason'], $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'تم إلغاء الإعفاء بنجاح',
            'data'    => new ExemptionResource($cancelled->fresh(['creator', 'canceller', 'enrollment.student', 'academicYear'])),
        ]);
    }

    /**
     * DELETE /api/exemptions/club/{clubMonthlyDiscount}
     * إلغاء إعفاء نادي مع توثيق سبب الإلغاء.
     */
    public function cancelClub(Request $request, ClubMonthlyDiscount $clubMonthlyDiscount): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'سبب الإلغاء إجباري',
            'reason.max'      => 'سبب الإلغاء طويل جداً',
        ]);

        try {
            $cancelled = $this->clubService->cancel($clubMonthlyDiscount->id, $data['reason'], $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'تم إلغاء إعفاء النادي بنجاح',
            'data'    => new ExemptionResource($cancelled->fresh(['creator', 'canceller', 'subscription.student', 'subscription.club', 'academicYear'])),
        ]);
    }
}
