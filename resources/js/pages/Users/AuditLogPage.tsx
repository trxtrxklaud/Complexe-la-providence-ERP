import { useEffect, useState } from 'react';
import { fetchAuditLogs, type AuditLog } from '../../api/auditLogs';
import type { Paginated } from '../../api/expenses';
import { fetchUsers, type User } from '../../api/users';
import { TableRowsSkeleton } from '../../components/DataSkeleton';
import { AlertCircle, ClipboardList, ChevronRight, ChevronLeft, RotateCcw } from 'lucide-react';

const C = {
    forest: '#3B4A36',
    deep:   '#2E3B2A',
    sage:   '#E3EBDB',
    ink:    '#1F261C',
    muted:  '#7C8677',
    line:   '#EDF1E8',
};

type BadgeTone = 'blue' | 'green' | 'red' | 'orange' | 'slate';

/** ألوان الشارات حسب طبيعة العملية — دخول/خروج أزرق، تسجيل أخضر، إلغاء/حذف أحمر، تعديل برتقالي. */
const TONE_CLASSES: Record<BadgeTone, string> = {
    blue:   'bg-blue-50 text-blue-700 ring-1 ring-blue-100',
    green:  'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100',
    red:    'bg-red-50 text-red-700 ring-1 ring-red-100',
    orange: 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
    slate:  'bg-slate-100 text-slate-600 ring-1 ring-slate-200',
};

/** أسماء عربية للعمليات المعروفة؛ ما لا يُعرف يُعرض كما هو. */
const ACTION_LABELS: Record<string, string> = {
    'login':                 'تسجيل الدخول',
    'logout':                'تسجيل الخروج',
    'payment.create':        'تسجيل دفعة',
    'payment.cancel':        'إلغاء دفعة',
    'student.create':        'إضافة تلميذ',
    'student.update':        'تعديل تلميذ',
    'student.delete':        'حذف تلميذ',
    'user.create':           'إضافة مستخدم',
    'user.update':           'تعديل مستخدم',
    'user.password_changed': 'تغيير كلمة المرور',
    'expense.create':        'تسجيل مصروف',
    'withdrawal.create':     'سحب من الخزينة',
};

function actionLabel(action: string): string {
    return ACTION_LABELS[action] ?? action;
}

/** لون الشارة يتبع قواعد ثابتة كي يشمل أي عملية جديدة لا تظهر في القائمة أعلاه. */
function toneForAction(action: string): BadgeTone {
    if (action === 'login' || action === 'logout') return 'blue';
    if (action.includes('cancel')) return 'red';
    if (action.includes('delete')) return 'red';
    if (action.includes('create')) return 'green';
    if (action.includes('payment')) return 'green';
    if (action.includes('password')) return 'orange';
    if (action.includes('update')) return 'orange';
    return 'slate';
}

function formatDateTime(iso: string | null): { date: string; time: string } {
    if (!iso) return { date: '—', time: '' };
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return { date: iso, time: '' };
    const pad = (n: number) => String(n).padStart(2, '0');
    return {
        date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`,
        time: `${pad(d.getHours())}:${pad(d.getMinutes())}`,
    };
}

function userLabel(log: AuditLog): string {
    if (log.user_name) return log.user_name;
    if (log.user) return `${log.user.first_name} ${log.user.last_name}`.trim();
    return '—';
}

export function AuditLogPage() {
    const [result, setResult] = useState<Paginated<AuditLog> | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [users, setUsers] = useState<User[]>([]);

    // مرشّحات — كلّها تطابق مرشّحات الخادم (تطابق تامّ للعملية والمستخدم، ومدى تاريخي).
    const [action, setAction] = useState('');
    const [userId, setUserId] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [page, setPage] = useState(1);

    // قائمة المستخدمين لمرشّح «المستخدم» — الصفحة محروسة بـ manage_users مثل مسار /users.
    useEffect(() => {
        let active = true;
        fetchUsers()
            .then((data) => { if (active) setUsers(data); })
            .catch(() => { /* غياب القائمة لا يعطّل السجل نفسه */ });
        return () => { active = false; };
    }, []);

    useEffect(() => {
        let active = true;
        setLoading(true);
        setError(null);
        fetchAuditLogs({
            action: action || undefined,
            user_id: userId ? Number(userId) : undefined,
            date_from: dateFrom || undefined,
            date_to: dateTo || undefined,
            page,
        })
            .then((res) => { if (active) setResult(res); })
            .catch((err: unknown) => {
                if (active) setError(err instanceof Error ? err.message : 'تعذّر تحميل سجل العمليات');
            })
            .finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [action, userId, dateFrom, dateTo, page]);

    // تغيير أيّ مرشّح يعيد الترقيم إلى الصفحة الأولى.
    const onAction = (v: string) => { setAction(v); setPage(1); };
    const onUser = (v: string) => { setUserId(v); setPage(1); };
    const onFrom = (v: string) => { setDateFrom(v); setPage(1); };
    const onTo = (v: string) => { setDateTo(v); setPage(1); };
    const clearFilters = () => { setAction(''); setUserId(''); setDateFrom(''); setDateTo(''); setPage(1); };

    const hasFilters = Boolean(action || userId || dateFrom || dateTo);
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
                        <ClipboardList size={22} />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold" style={{ color: C.ink }}>سجل العمليات</h1>
                        <p className="mt-1 text-sm" style={{ color: C.muted }}>
                            أثر كامل لكل عملية مهمّة على المنصّة — للمراجعة والمساءلة
                        </p>
                    </div>
                </div>
            </div>

            {/* المرشّحات */}
            <div className="bg-white rounded-[22px] border p-4 md:p-5 mb-6" style={{ borderColor: C.line }}>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label className="block text-xs font-semibold mb-1.5" style={{ color: C.muted }}>بحث بالعملية</label>
                        <select value={action} onChange={(e) => onAction(e.target.value)} className={inputClass} style={{ borderColor: C.line, color: C.ink }}>
                            <option value="">كل العمليات</option>
                            {Object.entries(ACTION_LABELS).map(([key, label]) => (
                                <option key={key} value={key}>{label}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-semibold mb-1.5" style={{ color: C.muted }}>المستخدم</label>
                        <select value={userId} onChange={(e) => onUser(e.target.value)} className={inputClass} style={{ borderColor: C.line, color: C.ink }}>
                            <option value="">كل المستخدمين</option>
                            {users.map((u) => (
                                <option key={u.id} value={u.id}>{u.first_name} {u.last_name}</option>
                            ))}
                        </select>
                    </div>
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
                                <th className="px-6 py-4 font-semibold whitespace-nowrap" style={{ color: C.muted }}>التاريخ والوقت</th>
                                <th className="px-6 py-4 font-semibold whitespace-nowrap" style={{ color: C.muted }}>المستخدم</th>
                                <th className="px-6 py-4 font-semibold whitespace-nowrap" style={{ color: C.muted }}>العملية</th>
                                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>التفاصيل</th>
                                <th className="px-6 py-4 font-semibold whitespace-nowrap" style={{ color: C.muted }}>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <TableRowsSkeleton columns={5} />
                            ) : rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center" style={{ color: C.muted }}>
                                        {hasFilters ? 'لا توجد عمليات مطابقة للمرشّحات' : 'لا توجد عمليات مسجّلة بعد'}
                                    </td>
                                </tr>
                            ) : (
                                rows.map((log) => {
                                    const { date, time } = formatDateTime(log.created_at);
                                    const tone = toneForAction(log.action);
                                    return (
                                        <tr key={log.id} className="border-t hover:bg-[#FAFBF8] transition align-top" style={{ borderColor: C.line }}>
                                            <td className="px-6 py-4 whitespace-nowrap" dir="ltr">
                                                <span className="font-medium" style={{ color: C.ink }}>{date}</span>
                                                {time && <span className="text-xs mr-2" style={{ color: C.muted }}>{time}</span>}
                                            </td>
                                            <td className="px-6 py-4 font-medium whitespace-nowrap" style={{ color: C.ink }}>
                                                {userLabel(log)}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <span className={`inline-flex px-3 py-1 rounded-full text-xs font-semibold ${TONE_CLASSES[tone]}`}>
                                                    {actionLabel(log.action)}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4" style={{ color: C.deep }}>
                                                {log.description || '—'}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-xs" style={{ color: C.muted }} dir="ltr">
                                                {log.ip_address || '—'}
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                {/* الترقيم */}
                {!loading && rows.length > 0 && (
                    <div className="flex items-center justify-between gap-4 px-6 py-4 border-t" style={{ borderColor: C.line }}>
                        <p className="text-xs" style={{ color: C.muted }}>
                            الصفحة {currentPage} من {lastPage} — {total} عملية
                        </p>
                        <div className="flex items-center gap-2">
                            <button
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                disabled={currentPage <= 1}
                                className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border transition disabled:opacity-40 disabled:cursor-not-allowed hover:bg-slate-50"
                                style={{ borderColor: C.line, color: C.forest }}
                            >
                                <ChevronRight size={16} />
                                السابق
                            </button>
                            <button
                                onClick={() => setPage((p) => (lastPage ? Math.min(lastPage, p + 1) : p + 1))}
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
