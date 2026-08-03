import React, { useState } from 'react';
import type { Employee } from '../../api/employees';
import { TreasuryBalanceHint } from '../../components/TreasuryBalanceHint';
import { C, today } from './shared';

export interface AdvanceFormValues {
  employee_id: string;
  type: 'advance' | 'loan';
  amount: string;
  advance_date: string;
  reason: string;
  method: string;
}

interface Props {
  employees: Employee[];
  saving: boolean;
  onClose: () => void;
  onSubmit: (values: AdvanceFormValues) => void;
}

const FIELD = 'w-full border rounded-xl px-3 py-2';

/**
 * نافذة منح تسبقة أو سلفة.
 *
 * المبلغ يخرج من الخزينة يوم منحه في الحالتين، ولذلك يُعرض تنبيه الرصيد
 * قبل الحفظ. الفرق بينهما في طريقة الاسترداد لا في الخروج.
 */
export function AdvanceFormModal({ employees, saving, onClose, onSubmit }: Props) {
  const [form, setForm] = useState<AdvanceFormValues>({
    employee_id: '', type: 'advance', amount: '',
    advance_date: today(), reason: '', method: 'cash',
  });

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    onSubmit(form);
  }

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <form onSubmit={handleSubmit} className="bg-white rounded-2xl p-5 w-full max-w-md space-y-3 max-h-[90vh] overflow-y-auto">
        <h3 className="font-bold text-lg" style={{ color: C.ink }}>منح تسبقة أو سلفة</h3>

        <select required className={FIELD} style={{ borderColor: C.line }}
          value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })}>
          <option value="">اختر الإطار</option>
          {employees.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}
        </select>

        <div>
          <label className="text-xs" style={{ color: C.muted }}>النوع</label>
          <select className={`mt-1 ${FIELD}`} style={{ borderColor: C.line }}
            value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as 'advance' | 'loan' })}>
            <option value="advance">تسبقة — تُخصم من راتب الشهر</option>
            <option value="loan">سلفة — تُردّ على مهل</option>
          </select>
        </div>

        <input required type="number" step="0.01" min="0.01" placeholder="المبلغ" className={FIELD} style={{ borderColor: C.line }}
          value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />

        <input required type="date" className={FIELD} style={{ borderColor: C.line }}
          value={form.advance_date} onChange={(e) => setForm({ ...form, advance_date: e.target.value })} />

        <input placeholder="السبب (اختياري)" className={FIELD} style={{ borderColor: C.line }}
          value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />

        <TreasuryBalanceHint amount={Number(form.amount || 0)} refreshKey={true} />

        <p className="text-xs" style={{ color: C.muted }}>
          هذا المبلغ يخرج من الخزينة يوم منحه ويُسجّل في الدفتر النقدي.
        </p>

        <div className="flex gap-2 justify-end">
          <button type="button" onClick={onClose} className="px-4 py-2 rounded-xl border" style={{ borderColor: C.line }}>إلغاء</button>
          <button type="submit" disabled={saving} className="px-4 py-2 rounded-xl text-white font-bold disabled:opacity-50" style={{ background: C.forest }}>
            {saving ? 'جارٍ...' : 'حفظ'}
          </button>
        </div>
      </form>
    </div>
  );
}
