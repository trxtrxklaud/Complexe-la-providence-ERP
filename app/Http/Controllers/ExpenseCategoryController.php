<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = ExpenseCategory::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->withCount('expenses')
            ->orderBy('name')
            ->get();

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120', Rule::unique('expense_categories', 'name')],
            'is_active' => ['nullable', 'boolean'],
            'notes'     => ['nullable', 'string'],
        ]);
        $data['is_active'] = $data['is_active'] ?? true;

        return response()->json(ExpenseCategory::create($data), 201);
    }

    public function show(ExpenseCategory $expenseCategory): JsonResponse
    {
        return response()->json($expenseCategory->loadCount('expenses'));
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:120', Rule::unique('expense_categories', 'name')->ignore($expenseCategory->id)],
            'is_active' => ['nullable', 'boolean'],
            'notes'     => ['nullable', 'string'],
        ]);

        $expenseCategory->update($data);

        return response()->json($expenseCategory->fresh());
    }

    /**
     * لا يُسمح بحذف صنف مرتبط بمصاريف مسجّلة، حتى لا تفقد التقارير التاريخية تصنيفها.
     * البديل المتاح هو تعطيل الصنف بـ is_active = false.
     */
    public function destroy(ExpenseCategory $expenseCategory): JsonResponse
    {
        $used = $expenseCategory->expenses()->count();

        if ($used > 0) {
            return response()->json([
                'message' => 'لا يمكن حذف هذا الصنف لارتباطه بـ ' . $used . ' مصروف. يمكنك تعطيله بدل حذفه.',
            ], 422);
        }

        $expenseCategory->delete();

        return response()->json(['message' => 'تم حذف الصنف']);
    }
}
