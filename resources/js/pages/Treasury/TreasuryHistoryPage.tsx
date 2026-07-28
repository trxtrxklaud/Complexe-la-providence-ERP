import { useEffect, useState } from 'react';
import { History, Filter } from 'lucide-react';
import { PageShell } from '../../components/PageShell';
import {
  fetchTreasuryHistory,
  CASH_CATEGORY_LABELS,
  type CashTransaction,
  type TreasurySummary,
} from '../../api/treasury';
import { errorMessage, money, monthStart, personName, today } from '../../lib/format';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  error: '#A03434',
  errorBg: '#FDECEC',
  inBg: '#EDF3E7',
};

type Direction = '' | 'in' | 'out';

export function TreasuryHistoryPage() {
  const [dateFrom, setDateFrom] = useState(monthStart());
  const [dateTo, setDateTo] = useState(today());
  const [direction, setDirection] = useState<Direction>('');
  const [category, setCategory] = useState('');
  const [includeCancelled, setIncludeCancelled] = useState(false);
  const [page, setPage] = useState(1);

  const [rows, setRows] = useState<CashTransaction[]>([]);
  const [summary, setSummary] = useState<TreasurySummary | null>(null);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const result = await fetchTreasuryHistory({
        date_from: dateFrom || null,
        date_to: dateTo || null,
        direction: direction === '' ? null : direction,
        category: category || null,
        include_cancelled: includeCancelled,
        per_page: 30,
        page,
      });
      setRows(result.transactions.data);
      setLastPage(result.transactions.last_page);
      setTotal(result.transactions.total);
      setSummary(result.summary);
    } catch (err) {
      setError(errorMessage(err));
      setRows([]);
      setSummary(null);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
  }, [page]);

  const applyFilters = () => {
    // إعادة الصفحة إلى 1 تُشغّل التحميل عبر useEffect، وإن كانت 1 أصلاً نحمّل يدوياً.
    if (page === 1) void load();
    else setPage(1);
  };

  const fieldStyle = { border: '1px solid ' + C.line, backgroundColor: '#fff', color: C.ink };
  const fieldCls = 'w-full px-3 py-2.5 rounded-xl text-sm';

  const income = summary?.by_category.filter((row) => row.direction === 'in') ?? [];
  const outgoing = summary?.by_category.filter((row) => row.direction === 'out') ?? [];

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <PageShell
        title="سجل حركات الخزينة"
        subtitle="كل الحركات الداخلة والخارجة (مداخيل / مصاريف / سحوبات) من الدفتر المركزي"
        icon={History}
      >
        <div>
          {error ? (
            <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>{error}</div>
          ) : null}

          {/* المرشّحات */}
          <div className="bg-white rounded-2xl p-5 mb-6" style={{ border: '1px solid ' + C.line }}>
            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>من تاريخ</label>
                <input value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} type="date" className={fieldCls} style={{ ...fieldStyle, direction: 'ltr' }} />
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>إلى تاريخ</label>
                <input value={dateTo} onChange={(e) => setDateTo(e.target.value)} type="date" className={fieldCls} style={{ ...fieldStyle, direction: 'ltr' }} />
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>الاتجاه</label>
                <select value={direction} onChange={(e) => setDirection(e.target.value as Direction)} className={fieldCls} style={fieldStyle}>
                  <option value="">الكل</option>
                  <option value="in">داخل</option>
                  <option value="out">خارج</option>
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>البند</label>
                <select value={category} onChange={(e) => setCategory(e.target.value)} className={fieldCls} style={fieldStyle}>
                  <option value="">كل البنود</option>
                  {Object.entries(CASH_CATEGORY_LABELS).map(([value, label]) => (
                    <option key={value} value={value}>{label}</option>
                  ))}
                </select>
              </div>
            </div>

            <div className="flex items-center gap-4 mt-4 flex-wrap">
              <button
                type="button"
                onClick={applyFilters}
                disabled={loading}
                className="flex items-center gap-2 px-5 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: C.forest }}
              >
                <Filter size={16} />
                <span>{loading ? 'جارٍ التحميل…' : 'تطبيق'}</span>
              </button>
              <label className="flex items-center gap-1.5 text-sm" style={{ color: C.ink }}>
                <input type="checkbox" checked={includeCancelled} onChange={(e) => setIncludeCancelled(e.target.checked)} />
                <span>إظهار الحركات الملغاة</span>
              </label>
            </div>
          </div>

          {/* الملخّص */}
          {summary ? (
            <>
              <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-4">
                {[
                  { label: 'مجموع المداخيل', value: summary.income, color: C.deep },
                  { label: 'مجموع المصاريف', value: summary.expenses, color: C.error },
                  { label: 'الدخل الصافي', value: summary.net_income, color: C.forest },
                  { label: 'السحوبات', value: summary.withdrawals, color: C.error },
                  { label: 'الرصيد النهائي', value: summary.balance, color: C.forest },
                ].map((card) => (
                  <div key={card.label} className="bg-white rounded-2xl p-4" style={{ border: '1px solid ' + C.line }}>
                    <p className="text-xs mb-1" style={{ color: C.muted }}>{card.label}</p>
                    <p className="text-base font-bold" style={{ color: card.color, direction: 'ltr', textAlign: 'right' }}>{money(card.value)}</p>
                  </div>
                ))}
              </div>

              {summary.by_category.length > 0 ? (
                <div className="grid sm:grid-cols-2 gap-3 mb-6">
                  <div className="bg-white rounded-2xl p-4" style={{ border: '1px solid ' + C.line }}>
                    <p className="text-sm font-bold mb-2" style={{ color: C.deep }}>تفصيل المداخيل</p>
                    {income.length === 0 ? (
                      <p className="text-xs" style={{ color: C.muted }}>لا مداخيل في هذه المدّة.</p>
                    ) : income.map((row) => (
                      <div key={row.category} className="flex items-center justify-between py-1 text-sm">
                        <span style={{ color: C.ink }}>{row.label}</span>
                        <span style={{ color: C.ink, direction: 'ltr' }}>{money(row.total)}</span>
                      </div>
                    ))}
                  </div>
                  <div className="bg-white rounded-2xl p-4" style={{ border: '1px solid ' + C.line }}>
                    <p className="text-sm font-bold mb-2" style={{ color: C.deep }}>تفصيل الحركات الخارجة</p>
                    {outgoing.length === 0 ? (
                      <p className="text-xs" style={{ color: C.muted }}>لا حركات خارجة في هذه المدّة.</p>
                    ) : outgoing.map((row) => (
                      <div key={row.category} className="flex items-center justify-between py-1 text-sm">
                        <span style={{ color: C.ink }}>{row.label}</span>
                        <span style={{ color: C.error, direction: 'ltr' }}>{money(row.total)}</span>
                      </div>
                    ))}
                  </div>
                </div>
              ) : null}
            </>
          ) : null}

          {/* الحركات */}
          <div className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
            <div className="px-5 py-4 flex items-center justify-between flex-wrap gap-2" style={{ backgroundColor: C.sage }}>
              <h3 className="font-bold" style={{ color: C.deep }}>الحركات</h3>
              <p className="text-xs" style={{ color: C.muted }}>{total} حركة</p>
            </div>

            {loading ? (
              <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>جارٍ التحميل…</p>
            ) : rows.length === 0 ? (
              <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>لا حركات في هذه المدّة.</p>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr style={{ borderBottom: '1px solid ' + C.line, color: C.muted }}>
                        <th className="text-right px-3 py-3 font-medium">التاريخ</th>
                        <th className="text-right px-3 py-3 font-medium">البند</th>
                        <th className="text-right px-3 py-3 font-medium">البيان</th>
                        <th className="text-right px-3 py-3 font-medium">داخل</th>
                        <th className="text-right px-3 py-3 font-medium">خارج</th>
                        <th className="text-right px-3 py-3 font-medium">المستخدم</th>
                      </tr>
                    </thead>
                    <tbody>
                      {rows.map((row) => {
                        const cancelled = Boolean(row.cancelled_at);
                        const label = row.label ?? CASH_CATEGORY_LABELS[row.category] ?? row.category;

                        return (
                          <tr key={row.id} style={{ borderBottom: '1px solid ' + C.line, opacity: cancelled ? 0.5 : 1 }}>
                            <td className="px-3 py-2.5 text-xs" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{String(row.transaction_date).slice(0, 10)}</td>
                            <td className="px-3 py-2.5" style={{ color: C.ink }}>{label}</td>
                            <td className="px-3 py-2.5" style={{ color: C.muted, textDecoration: cancelled ? 'line-through' : 'none' }}>
                              {row.description || '—'}
                              {cancelled && row.cancellation_reason ? (
                                <span className="block text-xs mt-0.5" style={{ color: C.error, textDecoration: 'none' }}>ملغاة: {row.cancellation_reason}</span>
                              ) : null}
                            </td>
                            <td className="px-3 py-2.5 font-medium" style={{ color: C.deep, direction: 'ltr', textAlign: 'right' }}>{row.direction === 'in' ? money(row.amount) : '—'}</td>
                            <td className="px-3 py-2.5 font-medium" style={{ color: C.error, direction: 'ltr', textAlign: 'right' }}>{row.direction === 'out' ? money(row.amount) : '—'}</td>
                            <td className="px-3 py-2.5 text-xs" style={{ color: C.muted }}>{personName(row.created_by)}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>

                {lastPage > 1 ? (
                  <div className="flex items-center justify-between px-5 py-3" style={{ borderTop: '1px solid ' + C.line }}>
                    <button
                      type="button"
                      onClick={() => setPage((p) => Math.max(1, p - 1))}
                      disabled={page <= 1 || loading}
                      className="px-4 py-2 rounded-xl text-sm disabled:opacity-40"
                      style={{ border: '1px solid ' + C.line, color: C.forest }}
                    >
                      السابق
                    </button>
                    <span className="text-xs" style={{ color: C.muted }}>صفحة {page} من {lastPage}</span>
                    <button
                      type="button"
                      onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                      disabled={page >= lastPage || loading}
                      className="px-4 py-2 rounded-xl text-sm disabled:opacity-40"
                      style={{ border: '1px solid ' + C.line, color: C.forest }}
                    >
                      التالي
                    </button>
                  </div>
                ) : null}
              </>
            )}
          </div>
        </div>
      </PageShell>
    </div>
  );
}

export default TreasuryHistoryPage;
