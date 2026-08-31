import { useEffect, useState } from 'react';
import { CreditCard, ChevronDown, CheckCircle2, DollarSign } from 'lucide-react';
import { FIELD_CLASS, LABEL_CLASS, type ChangeHandler, type WizardFormData } from './types';
import { EnrollmentFeeItemsSelector } from '../../../components/Payments/EnrollmentFeeItemsSelector';

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

  useEffect(() => {
    // تعبئة طريقة الدفع الافتراضية والتاريخ إن كانا فارغين
    if (!data.payment_method) {
      setField('payment_method', 'cash');
    }
    if (!data.payment_date) {
      const now = new Date();
      const offset = now.getTimezoneOffset() * 60000;
      const today = new Date(now.getTime() - offset).toISOString().slice(0, 10);
      setField('payment_date', today);
    }
  }, []);

  const handleTotalChange = (total: number) => {
    setField('registration_amount', total > 0 ? String(total) : '');
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3 pb-5 border-b border-slate-100">
        <div className="w-10 h-10 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-700">
          <CreditCard size={20} />
        </div>
        <div>
          <h2 className="text-lg font-bold text-slate-800">معاليم الترسيم واللوازم المدرسية</h2>
          <p className="text-xs text-slate-500 mt-0.5">
            يُسجّل المبلغ كمدخول في الخزينة مصنفاً حسب كل بند (ترسيم، ميدعة، منظومة، رزمة ورق) في المداخيل
          </p>
        </div>
      </div>

      {/* تفصيل واختيار المعاليم واللوازم مع إمكانية تعديل السعر */}
      <EnrollmentFeeItemsSelector onTotalChange={handleTotalChange} />

      {/* حقول الدفع الأساسية */}
      <div className="pt-4 border-t border-slate-100 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-5">
        <div className="space-y-2">
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
              className={`flex items-center w-full text-right pr-4 pl-12 py-2.5 bg-slate-50/50 hover:bg-white border rounded-xl cursor-pointer min-h-[46px] transition-all duration-200 outline-none ${open ? 'border-emerald-600 ring-2 ring-emerald-600/20 bg-white' : 'border-slate-200'}`}
            >
              <span className={data.payment_method ? 'text-slate-700 font-semibold text-xs' : 'text-slate-400 text-xs'}>{methodLabel}</span>
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
                        className={`w-full px-4 py-2.5 cursor-pointer transition-colors flex items-center justify-between text-right text-xs ${data.payment_method === option.id ? 'bg-emerald-50 text-emerald-800 font-bold' : 'hover:bg-slate-50 text-slate-700'}`}
                      >
                        {option.label}
                        {data.payment_method === option.id && <CheckCircle2 size={15} className="text-emerald-600" />}
                      </button>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        </div>

        <div className="space-y-2">
          <label className={LABEL_CLASS} htmlFor="registration_amount">
            المبلغ الإجمالي المقبوض <span className="text-red-500">*</span>
          </label>
          <div className="relative">
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
              className={`${FIELD_CLASS} text-right font-black text-sm text-emerald-900 pr-9`}
            />
            <div className="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-emerald-700">
              <DollarSign size={16} />
            </div>
          </div>
          <p className="text-[11px] text-slate-500">
            يُحسب تلقائياً من مجموع المعاليم المختارة أعلاه، ويمكن تعديله مباشرة.
          </p>
        </div>

        <div className="space-y-2">
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
          <p className="text-[11px] text-slate-500">
            هذا التاريخ هو الذي يظهر في الخزينة والمداخيل، لا تاريخ إدخال البيانات.
          </p>
        </div>

        <div className="space-y-2">
          <label className={LABEL_CLASS} htmlFor="payment_notes">ملاحظات الدفع (اختياري)</label>
          <input
            type="text"
            id="payment_notes"
            name="payment_notes"
            autoComplete="off"
            placeholder="مثال: خلاص الترسيم والميدعة كاملاً"
            value={data.payment_notes}
            onChange={onChange}
            className={FIELD_CLASS}
          />
        </div>
      </div>
    </div>
  );
}

export default PaymentStep;

