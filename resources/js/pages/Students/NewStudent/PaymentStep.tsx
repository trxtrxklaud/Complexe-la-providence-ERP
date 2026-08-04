import { useState } from 'react';
import { CreditCard, ChevronDown, CheckCircle2 } from 'lucide-react';
import { FIELD_CLASS, LABEL_CLASS, type ChangeHandler, type WizardFormData } from './types';

const METHODS = [
  { id: 'cash', label: 'نقداً' },
  { id: 'bank_transfer', label: 'تحويل بنكي' },
  { id: 'check', label: 'شيك' },
];

type Props = {
  data: WizardFormData;
  onChange: ChangeHandler;
  setField: (name: keyof WizardFormData, value: string) => void;
};

export function PaymentStep({ data, onChange, setField }: Props) {
  const [open, setOpen] = useState(false);
  const methodLabel = METHODS.find((m) => m.id === data.payment_method)?.label ?? 'اختر الطريقة';

  return (
    <div className="space-y-8">
      <div className="flex items-center gap-3 pb-6 border-b border-slate-100">
        <div className="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center text-blue-600">
          <CreditCard size={20} />
        </div>
        <div>
          <h2 className="text-xl font-bold text-slate-800">معلوم الترسيم</h2>
          <p className="text-sm text-slate-500 mt-1">
            يُسجّل كمدخول ترسيم في الخزينة ويظهر في المداخيل والدخل الصافي بتاريخ الدفع
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="payment_method_trigger">
            طريقة الدفع <span className="text-red-500">*</span>
          </label>
          <div className="relative">
            {/* الحقل المخفي هو ما تقرأه FormData؛ الزرّ هو ما يراه المستخدم ويركّز عليه. */}
            <input type="hidden" name="payment_method" value={data.payment_method} />
            <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 z-10">
              <ChevronDown size={18} className={`transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
            </div>
            <button
              type="button"
              id="payment_method_trigger"
              aria-haspopup="listbox"
              aria-expanded={open}
              onClick={() => setOpen(!open)}
              className={`flex items-center w-full text-right pr-4 pl-12 py-3 bg-slate-50/50 hover:bg-white border rounded-xl cursor-pointer min-h-[50px] transition-all duration-200 outline-none ${open ? 'border-blue-500 ring-2 ring-blue-500/20 bg-white' : 'border-slate-200'}`}
            >
              <span className={data.payment_method ? 'text-slate-700' : 'text-slate-400'}>{methodLabel}</span>
            </button>

            {open && (
              <>
                <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
                <ul
                  role="listbox"
                  aria-label="طريقة الدفع"
                  className="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-lg z-50 overflow-hidden py-1"
                >
                  {METHODS.map((option) => (
                    <li key={option.id} role="option" aria-selected={data.payment_method === option.id}>
                      <button
                        type="button"
                        onClick={() => {
                          setField('payment_method', option.id);
                          setOpen(false);
                        }}
                        className={`w-full px-4 py-3 cursor-pointer transition-colors flex items-center justify-between text-right ${data.payment_method === option.id ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-slate-50 text-slate-700'}`}
                      >
                        {option.label}
                        {data.payment_method === option.id && <CheckCircle2 size={16} className="text-blue-600" />}
                      </button>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="registration_amount">
            المبلغ المدفوع <span className="text-red-500">*</span>
          </label>
          <input
            type="number"
            step="0.01"
            min="0"
            dir="ltr"
            id="registration_amount"
            name="registration_amount"
            autoComplete="off"
            inputMode="decimal"
            value={data.registration_amount}
            onChange={onChange}
            className={`${FIELD_CLASS} text-right`}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="payment_date">
            تاريخ الدفع <span className="text-red-500">*</span>
          </label>
          <input
            type="date"
            id="payment_date"
            name="payment_date"
            autoComplete="off"
            value={data.payment_date}
            onChange={onChange}
            className={FIELD_CLASS}
          />
          <p className="text-xs text-slate-500">
            هذا التاريخ هو الذي يظهر في الخزينة والمداخيل، لا تاريخ إدخال البيانات.
          </p>
        </div>

        <div className="md:col-span-2 space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="payment_notes">ملاحظات الدفع</label>
          <textarea
            id="payment_notes"
            name="payment_notes"
            autoComplete="off"
            value={data.payment_notes}
            onChange={onChange}
            rows={3}
            className={FIELD_CLASS}
          />
        </div>
      </div>
    </div>
  );
}

export default PaymentStep;
