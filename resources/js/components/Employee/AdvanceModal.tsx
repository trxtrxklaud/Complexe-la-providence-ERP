import React, { useState, useEffect } from 'react';
import { X, Calendar, DollarSign, Calculator, User, AlertCircle } from 'lucide-react';
import { createEmployeeAdvance, getEmployees, type Employee } from '../../api/employeeAdvances';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
};

interface AdvanceModalProps {
  onClose: () => void;
  onSuccess: () => void;
  employeesList?: Employee[];
}

export function AdvanceModal({ onClose, onSuccess, employeesList }: AdvanceModalProps) {
  const [employees, setEmployees] = useState<Employee[]>(employeesList || []);
  const [loadingEmployees, setLoadingEmployees] = useState(!employeesList || employeesList.length === 0);

  const [formData, setFormData] = useState({
    employee_id: '',
    type: 'advance' as 'advance' | 'loan',
    amount: '',
    purpose: '',
    repayment_method: 'salary_deduction' as 'salary_deduction' | 'cash',
    installment_count: 1,
    advance_date: new Date().toISOString().slice(0, 10),
    due_date: '',
    notes: '',
  });

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!employeesList || employeesList.length === 0) {
      getEmployees()
        .then((res) => {
          setEmployees(res);
        })
        .catch((err) => {
          console.error('Failed to load employees:', err);
        })
        .finally(() => {
          setLoadingEmployees(false);
        });
    }
  }, [employeesList]);

  // حساب القسط الشهري تلقائياً
  const numAmount = parseFloat(formData.amount) || 0;
  const numInstallments = Math.max(1, formData.installment_count || 1);
  const monthlyInstallment = (numAmount / numInstallments).toFixed(2);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.employee_id) {
      setError('يرجى اختيار الموظف');
      return;
    }
    if (numAmount <= 0) {
      setError('يرجى إدخال مبلغ صحيح أكبر من الصفر');
      return;
    }

    setSaving(true);
    setError(null);

    try {
      await createEmployeeAdvance({
        employee_id: Number(formData.employee_id),
        type: formData.type,
        amount: numAmount,
        purpose: formData.purpose || (formData.type === 'advance' ? 'تسبقة على الراتب' : 'سلفة موظف'),
        reason: formData.purpose || (formData.type === 'advance' ? 'تسبقة على الراتب' : 'سلفة موظف'),
        repayment_method: formData.repayment_method,
        installment_count: formData.type === 'loan' ? numInstallments : 1,
        advance_date: formData.advance_date,
        due_date: formData.due_date || null,
        notes: formData.notes || null,
      });
      onSuccess();
    } catch (err: any) {
      setError(err?.message || 'فشل حفظ السلفة/التسبقة');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4 sm:p-6 overflow-y-auto">
      <div className="bg-white rounded-3xl w-full max-w-2xl shadow-2xl border border-slate-100 overflow-hidden my-auto animate-in fade-in zoom-in-95 duration-200">
        {/* Header */}
        <div className="p-6 border-b flex items-center justify-between" style={{ borderColor: C.line }}>
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
              <DollarSign size={20} style={{ color: C.forest }} />
            </div>
            <div>
              <h2 className="text-lg font-bold" style={{ color: C.ink }}>
                إضافة سلفة أو تسبقة جديدة
              </h2>
              <p className="text-xs" style={{ color: C.muted }}>
                تسجيل خروج نقدي فوري مع ضبط آلية وأقساط الاسترجاع
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            type="button"
            className="p-2 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"
          >
            <X size={20} />
          </button>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="p-6 space-y-5 max-h-[75vh] overflow-y-auto">
          {error && (
            <div className="p-4 rounded-2xl bg-red-50 text-red-700 text-sm flex items-center gap-3 border border-red-200">
              <AlertCircle size={18} className="shrink-0 text-red-600" />
              <span>{error}</span>
            </div>
          )}

          {/* نوع العملية */}
          <div>
            <label className="block text-xs font-bold mb-2" style={{ color: C.ink }}>
              نوع العملية *
            </label>
            <div className="grid grid-cols-2 gap-3">
              <label
                className={`flex items-center gap-3 p-3.5 rounded-2xl border cursor-pointer transition ${
                  formData.type === 'advance' ? 'bg-slate-50 shadow-xs' : 'hover:bg-slate-50/50'
                }`}
                style={{ borderColor: formData.type === 'advance' ? C.forest : C.line }}
              >
                <input
                  type="radio"
                  name="type"
                  value="advance"
                  checked={formData.type === 'advance'}
                  onChange={(e) => setFormData({ ...formData, type: e.target.value as 'advance' })}
                  className="w-4 h-4 text-emerald-600 focus:ring-emerald-500"
                />
                <div>
                  <p className="text-sm font-bold" style={{ color: C.ink }}>
                    تسبقة (Advance)
                  </p>
                  <p className="text-xs" style={{ color: C.muted }}>
                    تُخصم كاملة من راتب الشهر نفسه
                  </p>
                </div>
              </label>

              <label
                className={`flex items-center gap-3 p-3.5 rounded-2xl border cursor-pointer transition ${
                  formData.type === 'loan' ? 'bg-slate-50 shadow-xs' : 'hover:bg-slate-50/50'
                }`}
                style={{ borderColor: formData.type === 'loan' ? C.forest : C.line }}
              >
                <input
                  type="radio"
                  name="type"
                  value="loan"
                  checked={formData.type === 'loan'}
                  onChange={(e) => setFormData({ ...formData, type: e.target.value as 'loan' })}
                  className="w-4 h-4 text-emerald-600 focus:ring-emerald-500"
                />
                <div>
                  <p className="text-sm font-bold" style={{ color: C.ink }}>
                    سلفة (Loan)
                  </p>
                  <p className="text-xs" style={{ color: C.muted }}>
                    تُقسّط على عدة أشهر بالمهل
                  </p>
                </div>
              </label>
            </div>
          </div>

          {/* الموظف والمبلغ */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label htmlFor="employee_id" className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                الموظف / الإطار *
              </label>
              <div className="relative">
                <select
                  id="employee_id"
                  value={formData.employee_id}
                  onChange={(e) => setFormData({ ...formData, employee_id: e.target.value })}
                  required
                  disabled={loadingEmployees}
                  className="w-full px-3.5 py-2.5 rounded-xl border text-sm bg-white focus:outline-hidden focus:ring-2 focus:ring-slate-300"
                  style={{ borderColor: C.line }}
                >
                  <option value="">{loadingEmployees ? 'جارٍ تحميل الإطارات...' : 'اختر الموظف...'}</option>
                  {employees.map((emp) => (
                    <option key={emp.id} value={emp.id}>
                      {emp.first_name} {emp.last_name} {emp.job_title ? `(${emp.job_title})` : ''}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div>
              <label htmlFor="amount" className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                المبلغ (د.ت) *
              </label>
              <div className="relative">
                <input
                  id="amount"
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={formData.amount}
                  onChange={(e) => setFormData({ ...formData, amount: e.target.value })}
                  required
                  placeholder="مثلاً: 500.00"
                  className="w-full px-3.5 py-2.5 rounded-xl border text-sm font-mono font-bold bg-white focus:outline-hidden focus:ring-2 focus:ring-slate-300"
                  style={{ borderColor: C.line }}
                />
              </div>
            </div>
          </div>

          {/* التواريخ */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label htmlFor="advance_date" className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                تاريخ التسليم (خروج النقد) *
              </label>
              <input
                id="advance_date"
                type="date"
                value={formData.advance_date}
                onChange={(e) => setFormData({ ...formData, advance_date: e.target.value })}
                required
                className="w-full px-3.5 py-2.5 rounded-xl border text-sm bg-white focus:outline-hidden focus:ring-2 focus:ring-slate-300"
                style={{ borderColor: C.line }}
              />
            </div>

            <div>
              <label htmlFor="due_date" className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                تاريخ الاسترجاع المتوقع
              </label>
              <input
                id="due_date"
                type="date"
                value={formData.due_date}
                onChange={(e) => setFormData({ ...formData, due_date: e.target.value })}
                className="w-full px-3.5 py-2.5 rounded-xl border text-sm bg-white focus:outline-hidden focus:ring-2 focus:ring-slate-300"
                style={{ borderColor: C.line }}
              />
            </div>
          </div>

          {/* إذا كانت سلفة: أقساط وطريقة استرجاع */}
          {formData.type === 'loan' && (
            <div className="p-4 rounded-2xl border space-y-4" style={{ backgroundColor: C.sage + '40', borderColor: C.line }}>
              <div className="flex items-center gap-2 text-xs font-bold" style={{ color: C.forest }}>
                <Calculator size={16} />
                <span>خطة تقسيط السلفة</span>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label htmlFor="installment_count" className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                    عدد أشهر الاسترجاع (أقساط) *
                  </label>
                  <input
                    id="installment_count"
                    type="number"
                    min="1"
                    max="36"
                    value={formData.installment_count}
                    onChange={(e) =>
                      setFormData({ ...formData, installment_count: parseInt(e.target.value) || 1 })
                    }
                    className="w-full px-3.5 py-2.5 rounded-xl border text-sm bg-white font-mono"
                    style={{ borderColor: C.line }}
                  />
                </div>

                <div>
                  <label htmlFor="repayment_method" className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                    طريقة الاسترجاع المعتمدة
                  </label>
                  <select
                    id="repayment_method"
                    value={formData.repayment_method}
                    onChange={(e) =>
                      setFormData({
                        ...formData,
                        repayment_method: e.target.value as 'salary_deduction' | 'cash',
                      })
                    }
                    className="w-full px-3.5 py-2.5 rounded-xl border text-sm bg-white"
                    style={{ borderColor: C.line }}
                  >
                    <option value="salary_deduction">خصم تلقائي من الراتب الشهري</option>
                    <option value="cash">سداد نقدي في الخزينة</option>
                  </select>
                </div>
              </div>

              {numAmount > 0 && (
                <div className="flex items-center justify-between p-3 rounded-xl bg-white border text-xs" style={{ borderColor: C.line }}>
                  <span style={{ color: C.muted }}>القسط الشهري التقديري:</span>
                  <span className="font-mono font-bold text-sm" style={{ color: C.forest }}>
                    {monthlyInstallment} د.ت / شهر
                  </span>
                </div>
              )}
            </div>
          )}

          {/* الغرض والملاحظات */}
          <div>
            <label htmlFor="purpose" className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
              السبب / الغرض
            </label>
            <input
              id="purpose"
              type="text"
              value={formData.purpose}
              onChange={(e) => setFormData({ ...formData, purpose: e.target.value })}
              placeholder="مثلاً: سلفة شخصية، علاج، مناسبة..."
              className="w-full px-3.5 py-2.5 rounded-xl border text-sm bg-white focus:outline-hidden focus:ring-2 focus:ring-slate-300"
              style={{ borderColor: C.line }}
            />
          </div>

          <div>
            <label htmlFor="notes" className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
              ملاحظات إضافية
            </label>
            <textarea
              id="notes"
              rows={2}
              value={formData.notes}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
              placeholder="أي تفاصيل أو شروط خاصة..."
              className="w-full px-3.5 py-2.5 rounded-xl border text-sm bg-white focus:outline-hidden focus:ring-2 focus:ring-slate-300"
              style={{ borderColor: C.line }}
            />
          </div>
        </form>

        {/* Footer */}
        <div className="p-4 sm:p-6 border-t bg-slate-50/70 flex items-center justify-end gap-3" style={{ borderColor: C.line }}>
          <button
            type="button"
            onClick={onClose}
            disabled={saving}
            className="px-5 py-2.5 rounded-xl text-sm font-bold border bg-white hover:bg-slate-100 transition"
            style={{ borderColor: C.line, color: C.muted }}
          >
            إلغاء
          </button>
          <button
            type="button"
            onClick={handleSubmit}
            disabled={saving}
            className="px-6 py-2.5 rounded-xl text-sm font-bold text-white shadow-md hover:shadow-lg transition disabled:opacity-50 flex items-center gap-2"
            style={{ backgroundColor: C.forest }}
          >
            {saving ? 'جاري التسجيل...' : 'تأكيد وحفظ'}
          </button>
        </div>
      </div>
    </div>
  );
}
