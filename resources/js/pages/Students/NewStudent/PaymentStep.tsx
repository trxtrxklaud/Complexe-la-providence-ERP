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
          <label className={LABEL_CLASS}>
            طريقة الدفع <span className="text-red-500">*</span>
          </label>
          <div className="relative">
            <input type="hidden" name="payment_method" value={data.payment_method} />
            <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 z-10">
              <ChevronDown size={18} className={`transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
            </div>
            <div
              onClick={() => setOpen(!open)}
              className={`flex items-center w-full pr-4 pl-12 py-3 bg-slate-50/50 hover:bg-white border rounded-xl cursor-pointer min-h-[50px] transition-all duration-200 ${open ? 'border-blue-500 ring-2 ring-blue-500/20 bg-white' : 'border-slate-200'}`}
            >
              <span className={data.payment_method ? 'text-slate-700' : 'text-slate-400'}>{methodLabel}</span>
            </div>

            {open && (
              <>
                <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
                <div className="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-lg z-50 overflow-hidden py-1">
                  {METHODS.map((option) => (
                    <div
                      key={option.id}
                      onClick={() => {
                        setField('payment_method', option.id);
                        setOpen(false);
                      }}
                      className={`px-4 py-3 cursor-pointer transition-colors flex items-center justify-between ${data.payment_method === option.id ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-slate-50 text-slate-700'}`}
                    >
                      {option.label}
                      {data.payment_method === option.id && <CheckCircle2 size={16} className="text-blue-600" />}
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS}>
            المبلغ المدفوع <span className="text-red-500">*</span>
          </label>
          <input
            type="number"
            step="0.01"
            min="0"
            dir="ltr"
            name="registration_amount"
            value={data.registration_amount}
            onChange={onChange}
            className={`${FIELD_CLASS} text-right`}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS}>
            تاريخ الدفع <span className="text-red-500">*</span>
          </label>
          <input
            type="date"
            name="payment_date"
            value={data.payment_date}
            onChange={onChange}
            className={FIELD_CLASS}
          />
        </div>

        <div className="md:col-span-2 space-y-2.5">
          <label className={LABEL_CLASS}>ملاحظات الدفع</label>
          <textarea
            name="payment_notes"
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
