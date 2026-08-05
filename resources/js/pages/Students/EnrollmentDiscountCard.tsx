import { useEffect, useState } from 'react';
import { AlertCircle, BadgePercent, Ban, Loader2, Plus } from 'lucide-react';
import { useAuth } from '../../contexts/AuthContext';
import {
  cancelDiscount,
  createDiscount,
  fetchEnrollmentDiscount,
  isActiveDiscount,
  type DiscountEnrollmentSummary,
  type EnrollmentDiscount,
} from '../../api/discounts';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  error: '#A03434',
  errorBg: '#FDECEC',
  gold: '#7C6B42',
  goldBg: '#EFEAE0',
};

function money(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—';
  return `${Number(value).toFixed(3)} د`;
}

/**
 * بطاقة التخفيض السنوي لتسجيل واحد.
 *
 * التخفيض سعر مخفّض لا دَين: يُنقص أصل معاليم السنة كلّها مرّة واحدة، سقفه 20%.
 * الصلاحية waive_fees وحدها تُظهر أزرار المنح والإلغاء؛ من لا يملكها يرى الأرقام
 * قراءةً فقط. الإلغاء يُبقي الأثر ولا يحذفه، فيبقى السجل مقروءاً كبقية الوثائق.
 */
export function EnrollmentDiscountCard({
  enrollmentId,
  yearLabel,
}: {
  enrollmentId: number;
  yearLabel?: string;
}) {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('waive_fees');

  const [summary, setSummary] = useState<DiscountEnrollmentSummary | null>(null);
  const [discounts, setDiscounts] = useState<EnrollmentDiscount[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [showForm, setShowForm] = useState(false);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [appliedDate, setAppliedDate] = useState(new Date().toISOString().slice(0, 10));
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError('');
    fetchEnrollmentDiscount(enrollmentId)
      .then((result) => {
        if (!active) return;
        setSummary(result.enrollment);
        setDiscounts(result.discounts);
      })
      .catch((e) => {
        if (active) setError(e instanceof Error ? e.message : 'تعذّر تحميل التخفيض');
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [enrollmentId]);

  const activeDiscount = discounts.find(isActiveDiscount) ?? null;
  const history = discounts.filter((d) => !isActiveDiscount(d));

  function apply(result: { enrollment: DiscountEnrollmentSummary | null; discount: EnrollmentDiscount }, replaceList: boolean) {
    if (result.enrollment) setSummary(result.enrollment);
    setDiscounts((prev) => {
      if (replaceList) return [result.discount, ...prev];
      return prev.map((d) => (d.id === result.discount.id ? result.discount : d));
    });
  }

  async function handleGrant() {
    const value = parseFloat(amount || '0') || 0;
    if (value <= 0) {
      setError('أدخل مبلغ تخفيض أكبر من صفر');
      return;
    }
    if (reason.trim().length < 3) {
      setError('أدخل سبباً واضحاً للتخفيض (3 أحرف على الأقل)');
      return;
    }
    setSaving(true);
    setError('');
    try {
      const result = await createDiscount(enrollmentId, value, reason.trim(), appliedDate);
      apply(result, true);
      setShowForm(false);
      setAmount('');
      setReason('');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'تعذّر تسجيل التخفيض');
    } finally {
      setSaving(false);
    }
  }

  async function handleCancel(discount: EnrollmentDiscount) {
    const cancelReason = window.prompt('سبب إلغاء التخفيض (إلزامي):');
    if (cancelReason === null) return;
    if (cancelReason.trim().length < 3) {
      alert('يرجى إدخال سبب واضح (3 أحرف على الأقل)');
      return;
    }
    try {
      const result = await cancelDiscount(discount.id, cancelReason.trim());
      apply(result, false);
    } catch (e) {
      alert(e instanceof Error ? e.message : 'تعذّر إلغاء التخفيض');
    }
  }

  return (
    <div className="rounded-2xl border p-5" style={{ borderColor: C.line, background: '#fff' }}>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2" style={{ color: C.gold }}>
          <BadgePercent size={18} />
          <span className="text-sm font-semibold">
            التخفيض السنوي{yearLabel ? ` — ${yearLabel}` : ''}
          </span>
        </div>
        {canManage && !activeDiscount && !showForm && !loading && (
          <button
            type="button"
            onClick={() => setShowForm(true)}
            className="inline-flex items-center gap-1 rounded-xl px-3 py-1.5 text-sm text-white"
            style={{ background: C.forest }}
          >
            <Plus size={15} /> منح تخفيض
          </button>
        )}
      </div>

      {error && (
        <div
          className="mb-3 flex items-center gap-2 rounded-xl p-3 text-sm"
          style={{ background: C.errorBg, color: C.error }}
        >
          <AlertCircle size={16} /> {error}
        </div>
      )}

      {loading ? (
        <div className="flex items-center gap-2 text-sm" style={{ color: C.muted }}>
          <Loader2 size={16} className="animate-spin" /> جارٍ التحميل…
        </div>
      ) : (
        <>
          {summary && (
            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className="rounded-xl p-3" style={{ background: C.sage }}>
                <p className="text-xs" style={{ color: C.muted }}>معاليم السنة (قبل التخفيض)</p>
                <p className="text-lg font-bold" style={{ color: C.ink }}>{money(summary.annual_fees)}</p>
              </div>
              <div className="rounded-xl p-3" style={{ background: C.goldBg }}>
                <p className="text-xs" style={{ color: C.muted }}>التخفيض السارِي</p>
                <p className="text-lg font-bold" style={{ color: C.gold }}>{money(summary.active_discount)}</p>
                <p className="mt-1 text-[11px]" style={{ color: C.muted }}>السقف الأقصى: {money(summary.discount_cap)} (20%)</p>
              </div>
              <div className="rounded-xl p-3" style={{ background: C.sage }}>
                <p className="text-xs" style={{ color: C.muted }}>الصافي (بعد التخفيض)</p>
                <p className="text-lg font-bold" style={{ color: C.forest }}>{money(summary.net_fees)}</p>
              </div>
            </div>
          )}

          {activeDiscount && (
            <div
              className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border p-3"
              style={{ borderColor: C.line }}
            >
              <div className="text-sm" style={{ color: C.ink }}>
                <span className="font-semibold">{money(activeDiscount.amount)}</span>
                {activeDiscount.percentage !== null && (
                  <span className="text-xs" style={{ color: C.muted }}> ({activeDiscount.percentage}%)</span>
                )}
                <span className="mx-2" style={{ color: C.line }}>|</span>
                <span style={{ color: C.muted }}>{activeDiscount.reason}</span>
                {activeDiscount.applied_date && (
                  <span className="mr-2 text-xs" style={{ color: C.muted }} dir="ltr"> {activeDiscount.applied_date}</span>
                )}
              </div>
              {canManage && (
                <button
                  type="button"
                  onClick={() => handleCancel(activeDiscount)}
                  className="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs"
                  style={{ borderColor: '#FCA5A5', color: C.error }}
                >
                  <Ban size={14} /> إلغاء
                </button>
              )}
            </div>
          )}

          {!activeDiscount && !showForm && (
            <p className="text-sm" style={{ color: C.muted }}>لا يوجد تخفيض سارٍ لهذا التسجيل.</p>
          )}

          {canManage && showForm && !activeDiscount && (
            <div className="mb-3 rounded-xl border p-4" style={{ borderColor: C.line }}>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                  <label className="text-xs font-semibold" style={{ color: C.ink }}>المبلغ (د.ت)</label>
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                    className="mt-1 w-full rounded-xl border px-3 py-2 text-sm"
                    style={{ borderColor: C.line, direction: 'ltr' }}
                    placeholder="مثال: 20"
                  />
                </div>
                <div>
                  <label className="text-xs font-semibold" style={{ color: C.ink }}>تاريخ التطبيق</label>
                  <input
                    type="date"
                    value={appliedDate}
                    max={new Date().toISOString().slice(0, 10)}
                    onChange={(e) => setAppliedDate(e.target.value)}
                    className="mt-1 w-full rounded-xl border px-3 py-2 text-sm"
                    style={{ borderColor: C.line }}
                  />
                </div>
                <div>
                  <label className="text-xs font-semibold" style={{ color: C.ink }}>السبب</label>
                  <input
                    type="text"
                    maxLength={500}
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    className="mt-1 w-full rounded-xl border px-3 py-2 text-sm"
                    style={{ borderColor: C.line }}
                    placeholder="مثال: منحة أخوة"
                  />
                </div>
              </div>
              <div className="mt-3 flex items-center gap-2">
                <button
                  type="button"
                  onClick={handleGrant}
                  disabled={saving}
                  className="rounded-xl px-4 py-2 text-sm font-bold text-white disabled:opacity-60"
                  style={{ background: C.forest }}
                >
                  {saving ? 'جارٍ الحفظ…' : 'حفظ التخفيض'}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setShowForm(false);
                    setError('');
                  }}
                  className="rounded-xl border px-4 py-2 text-sm"
                  style={{ borderColor: C.line, color: C.muted }}
                >
                  إلغاء
                </button>
              </div>
              {summary && summary.discount_cap > 0 && (
                <p className="mt-2 text-[11px]" style={{ color: C.muted }}>
                  الحدّ الأقصى المسموح: {money(summary.discount_cap)} (20% من معاليم السنة).
                </p>
              )}
            </div>
          )}

          {history.length > 0 && (
            <div className="mt-2">
              <p className="mb-2 text-xs font-semibold" style={{ color: C.muted }}>تخفيضات ملغاة</p>
              <div className="space-y-1">
                {history.map((d) => (
                  <div key={d.id} className="rounded-lg border px-3 py-2 text-xs" style={{ borderColor: C.line, color: C.muted }}>
                    <span className="line-through">{money(d.amount)}</span>
                    <span className="mx-2">— {d.reason}</span>
                    {d.cancellation_reason && <span>· سبب الإلغاء: {d.cancellation_reason}</span>}
                  </div>
                ))}
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}

export default EnrollmentDiscountCard;
