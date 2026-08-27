<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\ManualStudentDebt;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Services\FamilyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class FamilyController extends Controller
{
    public function __construct(
        protected FamilyService $familyService
    ) {}

    /**
     * قائمة العائلات.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 25);
        $page = (int) $request->query('page', 1);

        $result = $this->familyService->listFamilies($search, $perPage, $page);

        return response()->json($result);
    }

    /**
     * تفاصيل عائلة محددة وأبنائها ورسومهم المستحقة (يدعم ID رقمي أو معرف هاتف مثل phone_50247050).
     */
    public function show(string|int $id): JsonResponse
    {
        try {
            $family = $this->familyService->getFamilyDetails($id);
            return response()->json($family);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'العائلة غير موجودة'], 404);
        } catch (\Exception $e) {
            report($e);
            return response()->json(['message' => 'تعذّر تحميل بيانات العائلة: ' . $e->getMessage()], 404);
        }
    }

    /**
     * الديون القديمة لأبناء العائلة (قراءة فقط) — لبانر التنبيه غير الحاجب
     * عند فتح الاستخلاص العائلي.
     *
     * يقرأ manual_student_debts فقط: لا ينشئ Payment ولا CashTransaction،
     * لا يعدّل أي رصيد، ولا يستدعي collectFamilyPayment. المتبقّي يُشتقّ من
     * مجموع توزيعات الدفعات غير الملغاة (قاعدة ManualStudentDebt::outstanding
     * نفسها) باستعلام مجمّع واحد دون تكرار الاستعلام لكل دين أو تلميذ.
     */
    public function oldDebts(string|int $id): JsonResponse
    {
        try {
            $students = $this->resolveFamilyStudentsForOldDebts($id);

            if ($students->isEmpty()) {
                return response()->json(['message' => 'العائلة غير موجودة'], 404);
            }

            $studentIds = $students->pluck('id')->all();

            // الديون السارية فقط: غير ملغاة وبحالة غير مسدّدة.
            $debts = ManualStudentDebt::query()
                ->whereIn('student_id', $studentIds)
                ->whereNull('cancelled_at')
                ->where('status', '!=', ManualStudentDebt::STATUS_PAID)
                ->get(['id', 'student_id', 'original_amount']);

            // التحصيل من الدفعات غير الملغاة فقط — استعلام مجمّع واحد لكل الديون.
            $collectedByDebt = [];
            if ($debts->isNotEmpty()) {
                $rows = PaymentAllocation::query()
                    ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                    ->whereNull('payments.cancelled_at')
                    ->whereIn('payment_allocations.manual_student_debt_id', $debts->pluck('id'))
                    ->groupBy('payment_allocations.manual_student_debt_id')
                    ->selectRaw('payment_allocations.manual_student_debt_id as debt_id, SUM(payment_allocations.amount_allocated) as collected')
                    ->get();

                foreach ($rows as $row) {
                    $collectedByDebt[(int) $row->debt_id] = (float) $row->collected;
                }
            }

            $studentsMap = [];
            $total = 0.0;

            foreach ($debts as $debt) {
                // المتبقّي = الأصل − المحصَّل دون نزول تحت الصفر
                // (صياغة محمولة بين SQLite وMySQL بدل GREATEST).
                $outstanding = round(max(0, (float) $debt->original_amount - ($collectedByDebt[(int) $debt->id] ?? 0.0)), 2);

                // الدين المستوفى بالكامل (متبقٍّ صفر أو أقل) لا يُعرض.
                if ($outstanding <= 0) {
                    continue;
                }

                $studentId = (int) $debt->student_id;

                if (! isset($studentsMap[$studentId])) {
                    $student = $students->firstWhere('id', $studentId);
                    $studentsMap[$studentId] = [
                        'student_id' => $studentId,
                        'student_name' => $student ? trim($student->first_name . ' ' . $student->last_name) : '—',
                        'student_code' => $student?->student_code,
                        'has_debt' => true,
                        'amount' => 0.0,
                        'debts_count' => 0,
                    ];
                }

                $studentsMap[$studentId]['amount'] = round($studentsMap[$studentId]['amount'] + $outstanding, 2);
                $studentsMap[$studentId]['debts_count']++;
                $total = round($total + $outstanding, 2);
            }

            return response()->json([
                'students' => $studentsMap ?: new \stdClass,
                'count' => count($studentsMap),
                'total' => $total,
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'تعذّر تحميل تنبيه الديون القديمة للعائلة'], 500);
        }
    }

    /**
     * حلّ أبناء العائلة بنفس قواعد FamilyService::resolveFamilyStudents
     * (معرف Guardian رقمي، أو هاتف منقّح phone_XXX، أو معرف تلميذ student_X).
     * نسخة مصغّرة للقراءة فقط كي لا يُعدَّل FamilyService.
     */
    protected function resolveFamilyStudentsForOldDebts(string|int $familyKey): \Illuminate\Support\Collection
    {
        if (is_numeric($familyKey)) {
            $guardian = Guardian::with('students')->find((int) $familyKey);

            if ($guardian && $guardian->students->isNotEmpty()) {
                return $guardian->students;
            }
        }

        $phoneKey = str_replace('phone_', '', (string) $familyKey);
        $phoneDigits = FamilyService::normalizePhone($phoneKey);

        if ($phoneDigits) {
            return Student::query()->get()->filter(function (Student $student) use ($phoneDigits) {
                return FamilyService::normalizePhone($student->guardian_phone) === $phoneDigits
                    || FamilyService::normalizePhone($student->mother_phone) === $phoneDigits;
            })->values();
        }

        if (str_starts_with((string) $familyKey, 'student_') || is_numeric($familyKey)) {
            $studentId = (int) str_replace('student_', '', (string) $familyKey);

            return Student::whereKey($studentId)->get();
        }

        return collect();
    }

    /**
     * تنفيذ الاستخلاص الجماعي للعائلة وإصدار وصل موحد برقم واحد (يدعم ID رقمي أو معرف هاتف).
     */
    public function collect(Request $request, string|int $id): JsonResponse
    {
        $request->validate([
            'payment_date' => ['required', 'date'],
            'method' => ['required', 'string', 'in:cash,bank_transfer,check,card'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'students_allocations' => ['nullable', 'array'],
            'students_allocations.*.student_id' => ['required_with:students_allocations', 'integer'],
            'students_allocations.*.enrollment_id' => ['required_with:students_allocations', 'integer'],
            'students_allocations.*.months' => ['nullable', 'array'],
            'students_allocations.*.club_items' => ['nullable', 'array'],
            'students_allocations.*.prior_allocations' => ['nullable', 'array'],
            // Backwards compatibility validation if allocations array is sent
            'allocations' => ['nullable', 'array'],
        ]);

        try {
            $payload = array_merge($request->all(), [
                'family_id' => $id,
                'idempotency_key' => $request->header('Idempotency-Key') ?: ($request->input('idempotency_key') ?: null),
            ]);

            $receipt = $this->familyService->collectFamilyPayment($payload, (int) auth()->id());

            return response()->json([
                'message' => 'تم تسجيل الاستخلاص العائلي بنجاح',
                'receipt' => $receipt,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'العائلة غير موجودة'], 404);
        } catch (\Exception $e) {
            report($e);
            return response()->json(['message' => 'فشل تنفيذ الاستخلاص العائلي: ' . $e->getMessage()], 500);
        }
    }
}
