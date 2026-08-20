<?php

namespace App\Http\Controllers;

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
