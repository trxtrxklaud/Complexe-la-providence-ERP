import React, { useEffect, useState } from 'react';
import { X, Loader2, AlertCircle, CheckCircle2, DollarSign, Calendar, Sparkles } from 'lucide-react';
import {
  fetchPreschoolShortCyclePreview,
  collectPreschoolShortCycle,
  type PreschoolShortCyclePreview,
} from '../../api/preschoolShortCycle';
import type { ReceiptData } from '../../pages/Payments/ReceiptModal';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
};

interface Props {
  enrollmentId: number;
  studentName: string;
  levelName?: string;
  isOpen: boolean;
  onClose: () => void;
  onSuccess: (receipt: ReceiptData) => void;
}

export function PreschoolShortCycleModal({
  enrollmentId,
  studentName,
  levelName,
  isOpen,
  onClose,
  onSuccess,
}: Props) {
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [preview, setPreview] = useState<PreschoolShortCyclePreview | null>(null);

  const [cycleMode, setCycleMode] = useState<'half_rate' | 'full_rate'>('full_rate');
  const [halfRateMonth, setHalfRateMonth] = useState<string>('');
  const [method, setMethod] = useState<'cash' | 'bank_transfer' | 'check' | 'card'>('cash');
  const [paymentDate, setPaymentDate] = useState<string>(new Date().toISOString().slice(0, 10));
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');

  useEffect(() => {
    if (!isOpen || !enrollmentId) return;

    setLoading(true);
    setError(null);

    fetchPreschoolShortCyclePreview(enrollmentId)
      .then((data) => {
        setPreview(data);
        if (data.can_collect_full) {
          setCycleMode('full_rate');
          setHalfRateMonth('');
        } else if (data.can_collect_september) {
          setCycleMode('half_rate');
          setHalfRateMonth(data.september?.month || '');
        } else if (data.can_collect_june) {
          setCycleMode('half_rate');
          setHalfRateMonth(data.june?.month || '');
        }
      })
      .catch((err) => {
        setError(err.message || 'تعذر تحميل بيانات الدورة المبسطة');
      })
      .finally(() => setLoading(false));
  }, [isOpen, enrollmentId]);

  if (!isOpen) return null;

  const totalAmount = cycleMode === 'full_rate' ? preview?.full_rate ?? 0 : preview?.half_rate ?? 0;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!preview) return;

    if (cycleMode === 'half_rate' && !halfRateMonth) {
      setError('يرجى تحديد الشهر المستهدف (سبتمبر أو جوان)');
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      const idempotencyKey = `SHORT_CYCLE_${enrollmentId}_${cycleMode}_${halfRateMonth || 'FULL'}_${Date.now()}`;
      const receipt = await collectPreschoolShortCycle({
        enrollment_id: enrollmentId,
        payment_date: paymentDate,
        method,
        reference: reference.trim() || undefined,
        notes: notes.trim() || undefined,
        cycle_mode: cycleMode,
        half_rate_month: cycleMode === 'half_rate' ? halfRateMonth : undefined,
        idempotency_key: idempotencyKey,
      });

      onSuccess(receipt);
      onClose();
    } catch (err: any) {
      setError(err.message || 'فشلت عملية الاستخلاص للدورة المبسطة');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs" dir="rtl">
      <div className="bg-white rounded-3xl shadow-2xl max-w-lg w-full overflow-hidden border border-slate-100 flex flex-col max-h-[92vh]">
        {/* Header */}
        <div className="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-amber-50 to-orange-50">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-2xl bg-amber-500/10 flex items-center justify-center text-amber-700">
              <Sparkles size={20} />
            </div>
            <div>
              <h2 className="text-lg font-bold text-slate-900">
                استخلاص الدورة المبسطة (سبتمبر / جوان)
              </h2>
              <p className="text-xs text-slate-500">
                مخصص حصراً لأقسام الروضة والتمهيدي والتحضيري (PRE1, PRE2, PRE3)
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="w-8 h-8 rounded-xl bg-white/80 hover:bg-white text-slate-400 hover:text-slate-700 flex items-center justify-center transition border border-slate-200"
          >
            <X size={16} />
          </button>
        </div>

        {/* Content */}
        <div className="p-6 overflow-y-auto space-y-5 flex-1">
          {loading ? (
            <div className="py-12 flex flex-col items-center justify-center gap-3 text-slate-400">
              <Loader2 className="w-8 h-8 animate-spin text-amber-600" />
              <span className="text-sm">جاري جلب تفاصيل المعاليم والأسعار المعتمدة...</span>
            </div>
          ) : error && !preview ? (
            <div className="p-4 rounded-2xl bg-red-50 border border-red-200 text-red-800 text-sm flex items-center gap-2">
              <AlertCircle size={18} className="shrink-0" />
              <span>{error}</span>
            </div>
          ) : preview && !preview.is_preschool ? (
            <div className="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-center gap-2">
              <AlertCircle size={18} className="shrink-0" />
              <span>{preview.message || 'هذا المسار مخصص حصراً لأقسام ما قبل الابتدائي.'}</span>
            </div>
          ) : preview ? (
            <form id="short-cycle-form" onSubmit={handleSubmit} className="space-y-5">
              {error && (
                <div className="p-4 rounded-2xl bg-red-50 border border-red-200 text-red-800 text-sm flex items-center gap-2">
                  <AlertCircle size={18} className="shrink-0" />
                  <span>{error}</span>
                </div>
              )}

              {/* Student Summary Card */}
              <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center justify-between">
                <div>
                  <div className="font-bold text-slate-900 text-sm">{studentName}</div>
                  <div className="text-xs text-slate-500">
                    المستوى: <span className="font-semibold text-slate-700">{preview.level_name || levelName}</span> ({preview.level_code})
                  </div>
                </div>
                <div className="text-left">
                  <div className="text-xs text-slate-500">نصف المعلوم المعتمد</div>
                  <div className="text-sm font-bold text-amber-700">{preview.half_rate} د.ت</div>
                </div>
              </div>

              {/* Selection Options */}
              <div className="space-y-3">
                <label className="text-xs font-bold text-slate-700 block">
                  اختر طريقة الاستخلاص:
                </label>

                {/* Option 1: Full Rate (September + June) */}
                <div
                  onClick={() => preview.can_collect_full && setCycleMode('full_rate')}
                  className={`p-4 rounded-2xl border transition select-none flex items-center justify-between ${
                    cycleMode === 'full_rate'
                      ? 'border-amber-500 bg-amber-50/50 ring-1 ring-amber-500'
                      : !preview.can_collect_full
                      ? 'border-slate-200 bg-slate-100 opacity-60 cursor-not-allowed'
                      : 'border-slate-200 hover:border-slate-300 cursor-pointer bg-white'
                  }`}
                >
                  <div className="flex items-center gap-3">
                    <input
                      type="radio"
                      name="cycleMode"
                      checked={cycleMode === 'full_rate'}
                      onChange={() => {}}
                      disabled={!preview.can_collect_full}
                      className="text-amber-600 focus:ring-amber-500"
                    />
                    <div>
                      <div className="text-sm font-bold text-slate-900">
                        استخلاص المعلوم الكامل (سبتمبر + جوان معاً)
                      </div>
                      <div className="text-xs text-slate-500">
                        {preview.september?.is_paid && preview.june?.is_paid
                          ? 'كلا الشهرين مستخلصان بالكامل مسبقاً'
                          : preview.september?.is_paid
                          ? 'شهر سبتمبر مستخلص مسبقاً — يرجى اختيار نصف معلوم لشهر جوان'
                          : preview.june?.is_paid
                          ? 'شهر جوان مستخلص مسبقاً — يرجى اختيار نصف معلوم لشهر سبتمبر'
                          : 'يشمل شهري سبتمبر وجوان دفعة واحدة'}
                      </div>
                    </div>
                  </div>
                  <div className="text-base font-extrabold text-slate-900">
                    {preview.full_rate} د.ت
                  </div>
                </div>

                {/* Option 2: Half Rate (Single Month) */}
                <div
                  onClick={() => setCycleMode('half_rate')}
                  className={`p-4 rounded-2xl border transition select-none space-y-3 ${
                    cycleMode === 'half_rate'
                      ? 'border-amber-500 bg-amber-50/50 ring-1 ring-amber-500'
                      : 'border-slate-200 hover:border-slate-300 cursor-pointer bg-white'
                  }`}
                >
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <input
                        type="radio"
                        name="cycleMode"
                        checked={cycleMode === 'half_rate'}
                        onChange={() => {}}
                        className="text-amber-600 focus:ring-amber-500"
                      />
                      <div>
                        <div className="text-sm font-bold text-slate-900">
                          استخلاص نصف معلوم (شهر واحد فقط)
                        </div>
                        <div className="text-xs text-slate-500">
                          اختر إما شهر سبتمبر أو شهر جوان
                        </div>
                      </div>
                    </div>
                    <div className="text-base font-extrabold text-slate-900">
                      {preview.half_rate} د.ت
                    </div>
                  </div>

                  {cycleMode === 'half_rate' && (
                    <div className="grid grid-cols-2 gap-2 pt-2 border-t border-amber-200/60">
                      <button
                        type="button"
                        disabled={preview.september?.is_paid}
                        onClick={() => setHalfRateMonth(preview.september?.month || '')}
                        className={`p-2.5 rounded-xl border text-xs font-bold transition flex items-center justify-between ${
                          halfRateMonth === preview.september?.month
                            ? 'bg-amber-600 text-white border-amber-600 shadow-xs'
                            : preview.september?.is_paid
                            ? 'bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed'
                            : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                        }`}
                      >
                        <span>شهر سبتمبر</span>
                        <span>{preview.september?.is_paid ? 'خالص ✓' : `${preview.september?.amount} د.ت`}</span>
                      </button>

                      <button
                        type="button"
                        disabled={preview.june?.is_paid}
                        onClick={() => setHalfRateMonth(preview.june?.month || '')}
                        className={`p-2.5 rounded-xl border text-xs font-bold transition flex items-center justify-between ${
                          halfRateMonth === preview.june?.month
                            ? 'bg-amber-600 text-white border-amber-600 shadow-xs'
                            : preview.june?.is_paid
                            ? 'bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed'
                            : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                        }`}
                      >
                        <span>شهر جوان</span>
                        <span>{preview.june?.is_paid ? 'خالص ✓' : `${preview.june?.amount} د.ت`}</span>
                      </button>
                    </div>
                  )}
                </div>
              </div>

              {/* Payment Details */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-slate-100">
                <div>
                  <label className="text-xs font-bold text-slate-700 mb-1 block">تاريخ الدفع</label>
                  <input
                    type="date"
                    required
                    value={paymentDate}
                    onChange={(e) => setPaymentDate(e.target.value)}
                    className="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs focus:ring-1 focus:ring-amber-500"
                  />
                </div>
                <div>
                  <label className="text-xs font-bold text-slate-700 mb-1 block">طريقة الدفع</label>
                  <select
                    value={method}
                    onChange={(e) => setMethod(e.target.value as any)}
                    className="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs focus:ring-1 focus:ring-amber-500"
                  >
                    <option value="cash">نقداً</option>
                    <option value="bank_transfer">تحويل بنكي</option>
                    <option value="check">شيك</option>
                    <option value="card">بطاقة بنكية</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="text-xs font-bold text-slate-700 mb-1 block">ملاحظات أو مرجع (اختياري)</label>
                <input
                  type="text"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="مثال: استخلاص نصف شهر سبتمبر حسب المسار المبسط"
                  className="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs focus:ring-1 focus:ring-amber-500"
                />
              </div>
            </form>
          ) : null}
        </div>

        {/* Footer */}
        <div className="px-6 py-4 border-t border-slate-100 bg-slate-50 flex items-center justify-between">
          <div className="text-sm font-semibold text-slate-700">
            المبلغ الإجمالي:{' '}
            <span className="text-lg font-black text-amber-700" dir="ltr">
              {totalAmount.toFixed(2)} د.ت
            </span>
          </div>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-200/60 transition"
            >
              إلغاء
            </button>
            <button
              type="submit"
              form="short-cycle-form"
              disabled={submitting || loading || !preview?.is_preschool || (!preview?.can_collect_september && !preview?.can_collect_june)}
              className="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-amber-600 hover:bg-amber-700 transition flex items-center gap-1.5 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {submitting ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>جاري التسجيل...</span>
                </>
              ) : (
                <>
                  <CheckCircle2 size={16} />
                  <span>تأكيد الاستخلاص وطباعة الوصل</span>
                </>
              )}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
