import React, { useState } from 'react';
import {
  C,
} from './shared';
import {
  STAFF_TYPE_LABELS, SALARY_TYPE_LABELS,
  type StaffType, type SalaryType,
} from '../../api/employees';

export interface EmployeeFormValues {
  first_name: string;
  last_name: string;
  phone: string;
  job_title: string;
  staff_type: StaffType;
  salary_type: SalaryType;
  hourly_rate: string;
  monthly_salary: string;
  default_salary: string;
  hire_date: string;
}

interface Props {
  saving: boolean;
  onClose: () => void;
  onSubmit: (values: EmployeeFormValues) => void;
}

const EMPTY: EmployeeFormValues = {
  first_name: '', last_name: '', phone: '', job_title: '',
  staff_type: 'monthly_teacher', salary_type: 'monthly',
  hourly_rate: '', monthly_salary: '', default_salary: '',
  hire_date: '',
};

const FIELD = 'w-full border rounded-xl px-3 py-2';

/** نافذة إضافة إطار جديد؛ تحمل حالتها بنفسها فلا تثقل المكوّن الأب. */
export function EmployeeFormModal({ saving, onClose, onSubmit }: Props) {
  const [form, setForm] = useState<EmployeeFormValues>(EMPTY);
  const set = (patch: Partial<EmployeeFormValues>) => setForm((f) => ({ ...f, ...patch }));

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    onSubmit(form);
  }

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <form onSubmit={handleSubmit} className="bg-white rounded-2xl p-5 w-full max-w-md space-y-3">
        <h3 className="font-bold text-lg" style={{ color: C.ink }}>إضافة إطار</h3>

        <input required placeholder="الاسم" className={FIELD} style={{ borderColor: C.line }}
          value={form.first_name} onChange={(e) => set({ first_name: e.target.value })} />

        <input required placeholder="اللقب" className={FIELD} style={{ borderColor: C.line }}
          value={form.last_name} onChange={(e) => set({ last_name: e.target.value })} />

        <input placeholder="الهاتف" className={FIELD} style={{ borderColor: C.line }}
          value={form.phone} onChange={(e) => set({ phone: e.target.value })} />

        <input placeholder="الوظيفة" className={FIELD} style={{ borderColor: C.line }}
          value={form.job_title} onChange={(e) => set({ job_title: e.target.value })} />

        <input type="date" className={FIELD} style={{ borderColor: C.line }}
          value={form.hire_date} onChange={(e) => set({ hire_date: e.target.value })} />

        <select required className={FIELD} style={{ borderColor: C.line }}
          value={form.staff_type} onChange={(e) => set({ staff_type: e.target.value as StaffType })}>
          {Object.entries(STAFF_TYPE_LABELS).map(([value, label]) => (
            <option key={value} value={value}>{label}</option>
          ))}
        </select>

        <select required className={FIELD} style={{ borderColor: C.line }}
          value={form.salary_type} onChange={(e) => set({ salary_type: e.target.value as SalaryType })}>
          {Object.entries(SALARY_TYPE_LABELS).map(([value, label]) => (
            <option key={value} value={value}>{label}</option>
          ))}
        </select>

        {form.salary_type === 'hourly' ? (
          <input type="number" step="0.001" min="0" placeholder="معلوم الساعة (د.ت)" className={FIELD}
            style={{ borderColor: C.line }}
            value={form.hourly_rate} onChange={(e) => set({ hourly_rate: e.target.value })} />
        ) : (
          <input type="number" step="0.01" min="0" placeholder="الراتب الشهري (د.ت)" className={FIELD}
            style={{ borderColor: C.line }}
            value={form.monthly_salary} onChange={(e) => set({ monthly_salary: e.target.value })} />
        )}

        <input type="number" step="0.01" min="0" placeholder="الراتب الافتراضي (اختياري)" className={FIELD}
          style={{ borderColor: C.line }}
          value={form.default_salary} onChange={(e) => set({ default_salary: e.target.value })} />

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