import { useEffect, useState } from 'react';
import { BadgePercent, Pencil, Trash2, XCircle } from 'lucide-react';
import { apiFetch, ApiError } from '../../api/http';
import {
  fetchEnrollmentDiscount,
  createDiscount,
  cancelDiscount,
  isActiveDiscount,
  type DiscountShow,
} from '../../api/discounts';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  beige: '#EFEAE0',
  ink: '#1F261C',
  muted: '#7C8677',
  error: '#A03434',
  errorBg: '#FDECEC',
};

type YearOption = { id: number; name: string; is_active?: boolean | number };

type SectionOption = {
  id: number;
  name: string;
  students_count?: number;
  level?: { name: string } | null;
};

type StudentRow = {
  enrollment_id: number;
  student: { id: number; first_name: string; last_name: string; student_code?: string };
};

/** قيمة نقدية معزولة الاتجاه حتى لا تنقلب إشارة السالب في واجهة RTL. */
function Money({ value }: { value: number | null | undefined }) {
  const negative = Number(value ?? 0) < 0;
  return (
    <bdi dir="ltr" className={negative ? 'text-[#A03434]' : undefined}>
      {`${Number(value ?? 0).toFixed(3)} د`}
    </bdi>
  );
}

/**
 * إدارة التخفيضات الشهرية الثابتة — لصاحب النظام فقط (waive_fees).
 *
 * التدفّق: سنة دراسية ← قسم (من التمهيدي إلى السادسة) ← تلميذ ← بطاقة التخفيض.
 * «التعديل» = إلغاء موثّق للساري ثم إنشاء جديد، و«الحذف» = إلغاء موثّق بسبب؛
 * فالمستندات المالية لا تُمحى أبداً بل تُلغى وتبقى في السجلّ، كالدفعات والرواتب.
 */
export function DiscountsPage() {
  const [years, setYears] = useState<YearOption[]>([]);
  const [yearId, setYearId] = useState<number | null>(null);
  const [sections, setSections] = useState<SectionOption[]>([]);
  const [sectionId, setSectionId] = useState<number | null>(null);
  const [students, setStudents] = useState<StudentRow[]>([]);
  const [panel, setPanel] = useState<DiscountShow | null>(null);
  const [loadingLists, setLoadingLists] = useState(false);
  const [panelLoading, setPanelLoading] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [appliedDate, setAppliedDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [editMode, setEditMode] = useState(false);
  const [deleteMode, setDeleteMode] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [busy, setBusy] = useState(false);

  const activeDiscount = panel?.discounts.find(isActiveDiscount) ?? null;

  const errorText = (e: unknown, fallback: string): string =>
    e instanceof ApiError ? e.firstError : fallback;

  const sectionLabel = (s: SectionOption): string => `${s.level?.name ?? ''} ${s.name}`.trim();

  // السنوات عند الإقلاع، ثم اختيار النشطة تلقائياً
  useEffect(() => {
    (async () => {
      try {
        const ys = await apiFetch<YearOption[]>('/collection/years');
        setYears(ys);
        const active = ys.find((y) => Boolean(y.is_active)) ?? ys[0] ?? null;
        if (active) setYearId(active.id);
      } catch (e) {
        setError(errorText(e, 'تعذّر تحميل السنوات الدراسية'));
      }
    })();
  }, []);

  // أقسام السنة المختارة — مرتّبة من الخادم: التحضيرية أولاً ثم الأولى إلى السادسة
  useEffect(() => {
    if (yearId === null) return;
    (async () => {
      setLoadingLists(true);
      setSections([]);
      setSectionId(null);
      setStudents([]);
      setPanel(null);
      try {
        setSections(await apiFetch<SectionOption[]>('/collection/years/' + yearId + '/sections'));
      } catch (e) {
        setError(errorText(e, 'تعذّر تحميل الأقسام'));
      } finally {
        setLoadingLists(false);
      }
    })();
  }, [yearId]);

  // تلاميذ القسم المختار في السنة المختارة
  useEffect(() => {
    if (sectionId === null || yearId === null) return;
    (async () => {
      setLoadingLists(true);
      setStudents([]);
      setPanel(null);
      try {
        setStudents(
          await apiFetch<StudentRow[]>('/collection/sections/' + sectionId + '/students', {
            params: { year_id: yearId },
          }),
        );
      } catch (e) {
        setError(errorText(e, 'تعذّر تحميل تلاميذ القسم'));
      } finally {
        setLoadingLists(false);
      }
    })();
  }, [sectionId, yearId]);

  const loadDiscount = async (id: number) => {
    setPanelLoading(true);
    setError('');
    setNotice('');
    setEditMode(false);
    setDeleteMode(false);
    setCancelReason('');
    try {
      setPanel(await fetchEnrollmentDiscount(id));
    } catch (e) {
      setError(errorText(e, 'تعذّر تحميل التخفيض'));
    } finally {
      setPanelLoading(false);
    }
  };

  const startEdit = () => {
    if (!activeDiscount) return;
    setAmount(String(activeDiscount.amount));
    setReason(activeDiscount.reason);
    setAppliedDate(activeDiscount.applied_date ?? new Date().toISOString().slice(0, 10));
    setEditMode(true);
    setDeleteMode(false);
    setError('');
    setNotice('');
  };

  const submit = async () => {
    if (!panel) return;
    const value = Number(amount);
    if (!Number.isFinite(value) || value <= 0) {
      setError('أدخل مبلغاً صحيحاً أكبر من صفر');
      return;
    }
    if (value > panel.enrollment.discount_cap) {
      setError('المبلغ يتجاوز سقف التخفيض المسموح (20 د في الشهر)');
      return;
    }
    if (!reason.trim()) {
      setError('سبب التخفيض إجباري');
      return;
    }
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const wasEdit = editMode && activeDiscount;
      if (wasEdit && activeDiscount) {
        // التعديل لا يمحو القديم: يُلغى موثّقاً ويُنشأ الجديد فوقه.
        await cancelDiscount(activeDiscount.id, 'تعديل التخفيض');
      }
      await createDiscount(panel.enrollment.id, value, reason.trim(), appliedDate);
      await loadDiscount(panel.enrollment.id);
      setAmount('');
      setReason('');
      setNotice(wasEdit ? 'تم تعديل التخفيض — السابق أُلغي ووُثّق في السجلّ' : 'تم تسجيل التخفيض بنجاح');
    } catch (e) {
      setError(errorText(e, 'تعذّر حفظ التخفيض'));
    } finally {
      setBusy(false);
    }
  };

  const doDelete = async () => {
    if (!panel || !activeDiscount) return;
    if (!cancelReason.trim()) {
      setError('سبب الحذف إجباري — التخفيض لا يُمحى بل يُلغى ويوثَّق');
      return;
    }
    setBusy(true);
    setError('');
    setNotice('');
    try {
      await cancelDiscount(activeDiscount.id, cancelReason.trim());
      await loadDiscount(panel.enrollment.id);
      setCancelReason('');
      setDeleteMode(false);
      setNotice('تم حذف التخفيض — وُثّق كملغى في السجلّ، وعاد المستحقّ كاملاً');
    } catch (e) {
      setError(errorText(e, 'تعذّر حذف التخفيض'));
    } finally {
      setBusy(false);
    }
  };

  const inputCls =
    'w-full rounded-xl border border-[#E3E7DE] px-4 py-2 text-sm outline-none bg-white';

  return (
    <div className="p-6 md:p-8" dir="rtl">
      <div className="mb-6">
        <h1 className="text-2xl font-bold" style={{ color: C.ink }}>
          التخفيضات
        </h1>
        <p className="mt-1 text-sm" style={{ color: C.muted }}>
          تخفيض شهري بمبلغ ثابت لا يتجاوز 20 ديناراً لكل تلميذ، يُخصم من معلوم الدفع الشهري ويبقى
          سارياً إلى آخر السنة. لا يرى هذه الصفحة إلا صاحب النظام، ويظهر للقابض في الوصل كجزء إداري.
        </p>
      </div>

      {error && (
        <div
          className="rounded-2xl p-4 mb-4 flex items-start gap-2 text-sm"
          style={{ backgroundColor: C.errorBg, color: C.error }}
        >
          <XCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {notice && (
        <div className="rounded-2xl p-4 mb-4 text-sm" style={{ backgroundColor: C.sage, color: C.forest }}>
          {notice}
        </div>
      )}

      {/* الاختيار المتسلسل: سنة ← قسم ← تلميذ */}
      <div className="bg-white rounded-[22px] p-5 border border-[#EDF1E8] mb-5">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label className="block text-sm mb-2" style={{ color: C.muted }}>
              السنة الدراسية
            </label>
            <select
              className={inputCls}
              value={yearId ?? ''}
              onChange={(e) => setYearId(e.target.value === '' ? null : Number(e.target.value))}
            >
              {years.length === 0 && <option value="">لا سنوات متاحة</option>}
              {years.map((y) => (
                <option key={y.id} value={y.id}>
                  {y.name}
                  {Boolean(y.is_active) ? ' (النشطة)' : ''}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-sm mb-2" style={{ color: C.muted }}>
              القسم
            </label>
            <select
              className={inputCls}
              value={sectionId ?? ''}
              onChange={(e) => setSectionId(e.target.value === '' ? null : Number(e.target.value))}
              disabled={sections.length === 0}
            >
              <option value="">اختر القسم…</option>
              {sections.map((s) => (
                <option key={s.id} value={s.id}>
                  {sectionLabel(s)}
                  {typeof s.students_count === 'number' ? ` (${s.students_count})` : ''}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-sm mb-2" style={{ color: C.muted }}>
              التلميذ
            </label>
            <select
              className={inputCls}
              value={panel?.enrollment.id ?? ''}
              onChange={(e) => {
                if (e.target.value !== '') void loadDiscount(Number(e.target.value));
              }}
              disabled={students.length === 0}
            >
              <option value="">
                {sectionId === null ? 'اختر القسم أولاً…' : students.length === 0 ? 'لا تلاميذ في هذا القسم' : 'اختر التلميذ…'}
              </option>
              {students.map((r) => (
                <option key={r.enrollment_id} value={r.enrollment_id}>
                  {r.student.first_name} {r.student.last_name}
                  {r.student.student_code ? ` — ${r.student.student_code}` : ''}
                </option>
              ))}
            </select>
          </div>
        </div>
        {loadingLists && (
          <p className="mt-3 text-sm" style={{ color: C.muted }}>
            جارٍ التحميل…
          </p>
        )}
      </div>

      {panelLoading && (
        <p className="text-sm" style={{ color: C.muted }}>
          جارٍ تحميل التخفيض…
        </p>
      )}

      {panel && !panelLoading && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
          {/* كارت قيمة التخفيضات */}
          <div className="bg-white rounded-[22px] p-6 border border-[#EDF1E8]">
            <h2 className="font-bold text-lg mb-4 flex items-center gap-2" style={{ color: C.ink }}>
              <BadgePercent size={20} style={{ color: C.forest }} />
              قيمة التخفيضات
            </h2>
            <div className="space-y-2 text-sm">
              <div className="flex items-center justify-between">
                <span style={{ color: C.muted }}>المعاليم السنوية</span>
                <strong style={{ color: C.ink }}>
                  <Money value={panel.enrollment.annual_fees} />
                </strong>
              </div>
              <div className="flex items-center justify-between">
                <span style={{ color: C.muted }}>السقف الشهري (20 د)</span>
                <strong style={{ color: C.ink }}>
                  <Money value={panel.enrollment.discount_cap} />
                </strong>
              </div>
              <div className="flex items-center justify-between">
                <span style={{ color: C.muted }}>التخفيض الشهري الساري</span>
                <strong style={{ color: C.forest }}>
                  <Money value={panel.enrollment.active_discount} />
                </strong>
              </div>
              <div className="flex items-center justify-between border-t border-[#F0F2EC] pt-2">
                <span style={{ color: C.muted }}>الصافي السنوي بعد التخفيض</span>
                <strong style={{ color: C.ink }}>
                  <Money value={panel.enrollment.net_fees} />
                </strong>
              </div>
            </div>
          </div>

          {/* إضافة / تعديل / حذف */}
          <div className="bg-white rounded-[22px] p-6 border border-[#EDF1E8]">
            <h2 className="font-bold text-lg mb-4" style={{ color: C.ink }}>
              {activeDiscount ? (editMode ? 'تعديل التخفيض' : deleteMode ? 'حذف التخفيض' : 'التخفيض الشهري الساري') : 'إضافة تخفيض'}
            </h2>

            {activeDiscount && !editMode && !deleteMode && (
              <>
                <p className="text-sm mb-1" style={{ color: C.ink }}>
                  <Money value={activeDiscount.amount} /> — {activeDiscount.reason}
                </p>
                <p className="text-xs mb-4" style={{ color: C.muted }}>
                  {activeDiscount.applied_date ?? ''}
                  {activeDiscount.created_by ? ' — ' + activeDiscount.created_by : ''}
                </p>
                <div className="flex gap-2">
                  <button
                    onClick={startEdit}
                    className="flex items-center gap-2 rounded-xl px-4 py-2 text-sm text-white"
                    style={{ backgroundColor: C.forest }}
                  >
                    <Pencil size={16} />
                    تعديل
                  </button>
                  <button
                    onClick={() => {
                      setDeleteMode(true);
                      setError('');
                      setNotice('');
                    }}
                    className="flex items-center gap-2 rounded-xl px-4 py-2 text-sm text-white"
                    style={{ backgroundColor: C.error }}
                  >
                    <Trash2 size={16} />
                    حذف
                  </button>
                </div>
              </>
            )}

            {activeDiscount && deleteMode && (
              <>
                <p className="text-sm mb-3" style={{ color: C.muted }}>
                  حذف التخفيض الشهري الساري (<Money value={activeDiscount.amount} />) — لا يُمحى من السجلّ بل يُلغى
                  موثّقاً بسببه، ويعود المستحقّ كاملاً.
                </p>
                <input
                  value={cancelReason}
                  onChange={(e) => setCancelReason(e.target.value)}
                  placeholder="سبب الحذف (إجباري)…"
                  className={inputCls + ' mb-2'}
                />
                <div className="flex gap-2">
                  <button
                    onClick={() => void doDelete()}
                    disabled={buzy}
                    className="rounded-xl px-4 py-2 text-sm text-white disabled:opacity-50"
                    style={{ backgroundColor: C.error }}
                  >
                    {busy ? 'جارٍ الحذف…' : 'تأكيد الحذف'}
                  </button>
                  <button
                    onClick={() => {
                      setDeleteMode(false);
                      setCancelReason('');
                    }}
                    className="rounded-xl px-4 py-2 text-sm"
                    style={{ color: C.muted }}
                  >
                    رجوع
                  </button>
                </div>
              </>
            )}

            {(!activeDiscount || editMode) && (
              <>
                <input
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  inputMode="decimal"
                  placeholder="المبلغ الشهري (د)"
                  className={inputCls + ' mb-2'}
                />
                <input
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="سبب التخفيض (إجباري)…"
                  className={inputCls + ' mb-2'}
                />
                <input
                  type="date"
                  value={appliedDate}
                  max={new Date().toISOString().slice(0, 10)}
                  onChange={(e) => setAppliedDate(e.target.value)}
                  className={inputCls + ' mb-3'}
                />
                <div className="flex gap-2">
                  <button
                    onClick={() => void submit()}
                    disabled={busy}
                    className="rounded-xl px-4 py-2 text-sm text-white disabled:opacity-50"
                    style={{ backgroundColor: C.forest }}
                  >
                    {busy ? 'جارٍ الحفظ…' : editMode ? 'حفظ التعديل' : 'تسجيل التخفيض'}
                  </button>
                  {editMode && (
                    <button
                      onClick={() => setEditMode(false)}
                      className="rounded-xl px-4 py-2 text-sm"
                      style={{ color: C.muted }}
                    >
                      رجوع
                    </button>
                  )}
                </div>
              </>
            )}
          </div>

          {/* السجلّ — مقروء لصاحب النظام فقط */}
          <div className="bg-white rounded-[22px] p-6 border border-[#EDF1E8]">
            <h2 className="font-bold text-lg mb-4" style={{ color: C.ink }}>
              سجلّ التخفيضات
            </h2>
            {panel.discounts.length === 0 && (
              <p className="text-sm" style={{ color: C.muted }}>
                لا تخفيضات بعد على هذا الترسيم.
              </p>
            )}
            <ul className="space-y-3 text-sm">
              {panel.discounts.map((d) => (
                <li key={d.id} className="rounded-xl border border-[#F0F2EC] p-3">
                  <div className="flex items-center justify-between">
                    <strong style={{ color: d.is_cancelled ? C.muted : C.forest }}>
                      <Money value={d.amount} />
                    </strong>
                    <span className="text-xs" style={{ color: d.is_cancelled ? C.error : C.forest }}>
                      {d.is_cancelled ? 'ملغى' : 'سارٍ'}
                    </span>
                  </div>
                  <p className="mt-1" style={{ color: C.ink }}>
                    {d.reason}
                  </p>
                  <p className="mt-1 text-xs" style={{ color: C.muted }}>
                    {d.applied_date ?? ''}
                    {d.created_by ? ' — ' + d.created_by : ''}
                  </p>
                  {d.is_cancelled && d.cancellation_reason && (
                    <p className="mt-1 text-xs" style={{ color: C.error }}>
                      سبب الإلغاء: {d.cancellation_reason}
                    </p>
                  )}
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}
    </div>
  );
}
