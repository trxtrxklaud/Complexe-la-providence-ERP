import React, { useState } from 'react';
import type { EmployeeAdvance, RepaymentMethod } from '../../api/employees';
import { C, money, today, employeeName, remainingOf } from './shared';

export interface SettleFormValues {
  amount: number;
  method: RepaymentMethod;
  repaid_at: string;
  notes: string;
}

interface Props {
  advance: EmployeeAdvance;
  saving: boolean;
  onClose: () => void;
  onError: (message: string) => void;
  onSubmit: (values: SettleFormValues) => void;
}

const FIELD = 'w-full border rounded-xl px-3 py-2';

/**
 * نافذة ردّ قسط من سلفة.
 *
 * للطريقتين أثر محاسبي مختلف تماماً: النقدي يدخل الدرج فيُسجّل في الدفتر،
 * والخصم من الراتب لا يمرّ بالصندوق إطلاقاً فلا سطر له فيه.
 *
 * التحقّق هنا راحة للقابض لا أكثر؛ الخادم يرفض التجاوز أيضاً مع قفل صفّ.
 */
export function SettleAdvanceModal({ advance, saving, onClose, onError, onSubmit }: Props) {
  const remaining = remainingOf(advance);

  const [form, setForm] = useState({
    amount: remaining.toFixed(2),
    method: 'cash' as RepaymentMethod,
    repaid_at: today(),
    notes: '',
  });

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();

    const amount = Number(form.amount);

    if (!amount || amount <= 0) {
      onError('مبلغ الخلاص غير صالح');
      return;
    }

    if (amount > remaining + 0.001) {
      onError(`المبلغ يتجاوز المتبقّي (${money(remaining)})`);
      return;
    }

    onSubmit({ amount, method: form.method, repaid_at: form.repaid_at, notes: form.notes });
  }

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <form onSubmit={handleSubmit} className="bg-white rounded-2xl p-5 w-full max-w-md space-y-3 max-h-[90vh] overflow-y-auto">
        <h3 className="font-bold text-lg" style={{ color: C.ink }}>خلاص سلفة</h3>

        <div className="rounded-xl p-3 text-sm space-y-1" style={{ background: C.sage, color: C.ink }}>
          <div className="flex justify-between">
            <span>الإطار</span>
            <strong>{employeeName(advance.employee, advance.employee_id)}</strong>
          </div>
          <div className="flex justify-between">
            <span>أصل السلفة</span>
            <strong>{money(advance.amount)}</strong>
          </div>
          <div className="flex justify-between">
            <span>المتبقّي</span>
            <strong style={{ color: C.danger }}>{money(remaining)}</strong>
          </div>
        </div>

        <div>
          <label className="text-xs" style={{ color: C.muted }}>المبلغ المردود</label>
          <input required type="number" step="0.01" min="0.01" className={`mt-1 ${FIELD}`} style={{ borderColor: C.line }}
            value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
        </div>

        <div>
          <label className="text-xs" style={{ color: C.muted }}>طريقة الردّ</label>
          <select className={`mt-1 ${FIELD}`} style={{ borderColor: C.line }}
            value={form.method}
            onChange={(e) => setForm({ ...form, method: e.target.value as RepaymentMethod })}>
            <option value="cash">نقداً — يدخل الخزينة</option>
            <option value="salary_deduction">خصم من الراتب — لا يمرّ بالصندوق</option>
          </select>
        </div>

        <div>
          <label className="text-xs" style={{ color: C.muted }}>تاريخ الردّ</label>
          <input required type="date" className={`mt-1 ${FIELD}`} style={{ borderColor: C.line }}
            value={form.repaid_at} onChange={(e) => setForm({ ...form, repaid_at: e.target.value })} />
        </div>

        <input placeholder="ملاحظة (اختيارية)" className={FIELD} style={{ borderColor: C.line }}
          value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />

        <p className="text-xs" style={{ color: C.muted }}>
          {form.method === 'cash'
            ? 'المبلغ يدخل الدرج فعلاً، ويُسجّل مدخولاً في بند «خلاص سلفة» بتاريخ الردّ.'
            : 'لا مال يدخل الخزينة: الإطار سيقبض راتباً أقلّ، فيُنقَص الدَين فحسب دون سطر في الدفتر.'}
        </p>

        <div className="flex gap-2 justify-end">
          <button type="button" onClick={onClose} className="px-4 py-2 rounded-xl border" style={{ borderColor: C.line }}>إلغاء</button>
          <button type="submit" disabled={saving} className="px-4 py-2 rounded-xl text-white font-bold disabled:opacity-50" style={{ background: C.forest }}>
            {saving ? 'جارٍ...' : 'تسجيل الردّ'}
          </button>
        </div>
      </form>
    </div>
  );
}
