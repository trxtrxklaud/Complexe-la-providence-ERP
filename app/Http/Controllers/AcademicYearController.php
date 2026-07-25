<?php
namespace App\Http\Controllers;
use App\Models\AcademicYear;
use Illuminate\Http\JsonResponse;
class AcademicYearController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            AcademicYear::orderByDesc("start_date")->get(["id","name","is_active","start_date","end_date"])
        );
    }
}
