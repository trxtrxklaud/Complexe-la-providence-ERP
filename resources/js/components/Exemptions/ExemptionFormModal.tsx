import React, { useState } from 'react';
import { X, ShieldCheck, HeartHandshake, BadgePercent, Loader2, AlertCircle } from 'lucide-react';
import {
  createMonthlyExemption,
  createClubExemption,
  type ExemptionItem,
} from '../../api/exemptions';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
};

interface ClubSubscriptionOption {
  id: number;
  club?: {
    id: number;
    name: string;
    monthly_fee?: number;
  };
}

interface ExemptionFormModalProps {
  enrollmentId: number;
  studentName: string;
  clubSubscriptions?: ClubSubscriptionOption[];
  academicYearMonths?: string[];
  onClose: () => void;
  onSuccess: (newItem: ExemptionItem) => void;
}

const MONTH_NAMES: Record<string, string> = {
  '01': 'جانفي', '02': 'فيفري', '03': 'مارس', '04': 'أفريل',
  '05': 'ماي', '06': 'جوان', '07': 'جويلية', '08': 'أوت',
  '09': 'سبتمبر', '10': 'أكتوبر', '11': 'نوفمبر', '12': 'ديسمبر',
};

function formatMonthLabel(m: string) {
  const parts = m.split('-');
  if (parts.length === 2) {
    return `${MONTH_NAMES[parts[1]] || parts[1]} ${parts[0]}`;
  }
  return m;
}

export function ExemptionFormModal({
  enrollmentId,
  studentName,
  clubSubscriptions = [],
  academicYearMonths = [
    '2025-09', '2025-10', '2025-11', '2025-12',
    '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06',
  ],
  onClose,
  onSuccess,
}: ExemptionFormModalProps) {
  const [targetType, setTargetType] = useState<'tuition' | 'club'>('tuition');
  const [subscriptionId, setSubscriptionId] = useState<number | ''>(
    clubSubscriptions[0]?.id || ''
  );
  const [discountType, setDiscountType] = useState<
    'full_waiver' | 'humanitarian_fixed' | 'normal_monthly'
  >('full_waiver');
  const [monthlyAmount, setMonthlyAmount] = useState('');
  const [startMonth, setStartMonth] = useState(academicYearMonths[0] || '2025-09');
  const [endMonth, setEndMonth] = useState(
    academicYearMonths[academicYearMonths.length - 1] || '2026-06'
  );
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');

    if (!reason.trim()) {
      setError('يرجى كتابة سبب الإعفاء أو التخفيض');
      return;
    }

    if (startMonth > endMonth) {
      setError('شهر النهاية يجب أن يكون مساوياً أو بعد شهر البداية');
      return;
    }

    if (discountType !== 'full_waiver') {
      const amountVal = parseFloat(monthlyAmount);
      if (isNaN(amountVal) || amountVal <= 0) {
        setError('يرجى تحديد مبلغ شهري صحيح أكبر من الصفر');
        return;
      }
    }

    if (targetType === 'club' && !subscriptionId) {
      setError('يرجى اختيار النادي المراد تطبيق الإعفاء عليه');
      return;
    }

    setSaving(true);
    try {
      if (targetType === 'tuition') {
        const res = await createMonthlyExemption(enrollmentId, {
          discount_type: discountType,
          monthly_amount:
            discountType !== 'full_waiver' ? parseFloat(monthlyAmount) : null,
          start_month: startMonth,
          end_month: endMonth,
          reason: reason.trim(),
          notes: notes.trim() || null,
        });
        onSuccess(res.data);
      } else {
        const res = await createClubExemption(enrollmentId, Number(subscriptionId), {
          discount_type:
            discountType === 'normal_monthly' ? 'humanitarian_fixed' : discountType,
          monthly_amount:
            discountType !== 'full_waiver' ? parseFloat(monthlyAmount) : null,
          start_month: startMonth,
          end_month: endMonth,
          reason: reason.trim(),
          notes: notes.trim() || null,
        });
        onSuccess(res.data);
      }
    } catch (err: any) {
      setError(err?.message || 'تعذر تسجيل الإعفاء');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm animate-in fade-in duration-200"
      dir="rtl"
    >
      <div
        className="w-full max-w-lg bg-white rounded-3xl shadow-2xl border overflow-hidden flex flex-col max-h-[90vh]"
        style={{ borderColor: C.line }}
      >
        {/* Header */}
        <div
          className="p-5 flex items-center justify-between border-b"
          style={{ borderColor: C.line, background: '#F8FAF6' }}
        >
          <div>
            <h2 className="text-lg font-bold" style={{ color: C.ink }}>
              إضافة إعفاء / تخفيض شهري
            </h2>
            <p className="text-xs mt-0.5" style={{ color: C.muted }}>
              للتلميذ: <span className="font-semibold text-slate-800">{studentName}</span>
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-2 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Body */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4 overflow-y-auto flex-1">
          {error && (
            <div className="p-3.5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
              <AlertCircle className="w-4 h-4 flex-shrink-0" />
              <span>{error}</span>
            </div>
          )}

          {/* نوع البند: تمدرس شهري أو نادي */}
          <div>
            <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
              المجال المعني بالإعفاء
            </label>
            <div className="grid grid-cols-2 gap-2">
              <button
                type="button"
                onClick={() => {
                  setTargetType('tuition');
                }}
                className={`py-2.5 px-3 rounded-2xl text-xs font-bold border transition text-center ${
                  targetType === 'tuition'
                    ? 'bg-[#3B4A36] text-white border-[#3B4A36] shadow-sm'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                }`}
              >
                المعلوم الدراسي الشهري
              </button>
              <button
                type="button"
                onClick={() => {
                  setTargetType('club');
                  if (discountType === 'normal_monthly') setDiscountType('full_waiver');
                }}
                disabled={clubSubscriptions.length === 0}
                className={`py-2.5 px-3 rounded-2xl text-xs font-bold border transition text-center disabled:opacity-50 disabled:cursor-not-allowed ${
                  targetType === 'club'
                    ? 'bg-[#3B4A36] text-white border-[#3B4A36] shadow-sm'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                }`}
              >
                معلوم نادي مدرسي {clubSubscriptions.length === 0 && '(لا توجد اشتراكات)'}
              </button>
            </div>
          </div>

          {/* اختيار النادي في حال تم تحديد نادي */}
          {targetType === 'club' && (
            <div>
              <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                اختر النادي
              </label>
              <select
                value={subscriptionId}
                onChange={(e) => setSubscriptionId(Number(e.target.value))}
                className="w-full rounded-2xl border px-3 py-2 text-sm bg-white"
                style={{ borderColor: C.line }}
              >
                {clubSubscriptions.map((sub) => (
                  <option key={sub.id} value={sub.id}>
                    {sub.club?.name || 'نادي'} (المعلوم الشهري الأصلي:{' '}
                    {sub.club?.monthly_fee ?? 0} د.ت)
                  </option>
                ))}
              </select>
            </div>
          )}

          {/* نوع التخفيض */}
          <div>
            <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
              نوع الإعفاء / التخفيض
            </label>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
              <button
                type="button"
                onClick={() => setDiscountType('full_waiver')}
                className={`p-3 rounded-2xl border text-right transition flex flex-col justify-between ${
                  discountType === 'full_waiver'
                    ? 'bg-emerald-50 border-emerald-400 text-emerald-950 font-bold ring-2 ring-emerald-400/20'
                    : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                }`}
              >
                <div className="flex items-center gap-1.5 mb-1">
                  <ShieldCheck className="w-4 h-4 text-emerald-700" />
                  <span className="text-xs">إعفاء كلي</span>
                </div>
                <span className="text-[11px] opacity-75 font-normal">0 د.ت مستحق</span>
              </button>

              <button
                type="button"
                onClick={() => setDiscountType('humanitarian_fixed')}
                className={`p-3 rounded-2xl border text-right transition flex flex-col justify-between ${
                  discountType === 'humanitarian_fixed'
                    ? 'bg-amber-50 border-amber-400 text-amber-950 font-bold ring-2 ring-amber-400/20'
                    : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                }`}
              >
                <div className="flex items-center gap-1.5 mb-1">
                  <HeartHandshake className="w-4 h-4 text-amber-700" />
                  <span className="text-xs">تخفيض إنساني</span>
                </div>
                <span className="text-[11px] opacity-75 font-normal">مبلغ مخفض مخصص</span>
              </button>

              {targetType === 'tuition' && (
                <button
                  type="button"
                  onClick={() => setDiscountType('normal_monthly')}
                  className={`p-3 rounded-2xl border text-right transition flex flex-col justify-between ${
                    discountType === 'normal_monthly'
                      ? 'bg-teal-50 border-teal-400 text-teal-950 font-bold ring-2 ring-teal-400/20'
                      : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <div className="flex items-center gap-1.5 mb-1">
                    <BadgePercent className="w-4 h-4 text-teal-700" />
                    <span className="text-xs">تخفيض شهري</span>
                  </div>
                  <span className="text-[11px] opacity-75 font-normal">خصم منتظم</span>
                </button>
              )}
            </div>
          </div>

          {/* المبلغ الشهري (في حالة التخفيض غير الكلي) */}
          {discountType !== 'full_waiver' && (
            <div>
              <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                المبلغ الشهري (د.ت)
              </label>
              <input
                type="number"
                min="0.01"
                step="0.01"
                required
                value={monthlyAmount}
                onChange={(e) => setMonthlyAmount(e.target.value)}
                placeholder="مثال: 50.00"
                className="w-full rounded-2xl border px-3 py-2 text-sm bg-white"
                style={{ borderColor: C.line, direction: 'ltr' }}
              />
              <p className="text-[11px] text-slate-500 mt-1">
                {discountType === 'humanitarian_fixed'
                  ? 'المبلغ الشهري الصافي الواجب أداؤه بعد التخفيض الإنساني'
                  : 'قيمة التخفيض المخصومة شهرياً'}
              </p>
            </div>
          )}

          {/* فترة السريان */}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                من شهر
              </label>
              <select
                value={startMonth}
                onChange={(e) => setStartMonth(e.target.value)}
                className="w-full rounded-2xl border px-3 py-2 text-xs bg-white"
                style={{ borderColor: C.line }}
              >
                {academicYearMonths.map((m) => (
                  <option key={m} value={m}>
                    {formatMonthLabel(m)}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                إلى شهر
              </label>
              <select
                value={endMonth}
                onChange={(e) => setEndMonth(e.target.value)}
                className="w-full rounded-2xl border px-3 py-2 text-xs bg-white"
                style={{ borderColor: C.line }}
              >
                {academicYearMonths.map((m) => (
                  <option key={m} value={m}>
                    {formatMonthLabel(m)}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {/* السبب */}
          <div>
            <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
              سبب الإعفاء / التخفيض <span className="text-rose-500">*</span>
            </label>
            <input
              type="text"
              required
              maxLength={500}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="مثال: منحة إدارية، وضعية اجتماعية خاصة..."
              className="w-full rounded-2xl border px-3 py-2 text-sm bg-white"
              style={{ borderColor: C.line }}
            />
          </div>

          {/* الملاحظات */}
          <div>
            <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
              ملاحظات إضافية (اختياري)
            </label>
            <textarea
              rows={2}
              maxLength={1000}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="أي تفاصيل أو توثيق إداري إضافي..."
              className="w-full rounded-2xl border px-3 py-2 text-sm bg-white resize-none"
              style={{ borderColor: C.line }}
            />
          </div>

          {/* Footer buttons */}
          <div className="pt-3 flex items-center justify-end gap-2 border-t" style={{ borderColor: C.line }}>
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 rounded-2xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition"
            >
              إلغاء
            </button>
            <button
              type="submit"
              disabled={saving}
              className="px-5 py-2.5 rounded-2xl text-xs font-bold text-white transition flex items-center gap-1.5 disabled:opacity-50"
              style={{ background: C.forest }}
            >
              {saving ? (
                <>
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  <span>جارٍ الحفظ...</span>
                </>
              ) : (
                <span>حفظ الإعفاء</span>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
