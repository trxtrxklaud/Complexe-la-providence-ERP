<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        $items = Employee::orderBy('last_name')->orderBy('first_name')->get();
        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'default_salary' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['is_active'] = $data['is_active'] ?? true;
        $emp = Employee::create($data);
        return response()->json($emp, 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json($employee);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'default_salary' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);
        $employee->update($data);
        return response()->json($employee->fresh());
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $hasRelatedRecords = $employee->salaries()->exists()
            || $employee->advances()->exists()
            || $employee->repayments()->exists();

        if ($hasRelatedRecords) {
            return response()->json([
                'message' => 'لا يمكن حذف موظف لديه رواتب أو سلف أو سجلات مالية مرتبطة',
            ], 422);
        }

        $employee->delete();
        return response()->json(['message' => 'تم الحذف']);
    }
}
