<?php

namespace App\Http\Controllers;

use App\Models\FeeType;
use Illuminate\Http\Request;

class FeeTypeController extends Controller
{
    public function index()
    {
        return response()->json(FeeType::orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name_ar'   => 'required|string|max:255',
            'name_fr'   => 'nullable|string|max:255',
            'price'     => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        return response()->json(FeeType::create($data), 201);
    }

    public function update(Request $request, FeeType $feeType)
    {
        $data = $request->validate([
            'name_ar'   => 'required|string|max:255',
            'name_fr'   => 'nullable|string|max:255',
            'price'     => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $feeType->update($data);
        return response()->json($feeType);
    }

    public function destroy(FeeType $feeType)
    {
        $feeType->delete();
        return response()->json(null, 204);
    }
}
