<?php

namespace App\Http\Controllers;

use App\Models\Salary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Salary::with([
            'employee:id,first_name,last_name',
            'academicYear:id,name',
        ])->latest('paid_at')->latest('id');

        if ($request->filled('academic_year_id')) {
            $q->where('academic_year_id', $request->integer('academic_year_id'));
        }
        if ($request->filled('employee_id')) {
            $q->where('employee_id', $request->integer('employee_id'));
        }

        return response()->json($q->paginate(min($request->integer('per_page', 20), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'paid_at' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['created_by'] = $request->user()?->id;
        $salary = Salary::create($data)->load(['employee:id,first_name,last_name', 'academicYear:id,name']);
        return response()->json($salary, 201);
    }

    public function show(Salary $salary): JsonResponse
    {
        return response()->json($salary->load(['employee', 'academicYear']));
    }

    public function update(Request $request, Salary $salary): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['sometimes', 'integer', 'exists:academic_years,id'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'period_from' => ['sometimes', 'date'],
            'period_to' => ['sometimes', 'date'],
            'paid_at' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);
        $salary->update($data);
        return response()->json($salary->fresh()->load(['employee:id,first_name,last_name', 'academicYear:id,name']));
    }

    public function destroy(Salary $salary): JsonResponse
    {
        $salary->delete();
        return response()->json(['message' => 'تم الحذف']);
    }
}
