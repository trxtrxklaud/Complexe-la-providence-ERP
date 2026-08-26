import { useEffect, useState } from 'react';
import { paymentsApi } from '../../api/payments';
import { useAuth } from '../../contexts/AuthContext';
import type { Payment, PaginatedResponse, PaymentMethod } from '../../types';
import { TableRowsSkeleton } from '../../components/DataSkeleton';
import { AlertCircle, Receipt, ChevronRight, ChevronLeft, RotateCcw } from 'lucide-react';

const C = {
    forest: '#3B4A36',
    deep:   '#2E3B2A',
    sage:   '#E3EBDB',
    ink:    '#1F261C',
    muted:  '#7C8677',
    line:   '#EDF1E8',
};

/** أسماء الطرق بالعربية — نفس خريطة HistoriquePage للاتساق. */
const METHOD_LABELS: Record<PaymentMethod, string> = {
    cash:          'نقداً',
    bank_transfer: 'تحويل بنكي',
    check:         'شيك',
    card:          'بطاقة',
};

function fmtDay(s?: string | null): string {
    if (!s) return '—';
    const d = new Date(s);
    if (Number.isNaN(d.getTime())) return String(s);
    return d.toLocaleDateString('fr-FR');
}

/** المبلغ بالدينار وثلاث خانات للمليمات — عرف TND. */
function fmtAmount(v: number | string): string {
    return Number(v).toLocaleString('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + ' د.ت';
}

/**
 * «ما تم استخلاصه» — سجل الاستخلاصات التي قام بها المستخدم نفسه. ذاتيّ النطاق خادمياً:
 * يستدعي /payments/my-collections الذي يشتقّ المستخدم من التوكن، فلا يُرسَل أيّ معرّف.
 */
export function MyCollectionsPage() {
    const { user } = useAuth();
    const [result, setResult] = useState<PaginatedResponse<Payment> | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [page, setPage] = useState(1);

    useEffect(() => {
        if (!user) return;
        let active = true;
        setLoading(true);
        setError(null);
        paymentsApi
            .myCollections({
                exclude_cancelled: true,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
                page,
            })
            .then((res) => { if (active) setResult(res); })
            .catch((err: unknown) => {
                if (active) setError(err instanceof Error ? err.message : 'تعذّر تحميل الاستخلاصات');
            })
            .finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [user, dateFrom, dateTo, page]);

    // تغيير أيّ مرشّح يعيد الترقيم إلى الصفحة الأولى.
    const onFrom = (v: string) => { setDateFrom(v); setPage(1); };
    const onTo = (v: string) => { setDateTo(v); setPage(1); };
    const clearFilters = () => { setDateFrom(''); setDateTo(''); setPage(1); };

    const hasFilters = Boolean(dateFrom || dateTo);
    const rows = result?.data ?? [];
    const currentPage = result?.current_page ?? page;
    const lastPage = result?.last_page ?? 1;
    const total = result?.total ?? 0;

    const inputClass =
        'w-full px-3 py-2 rounded-xl border text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition';

    return (
        <div className="p-6 md:p-8" dir="rtl">

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
                <div className="flex items-center gap-3">
                    <div className="w-11 h-11 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage, color: C.forest }}>
                        <Receipt size={22} />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold" style={{ color: C.ink }}>ما تم استخلاصه</h1>
                        <p className="mt-1 text-sm" style={{ color: C.muted }}>
                            سجل الاستخلاصات التي قمت بها
                        </p>
                    </div>
                </div>
            </div>

            {/* المرشّحات — مدى تاريخي */}
            <div className="bg-white rounded-[22px] border p-4 md:p-5 mb-6" style={{ borderColor: C.line }}>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-xs font-semibold mb-1.5" style={{ color: C.muted }}>تاريخ من</label>
                        <input type="date" value={dateFrom} onChange={(e) => onFrom(e.target.value)} className={inputClass} style={{ borderColor: C.line, color: C.ink }} dir="ltr" />
                    </div>
                    <div>
                        <label className="block text-xs font-semibold mb-1.5" style={{ color: C.muted }}>تاريخ إلى</label>
                        <input type="date" value={dateTo} onChange={(e) => onTo(e.target.value)} className={inputClass} style={{ borderColor: C.line, color: C.ink }} dir="ltr" />
                    </div>
                </div>
                {hasFilters && (
                    <div className="mt-3 flex justify-end">
                        <button
                            onClick={clearFilters}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-slate-100 transition"
                            style={{ color: C.muted }}
                        >
                            <RotateCcw size={14} />
                            مسح المرشّحات
                        </button>
                    </div>
                )}
            </div>

            {error && (
                <div className="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 flex items-center gap-3">
                    <AlertCircle size={20} />
                    <p>{error}</p>
                </div>
            )}

            <div className="bg-white rounded-[22px] border overflow-hidden" style={{ borderColor: C.line }}>
                <div className="overflow-x-auto">
                    <table className="w-full text-right text-sm">
                        <thead>
                            <tr style={{ backgroundColor: '#F7F9F5' }}>
                                <th className="px-6 py-4 font-semibold whitespace-nowrap" style={{ color: C.muted }}>التاريخ</th>
                                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>التلميذ</th>
                                <th className="px-6 py-4 font-semibold whitespace-nowrap" style={{ color: C.muted }}>المبلغ</th>
                                <th className="px-6 py-4 font-semibold whitespace-nowrap" style={{ color: C.muted }}>الطريقة</th>
                                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <TableRowsSkeleton columns={5} />
                            ) : rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center" style={{ color: C.muted }}>
                                        {hasFilters ? 'لا توجد استخلاصات مطابقة للمرشّحات' : 'لا توجد استخلاصات بعد'}
                                    </td>
                                </tr>
                            ) : (
                                rows.map((p) => (
                                    <tr key={p.id} className="border-t hover:bg-[#FAFBF8] transition align-top" style={{ borderColor: C.line }}>
                                        <td className="px-6 py-4 whitespace-nowrap" dir="ltr" style={{ color: C.ink }}>
                                            {fmtDay(p.payment_date)}
                                        </td>
                                        <td className="px-6 py-4" style={{ color: C.ink }}>
                                            {p.student ? `${p.student.first_name} ${p.student.last_name}` : '—'}
                                            {p.student?.student_code && (
                                                <span className="block text-xs" style={{ color: C.muted }} dir="ltr">
                                                    {p.student.student_code}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 font-semibold whitespace-nowrap" style={{ color: C.forest }}>
                                            {fmtAmount(p.amount)}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            <span className="inline-flex px-3 py-1 rounded-full text-xs font-medium" style={{ backgroundColor: C.sage, color: C.forest }}>
                                                {METHOD_LABELS[p.method] ?? p.method}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4" style={{ color: C.deep }}>
                                            {p.notes || p.reference || '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* الترقيم */}
                {!loading && rows.length > 0 && (
                    <div className="flex items-center justify-between gap-4 px-6 py-4 border-t" style={{ borderColor: C.line }}>
                        <p className="text-xs" style={{ color: C.muted }}>
                            الصفحة {currentPage} من {lastPage} — {total} استخلاص
                        </p>
                        <div className="flex items-center gap-2">
                            <button
                                onClick={() => setPage((prev) => Math.max(1, prev - 1))}
                                disabled={currentPage <= 1}
                                className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border transition disabled:opacity-40 disabled:cursor-not-allowed hover:bg-slate-50"
                                style={{ borderColor: C.line, color: C.forest }}
                            >
                                <ChevronRight size={16} />
                                السابق
                            </button>
                            <button
                                onClick={() => setPage((prev) => (lastPage ? Math.min(lastPage, prev + 1) : prev + 1))}
                                disabled={currentPage >= lastPage}
                                className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border transition disabled:opacity-40 disabled:cursor-not-allowed hover:bg-slate-50"
                                style={{ borderColor: C.line, color: C.forest }}
                            >
                                التالي
                                <ChevronLeft size={16} />
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
