import React, { useState } from 'react';
import {
  C,
} from './shared';
import {
  STAFF_TYPE_LABELS, SALARY_TYPE_LABELS,
  type Employee, type StaffType, type SalaryType,
} from '../../api/employees';

export interface EmployeeFormValues {
  first_name: string;
  last_name: string;
  phone: string;
  email: string;
  job_title: string;
  staff_type: StaffType;
  salary_type: SalaryType;
  hourly_rate: string;
  monthly_salary: string;
  default_salary: string;
  hire_date: string;
  notes: string;
}

interface Props {
  initialData?: Employee | null;
  saving: boolean;
  onClose: () => void;
  onSubmit: (values: EmployeeFormValues) => void;
}

const EMPTY: EmployeeFormValues = {
  first_name: '', last_name: '', phone: '', email: '', job_title: '',
  staff_type: 'monthly_teacher', salary_type: 'monthly',
  hourly_rate: '', monthly_salary: '', default_salary: '',
  hire_date: '', notes: '',
};

const FIELD = 'w-full border rounded-xl px-3 py-2 text-sm';

/** نافذة إضافة أو تعديل إطار؛ تحمل حالتها بنفسها فلا تثقل المكوّن الأب. */
export function EmployeeFormModal({ initialData, saving, onClose, onSubmit }: Props) {
  const isEdit = Boolean(initialData);

  const [form, setForm] = useState<EmployeeFormValues>(() => {
    if (initialData) {
      return {
        first_name: initialData.first_name || '',
        last_name: initialData.last_name || '',
        phone: initialData.phone || '',
        email: initialData.email || '',
        job_title: initialData.job_title || '',
        staff_type: (initialData.staff_type || 'monthly_teacher') as StaffType,
        salary_type: (initialData.salary_type || 'monthly') as SalaryType,
        hourly_rate: initialData.hourly_rate != null ? String(initialData.hourly_rate) : '',
        monthly_salary: initialData.monthly_salary != null ? String(initialData.monthly_salary) : '',
        default_salary: initialData.default_salary != null ? String(initialData.default_salary) : '',
        hire_date: initialData.hire_date ? String(initialData.hire_date).slice(0, 10) : '',
        notes: initialData.notes || '',
      };
    }
    return EMPTY;
  });

  const set = (patch: Partial<EmployeeFormValues>) => setForm((f) => ({ ...f, ...patch }));

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    onSubmit(form);
  }

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <form onSubmit={handleSubmit} className="bg-white rounded-2xl p-5 w-full max-w-md space-y-3 max-h-[90vh] overflow-y-auto" dir="rtl">
        <h3 className="font-bold text-lg" style={{ color: C.ink }}>
          {isEdit ? 'تعديل بيانات الإطار' : 'إضافة إطار'}
        </h3>

        <div className="grid grid-cols-2 gap-2">
          <input required placeholder="الاسم" className={FIELD} style={{ borderColor: C.line }}
            value={form.first_name} onChange={(e) => set({ first_name: e.target.value })} />

          <input required placeholder="اللقب" className={FIELD} style={{ borderColor: C.line }}
            value={form.last_name} onChange={(e) => set({ last_name: e.target.value })} />
        </div>

        <div className="grid grid-cols-2 gap-2">
          <input placeholder="الهاتف" className={FIELD} style={{ borderColor: C.line }}
            value={form.phone} onChange={(e) => set({ phone: e.target.value })} />

          <input placeholder="الوظيفة" className={FIELD} style={{ borderColor: C.line }}
            value={form.job_title} onChange={(e) => set({ job_title: e.target.value })} />
        </div>

        <input type="email" placeholder="البريد الإلكتروني (اختياري)" className={FIELD} style={{ borderColor: C.line }}
          value={form.email} onChange={(e) => set({ email: e.target.value })} />

        <div>
          <label className="block text-xs mb-1" style={{ color: C.muted }}>تاريخ الانتداب</label>
          <input type="date" className={FIELD} style={{ borderColor: C.line }}
            value={form.hire_date} onChange={(e) => set({ hire_date: e.target.value })} />
        </div>

        <div>
          <label className="block text-xs mb-1" style={{ color: C.muted }}>صنف الإطار</label>
          <select required className={FIELD} style={{ borderColor: C.line }}
            value={form.staff_type} onChange={(e) => set({ staff_type: e.target.value as StaffType })}>
            {Object.entries(STAFF_TYPE_LABELS).map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-xs mb-1" style={{ color: C.muted }}>نوع الأجر</label>
          <select required className={FIELD} style={{ borderColor: C.line }}
            value={form.salary_type} onChange={(e) => set({ salary_type: e.target.value as SalaryType })}>
            {Object.entries(SALARY_TYPE_LABELS).map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>
        </div>

        {form.salary_type === 'hourly' ? (
          <div>
            <label className="block text-xs mb-1" style={{ color: C.muted }}>معلوم الساعة (د.ت)</label>
            <input type="number" step="0.001" min="0" placeholder="0.000" className={FIELD}
              style={{ borderColor: C.line, direction: 'ltr' }}
              value={form.hourly_rate} onChange={(e) => set({ hourly_rate: e.target.value })} />
          </div>
        ) : (
          <div>
            <label className="block text-xs mb-1" style={{ color: C.muted }}>الراتب الشهري (د.ت)</label>
            <input type="number" step="0.01" min="0" placeholder="0.00" className={FIELD}
              style={{ borderColor: C.line, direction: 'ltr' }}
              value={form.monthly_salary} onChange={(e) => set({ monthly_salary: e.target.value })} />
          </div>
        )}

        <div>
          <label className="block text-xs mb-1" style={{ color: C.muted }}>الراتب الافتراضي (اختياري)</label>
          <input type="number" step="0.01" min="0" placeholder="0.00" className={FIELD}
            style={{ borderColor: C.line, direction: 'ltr' }}
            value={form.default_salary} onChange={(e) => set({ default_salary: e.target.value })} />
        </div>

        <div>
          <label className="block text-xs mb-1" style={{ color: C.muted }}>ملاحظات</label>
          <textarea placeholder="ملاحظات إضافية عن الإطار..." rows={2} className={FIELD}
            style={{ borderColor: C.line }}
            value={form.notes} onChange={(e) => set({ notes: e.target.value })} />
        </div>

        <div className="flex gap-2 justify-end pt-2">
          <button type="button" onClick={onClose} className="px-4 py-2 rounded-xl border text-sm" style={{ borderColor: C.line }}>إلغاء</button>
          <button type="submit" disabled={saving} className="px-5 py-2 rounded-xl text-white font-bold text-sm disabled:opacity-50" style={{ background: C.forest }}>
            {saving ? 'جارٍ...' : (isEdit ? 'تحديث البيانات' : 'حفظ')}
          </button>
        </div>
      </form>
    </div>
  );
}