import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { HeartHandshake, ShieldAlert, CheckCircle2, XCircle, AlertCircle, Info, Calendar, DollarSign } from 'lucide-react';
import { apiFetch, ApiError } from '../../api/http';
import {
  fetchTuitionMonthlyDiscounts,
  createTuitionMonthlyDiscount,
  cancelTuitionMonthlyDiscount,
  fetchClubMonthlyDiscounts,
  createClubMonthlyDiscount,
  cancelClubMonthlyDiscount,
  type MonthlyDiscountItem,
} from '../../api/monthlyDiscounts';

const C = {
  forest: '#3B4A36',
  sage:   '#E3EBDB',
  beige:  '#EFEAE0',
  ink:    '#1F261C',
  muted:  '#7C8677',
  error:  '#A03434',
};

type YearOption = { id: number; name: string; is_active?: boolean | number };
type SectionOption = { id: number; name: string; level?: { name: string } | null };
type StudentRow = {
  enrollment_id: number;
  student: { id: number; first_name: string; last_name: string; student_code?: string };
};
type ClubSubscriptionRow = {
  id: number;
  student_id: number;
  student?: { first_name: string; last_name: string; student_code?: string };
  club?: { id: number; name: string; monthly_fee: number };
};

export function MonthlyDiscountsPage() {
  const [category, setCategory] = useState<'tuition' | 'club'>('tuition');
  const [discountType, setDiscountType] = useState<'full_waiver' | 'humanitarian_fixed' | 'normal_monthly'>('normal_monthly');


  const [years, setYears] = useState<YearOption[]>([]);
  const [yearId, setYearId] = useState<number | null>(null);
  const [sections, setSections] = useState<SectionOption[]>([]);
  const [sectionId, setSectionId] = useState<number | null>(null);
  const [students, setStudents] = useState<StudentRow[]>([]);
  const [enrollmentId, setEnrollmentId] = useState<number | null>(null);

  const [subscriptions, setSubscriptions] = useState<ClubSubscriptionRow[]>([]);
  const [subscriptionId, setSubscriptionId] = useState<number | null>(null);

  const [discountsList, setDiscountsList] = useState<MonthlyDiscountItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [monthlyAmount, setMonthlyAmount] = useState('');
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');

  const [cancelModalId, setCancelModalId] = useState<number | null>(null);
  const [cancelReasonInput, setCancelReasonInput] = useState('');

  const errorText = (e: unknown, fallback: string): string =>
    e instanceof ApiError ? e.firstError : fallback;

  // Load active academic years on mount
  useEffect(() => {
    const ctrl = new AbortController();
    (async () => {
      try {
        const ys = await apiFetch<YearOption[]>('/collection/years', { signal: ctrl.signal });
        if (ctrl.signal.aborted) return;
        setYears(ys);
        const active = ys.find((y) => Boolean(y.is_active)) ?? ys[0] ?? null;
        if (active) setYearId(active.id);
      } catch (e) {
        if (ctrl.signal.aborted) return;
        setError(errorText(e, 'تعذّر تحميل السنوات الدراسية'));
      }
    })();
    return () => ctrl.abort();
  }, []);

  // Load sections when year changes
  useEffect(() => {
    if (yearId === null) return;
    const ctrl = new AbortController();
    (async () => {
      try {
        const secs = await apiFetch<SectionOption[]>(`/collection/years/${yearId}/sections`, { signal: ctrl.signal });
        if (ctrl.signal.aborted) return;
        setSections(secs);
        if (secs.length > 0) setSectionId(secs[0].id);
      } catch (e) {
        if (ctrl.signal.aborted) return;
        setError(errorText(e, 'تعذّر تحميل الأقسام'));
      }
    })();
    return () => ctrl.abort();
  }, [yearId]);

  // Load students for section
  useEffect(() => {
    if (sectionId === null) return;
    const ctrl = new AbortController();
    (async () => {
      try {
        const stus = await apiFetch<StudentRow[]>(`/collection/sections/${sectionId}/students`, { signal: ctrl.signal });
        if (ctrl.signal.aborted) return;
        setStudents(stus);
        if (stus.length > 0) setEnrollmentId(stus[0].enrollment_id);
        else setEnrollmentId(null);
      } catch (e) {
        if (ctrl.signal.aborted) return;
        setError(errorText(e, 'تعذّر تحميل قائمة التلاميذ'));
      }
    })();
    return () => ctrl.abort();
  }, [sectionId]);

  // Load club subscriptions when year changes
  useEffect(() => {
    if (yearId === null || category !== 'club') return;
    const ctrl = new AbortController();
    (async () => {
      try {
        const res = await apiFetch<{ data: ClubSubscriptionRow[] }>(`/club-subscriptions?academic_year_id=${yearId}&per_page=100`, { signal: ctrl.signal });
        if (ctrl.signal.aborted) return;
        const subs = res.data ?? [];
        setSubscriptions(subs);
        if (subs.length > 0) setSubscriptionId(subs[0].id);
        else setSubscriptionId(null);
      } catch (e) {
        if (ctrl.signal.aborted) return;
        setError(errorText(e, 'تعذّر تحميل اشتراكات النوادي'));
      }
    })();
    return () => ctrl.abort();
  }, [yearId, category]);

  // Load existing discounts when enrollment or subscription changes
  const loadDiscounts = async (signal?: AbortSignal) => {
    setError('');
    setLoading(true);
    try {
      if (category === 'tuition' && enrollmentId) {
        const res = await fetchTuitionMonthlyDiscounts(enrollmentId, signal);
        if (signal?.aborted) return;
        setDiscountsList(res.discounts);
      } else if (category === 'club' && subscriptionId) {
        const res = await fetchClubMonthlyDiscounts(subscriptionId, signal);
        if (signal?.aborted) return;
        setDiscountsList(res.discounts);
      } else {
        setDiscountsList([]);
      }
    } catch (e) {
      if (signal?.aborted) return;
      setError(errorText(e, 'تعذّر تحميل قائمة التخفيضات'));
    } finally {
      if (!signal?.aborted) setLoading(false);
    }
  };

  useEffect(() => {
    const ctrl = new AbortController();
    loadDiscounts(ctrl.signal);
    return () => ctrl.abort();
  }, [category, enrollmentId, subscriptionId]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setNotice('');

    if (discountType === 'humanitarian_fixed') {
      const amt = parseFloat(monthlyAmount);
      if (isNaN(amt) || amt <= 20) {
        setError('مبلغ التخفيض الإنساني يجب أن يكون أكبر من 20 ديناراً');
        return;
      }
    }

    if (!reason.trim()) {
      setError('سبب التخفيض إجباري');
      return;
    }

    setBusy(true);
    try {
      if (category === 'tuition') {
        if (!enrollmentId) throw new Error('يرجى اختيار تلميذ');
        await createTuitionMonthlyDiscount(enrollmentId, {
          discount_type: discountType,
          monthly_amount: discountType === 'full_waiver' ? null : parseFloat(monthlyAmount),
          reason: reason.trim(),
          notes: notes.trim() || undefined,
        });
      } else {
        if (!subscriptionId) throw new Error('يرجى اختيار اشتراك نادي');
        await createClubMonthlyDiscount(subscriptionId, {
          discount_type: discountType,
          monthly_amount: discountType === 'full_waiver' ? null : parseFloat(monthlyAmount),
          reason: reason.trim(),
          notes: notes.trim() || undefined,
        });
      }

      setNotice('تم تسجيل التخفيض الشهري بنجاح');
      setReason('');
      setMonthlyAmount('');
      setNotes('');
      await loadDiscounts();
    } catch (err) {
      setError(errorText(err, 'فشل تسجيل التخفيض الشهري'));
    } finally {
      setBusy(false);
    }
  };

  const handleCancel = async () => {
    if (!cancelModalId || !cancelReasonInput.trim()) return;
    setBusy(true);
    setError('');
    try {
      if (category === 'tuition') {
        await cancelTuitionMonthlyDiscount(cancelModalId, cancelReasonInput.trim());
      } else {
        await cancelClubMonthlyDiscount(cancelModalId, cancelReasonInput.trim());
      }
      setNotice('تم إلغاء التخفيض بنجاح');
      setCancelModalId(null);
      setCancelReasonInput('');
      await loadDiscounts();
    } catch (err) {
      setError(errorText(err, 'فشل إلغاء التخفيض'));
    } finally {
      setBusy(false);
    }
  };

  const activeDisc = discountsList.find((d) => !d.is_cancelled);

  return (
    <div className="p-6 md:p-8 max-w-6xl mx-auto" dir="rtl">
      {/* ── Header ────────────────────────────────────────────── */}
      <div className="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 text-sm text-slate-500 mb-1">
            <Link to="/discounts" className="hover:underline text-[#3B4A36] font-medium">التخفيضات العادية</Link>
            <span>/</span>
            <span>التخفيضات الشهرية المتكررة</span>
          </div>
          <h1 className="text-2xl font-bold text-slate-800 flex items-center gap-2">
            <HeartHandshake className="text-[#3B4A36]" size={28} />
            التخفيضات الشهرية المتكررة (الإعفاء الكلي والحالات الإنسانية)
          </h1>
          <p className="text-slate-500 text-sm mt-1">
            تسري التخفيضات آلياً من شهر سبتمبر إلى نهاية السنة الدراسية المختارة.
          </p>
        </div>
      </div>

      {/* ── Category & Type Selector Bar ──────────────────────── */}
      <div className="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 mb-8 space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
          <div>
            <span className="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-2">مجال التخفيض:</span>
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => setCategory('tuition')}
                className={`px-4 py-2 rounded-xl text-sm font-bold transition-all ${
                  category === 'tuition'
                    ? 'bg-[#3B4A36] text-white shadow-sm'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                معاليم الدراسة (Tuition)
              </button>
              <button
                type="button"
                onClick={() => setCategory('club')}
                className={`px-4 py-2 rounded-xl text-sm font-bold transition-all ${
                  category === 'club'
                    ? 'bg-[#3B4A36] text-white shadow-sm'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                معاليم النوادي (Clubs)
              </button>
            </div>
          </div>

          <div>
            <span className="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-2">نوع التخفيض:</span>
            <div className="flex items-center gap-2 flex-wrap">
              <button
                type="button"
                onClick={() => setDiscountType('normal_monthly')}
                className={`px-4 py-2 rounded-xl text-sm font-bold transition-all ${
                  discountType === 'normal_monthly'
                    ? 'bg-blue-700 text-white shadow-sm'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                تخفيض عادي شهري (السقف 20%)
              </button>
              <button
                type="button"
                onClick={() => setDiscountType('full_waiver')}
                className={`px-4 py-2 rounded-xl text-sm font-bold transition-all ${
                  discountType === 'full_waiver'
                    ? 'bg-emerald-700 text-white shadow-sm'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                التخفيض الكلي (Full Waiver)
              </button>
              <button
                type="button"
                onClick={() => setDiscountType('humanitarian_fixed')}
                className={`px-4 py-2 rounded-xl text-sm font-bold transition-all ${
                  discountType === 'humanitarian_fixed'
                    ? 'bg-amber-700 text-white shadow-sm'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                تخفيض حالة إنسانية (&gt; 20 د)
              </button>
            </div>
          </div>

        </div>

        {/* ── Selection Dropdowns ────────────────────────────── */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <label className="space-y-1 text-xs font-semibold text-slate-700">
            <span>السنة الدراسية</span>
            <select
              value={yearId ?? ''}
              onChange={(e) => setYearId(Number(e.target.value))}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-[#3B4A36]"
            >
              {years.map((y) => (
                <option key={y.id} value={y.id}>{y.name}</option>
              ))}
            </select>
          </label>

          {category === 'tuition' ? (
            <>
              <label className="space-y-1 text-xs font-semibold text-slate-700">
                <span>القسم</span>
                <select
                  value={sectionId ?? ''}
                  onChange={(e) => setSectionId(Number(e.target.value))}
                  className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-[#3B4A36]"
                >
                  {sections.map((s) => (
                    <option key={s.id} value={s.id}>{s.level?.name ?? ''} {s.name}</option>
                  ))}
                </select>
              </label>

              <label className="space-y-1 text-xs font-semibold text-slate-700">
                <span>التلميذ</span>
                <select
                  value={enrollmentId ?? ''}
                  onChange={(e) => setEnrollmentId(Number(e.target.value))}
                  className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-[#3B4A36]"
                >
                  {students.map((st) => (
                    <option key={st.enrollment_id} value={st.enrollment_id}>
                      {st.student.first_name} {st.student.last_name} ({st.student.student_code ?? '—'})
                    </option>
                  ))}
                </select>
              </label>
            </>
          ) : (
            <label className="space-y-1 text-xs font-semibold text-slate-700 col-span-2">
              <span>اشتراك النادي (التلميذ - النادي)</span>
              <select
                value={subscriptionId ?? ''}
                onChange={(e) => setSubscriptionId(Number(e.target.value))}
                className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-[#3B4A36]"
              >
                {subscriptions.map((sub) => (
                  <option key={sub.id} value={sub.id}>
                    {sub.student?.first_name} {sub.student?.last_name} — {sub.club?.name} ({sub.club?.monthly_fee} د/شهرياً)
                  </option>
                ))}
              </select>
            </label>
          )}
        </div>
      </div>

      {/* ── Error & Notice Alerts ────────────────────────────── */}
      {error && (
        <div className="mb-6 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
          <AlertCircle size={18} className="shrink-0" />
          <span>{error}</span>
        </div>
      )}
      {notice && (
        <div className="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">
          <CheckCircle2 size={18} className="shrink-0" />
          <span>{notice}</span>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        {/* ── Form Column ──────────────────────────────────────── */}
        <div className="lg:col-span-1 bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
          <h2 className="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">
            <DollarSign size={18} className="text-[#3B4A36]" />
            منح تخفيض جديد
          </h2>

          <form onSubmit={handleCreate} className="space-y-4">
            {discountType === 'normal_monthly' ? (
              <label className="block space-y-1 text-xs font-semibold text-slate-700">
                <span>المبلغ الشهري الخصم (د.ت)</span>
                <input
                  type="number"
                  step="0.01"
                  min="0.01"
                  required
                  placeholder="مثلاً 16.00"
                  value={monthlyAmount}
                  onChange={(e) => setMonthlyAmount(e.target.value)}
                  className="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-[#3B4A36]"
                />
                <span className="text-[11px] text-blue-700 block">تخفيض عادي شهري متكرر، لا يتجاوز 20% من المعلوم الشهري.</span>
              </label>
            ) : discountType === 'humanitarian_fixed' ? (
              <label className="block space-y-1 text-xs font-semibold text-slate-700">
                <span>المبلغ الشهري الخصم (د.ت)</span>
                <input
                  type="number"
                  step="0.01"
                  min="20.01"
                  required
                  placeholder="مثلاً 50.00"
                  value={monthlyAmount}
                  onChange={(e) => setMonthlyAmount(e.target.value)}
                  className="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-[#3B4A36]"
                />
                <span className="text-[11px] text-amber-700 block">يجب أن يكون أكبر من 20 د وأقل من المعلوم الشهري.</span>
              </label>
            ) : (
              <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-xs text-emerald-800 flex items-center gap-2">
                <Info size={16} className="shrink-0 text-emerald-600" />
                <span>إعفاء كلي تام: يكون صافي المعلوم المطلوب شهرياً 0 د.ت.</span>
              </div>
            )}


            <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-600 flex items-center gap-2">
              <Calendar size={16} className="shrink-0 text-slate-400" />
              <span>فترة التطبيق: تلقائياً من <strong>سبتمبر</strong> إلى <strong>نهاية السنة الدراسية</strong>.</span>
            </div>

            <label className="block space-y-1 text-xs font-semibold text-slate-700">
              <span>سبب التخفيض <span className="text-red-500">*</span></span>
              <input
                type="text"
                required
                placeholder="سبب الإعفاء أو الحالة الإنسانية..."
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-[#3B4A36]"
              />
            </label>

            <label className="block space-y-1 text-xs font-semibold text-slate-700">
              <span>ملاحظات إضافية</span>
              <textarea
                rows={2}
                placeholder="ملاحظات توثيقية اختياري..."
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#3B4A36]"
              />
            </label>

            <button
              type="submit"
              disabled={busy || (category === 'tuition' ? !enrollmentId : !subscriptionId)}
              className="w-full py-3 px-4 rounded-xl bg-[#3B4A36] text-white font-bold text-sm shadow-sm hover:bg-[#2e3b2a] transition disabled:opacity-50"
            >
              {busy ? 'جارٍ التسجيل…' : 'تسجيل التخفيض الشهري'}
            </button>
          </form>
        </div>

        {/* ── History Column ───────────────────────────────────── */}
        <div className="lg:col-span-2 bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
          <h2 className="text-base font-bold text-slate-800 mb-4">
            سجل التخفيضات الشهرية المسجلة
          </h2>

          {loading ? (
            <div className="py-12 text-center text-sm text-slate-400">جارٍ تحميل السجل…</div>
          ) : discountsList.length === 0 ? (
            <div className="py-12 text-center text-sm text-slate-400">لا توجد تخفيضات شهرية مسجلة لهذا التلميذ/الاشتراك.</div>
          ) : (
            <div className="space-y-3">
              {discountsList.map((disc) => (
                <div
                  key={disc.id}
                  className={`p-4 rounded-xl border transition ${
                    disc.is_cancelled
                      ? 'bg-slate-50 border-slate-200 opacity-60'
                      : 'bg-white border-slate-200 shadow-sm'
                  }`}
                >
                  <div className="flex items-center justify-between mb-2">
                    <div className="flex items-center gap-2">
                      <span className={`px-2.5 py-1 rounded-md text-xs font-bold ${
                        disc.discount_type === 'full_waiver'
                          ? 'bg-emerald-100 text-emerald-800'
                          : 'bg-amber-100 text-amber-800'
                      }`}>
                        {disc.discount_type === 'full_waiver' ? 'تخفيض كلي (Full Waiver)' : `تخفيض إنساني: ${disc.monthly_amount} د/شهرياً`}
                      </span>
                      {disc.is_cancelled && (
                        <span className="px-2 py-0.5 rounded bg-red-100 text-red-700 text-xs font-semibold">ملغى</span>
                      )}
                    </div>
                    {!disc.is_cancelled && (
                      <button
                        type="button"
                        onClick={() => setCancelModalId(disc.id)}
                        className="text-xs text-red-600 font-semibold hover:underline"
                      >
                        إلغاء التخفيض
                      </button>
                    )}
                  </div>

                  <p className="text-sm font-semibold text-slate-800">{disc.reason}</p>
                  {disc.notes && <p className="text-xs text-slate-500 mt-1">{disc.notes}</p>}

                  <div className="mt-3 pt-2 border-t border-slate-100 text-[11px] text-slate-400 flex flex-wrap justify-between gap-2">
                    <span>الفترة: من {disc.start_month} إلى {disc.end_month}</span>
                    <span>بواسطة: {disc.created_by ?? 'المشرف'}</span>
                    {disc.is_cancelled && (
                      <span className="text-red-500 font-medium">سبب الإلغاء: {disc.cancellation_reason}</span>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* ── Cancel Modal ──────────────────────────────────────── */}
      {cancelModalId && (
        <div className="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl p-6 max-w-md w-full shadow-xl space-y-4" dir="rtl">
            <h3 className="text-lg font-bold text-slate-800 flex items-center gap-2">
              <ShieldAlert className="text-red-600" size={20} />
              إلغاء التخفيض الشهري
            </h3>
            <p className="text-xs text-slate-500">
              سيتم التراجع عن التخفيض وعودة المستحقّ كما كان. سيبقى أثر التخفيض وإلغائه مقروءاً في السجلات المالية.
            </p>
            <label className="block space-y-1 text-xs font-semibold text-slate-700">
              <span>سبب الإلغاء <span className="text-red-500">*</span></span>
              <input
                type="text"
                required
                placeholder="سبب الإلغاء التوثيقي..."
                value={cancelReasonInput}
                onChange={(e) => setCancelReasonInput(e.target.value)}
                className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#3B4A36]"
              />
            </label>
            <div className="flex gap-2 justify-end pt-2">
              <button
                type="button"
                onClick={() => setCancelModalId(null)}
                className="px-4 py-2 rounded-xl text-xs font-semibold bg-slate-100 text-slate-600 hover:bg-slate-200"
              >
                إلغاء
              </button>
              <button
                type="button"
                disabled={busy || !cancelReasonInput.trim()}
                onClick={handleCancel}
                className="px-4 py-2 rounded-xl text-xs font-bold bg-red-600 text-white hover:bg-red-700 disabled:opacity-50"
              >
                {busy ? 'جارٍ الإلغاء…' : 'تأكيد الإلغاء'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
