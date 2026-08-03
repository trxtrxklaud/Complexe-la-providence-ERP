import React, { useState } from 'react';
import { C } from './shared';

export interface EmployeeFormValues {
  first_name: string;
  last_name: string;
  phone: string;
  job_title: string;
  default_salary: string;
}

interface Props {
  saving: boolean;
  onClose: () => void;
  onSubmit: (values: EmployeeFormValues) => void;
}

const EMPTY: EmployeeFormValues = {
  first_name: '', last_name: '', phone: '', job_title: '', default_salary: '',
};

const FIELD = 'w-full border rounded-xl px-3 py-2';

/** نافذة إضافة إطار جديد؛ تحمل حالتها بنفسها فلا تثقل المكوّن الأب. */
export function EmployeeFormModal({ saving, onClose, onSubmit }: Props) {
  const [form, setForm] = useState<EmployeeFormValues>(EMPTY);

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    onSubmit(form);
  }

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <form onSubmit={handleSubmit} className="bg-white rounded-2xl p-5 w-full max-w-md space-y-3">
        <h3 className="font-bold text-lg" style={{ color: C.ink }}>إضافة إطار</h3>

        <input required placeholder="الاسم" className={FIELD} style={{ borderColor: C.line }}
          value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} />

        <input required placeholder="اللقب" className={FIELD} style={{ borderColor: C.line }}
          value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} />

        <input placeholder="الهاتف" className={FIELD} style={{ borderColor: C.line }}
          value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />

        <input placeholder="الوظيفة" className={FIELD} style={{ borderColor: C.line }}
          value={form.job_title} onChange={(e) => setForm({ ...form, job_title: e.target.value })} />

        <input type="number" step="0.01" placeholder="الراتب الافتراضي" className={FIELD} style={{ borderColor: C.line }}
          value={form.default_salary} onChange={(e) => setForm({ ...form, default_salary: e.target.value })} />

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
