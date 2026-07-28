<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\FeeType;
use Illuminate\Http\Request;

class FeeTypeController extends Controller
{
    /**
     * قواعد التحقّق مشتركة بين الإنشاء والتعديل.
     *
     * ledger_category هو ما يحدّد بند المدخول في الدفتر المركزي. كان مفقوداً من
     * التحقّق رغم وجوده في fillable، فكان يُهمَل صامتاً وتقع المنصة على الاستدلال
     * من اسم النوع. القائمة هنا مطابقة تماماً لـ FeeType::resolveLedgerCategory()
     * بما فيها «خلاص سلفة»، وإسقاطها كان سيمنع تصنيف خلاص السلف إلى الأبد.
     */
    private function rules(): array
    {
        $categories = [
            CashTransaction::CATEGORY_REGISTRATION_FEE,
            CashTransaction::CATEGORY_MONTHLY_FEE,
            CashTransaction::CATEGORY_INSTALLMENT,
            CashTransaction::CATEGORY_PRODUCT_SALE,
            CashTransaction::CATEGORY_ADVANCE_REPAYMENT,
            CashTransaction::CATEGORY_OTHER_INCOME,
        ];

        return [
            'name_ar'         => 'required|string|max:255',
            'name_fr'         => 'nullable|string|max:255',
            'price'           => 'required|numeric|min:0',
            'is_active'       => 'boolean',
            'ledger_category' => 'nullable|string|in:' . implode(',', $categories),
        ];
    }

    public function index()
    {
        return response()->json(FeeType::orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        return response()->json(FeeType::create($data), 201);
    }

    public function update(Request $request, FeeType $feeType)
    {
        $data = $request->validate($this->rules());

        $feeType->update($data);
        return response()->json($feeType);
    }

    public function destroy(FeeType $feeType)
    {
        $feeType->delete();
        return response()->json(null, 204);
    }
}
