import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, ChevronLeft, TrendingUp } from 'lucide-react';
import { fetchStudentRevenue, type StudentRevenueReport } from '../../api/reports';
import { errorMessage, money } from '../../lib/format';
import { PageDataSkeleton } from '../../components/DataSkeleton';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  error: '#A03434',
  errorBg: '#FDECEC',
};

/**
 * مداخيل التلاميذ — المبالغ من دفتر الخزينة، والهوية من الدفعة والتسجيل،
 * فيبقى مجموع هذه الصفحة مطابقاً لمجموع صفحة الأقسام لنفس الفترة.
 *
 * اسم التلميذ مدخل إلى صفحته التي تعرض وصولاته واحداً واحداً.
 */
export function StudentRevenuePage() {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [search, setSearch] = useState('');
  const [data, setData] = useState<StudentRevenueReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      setLoading(true);
      setError('');
      fetchStudentRevenue(
        {
          date_from: from || undefined,
          date_to: to || undefined,
          search: search.trim() || undefined,
        },
        controller.signal,
      )
        .then((result) => {
          if (!controller.signal.aborted) setData(result);
        })
        .catch((e) => {
          if (!controller.signal.aborted) {
            setData(null);
            setError(errorMessage(e));
          }
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false);
        });
    }, 300);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [from, to, search]);

  const detailQuery = from || to ? `?from=${from}&to=${to}` : '';

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <div className="flex items-center gap-3 mb-4">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
          <TrendingUp size={20} style={{ color: C.forest }} />
        </div>
        <div>
          <h2 className="text-lg font-bold" style={{ color: C.ink }}>مداخيل التلاميذ</h2>
          <p className="text-sm" style={{ color: C.muted }}>انقر على اسم تلميذ لفتح صفحته ووصولاته</p>
        </div>
      </div>

      <div className="bg-white rounded-2xl p-4 mb-4 flex flex-wrap items-end gap-4" style={{ border: `1px solid ${C.line}` }}>
        <div>
          <label className="block text-sm mb-1" style={{ color: C.muted }}>من تاريخ</label>
          <input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={{ border: `1px solid ${C.line}`, color: C.ink }}
          />
        </div>
        <div>
          <label className="block text-sm mb-1" style={{ color: C.muted }}>إلى تاريخ</label>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={{ border: `1px solid ${C.line}`, color: C.ink }}
          />
        </div>
        <div className="flex-1 min-w-[12rem]">
          <label className="block text-sm mb-1" style={{ color: C.muted }}>بحث</label>
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="الاسم أو رقم التلميذ"
            className="w-full rounded-xl px-3 py-2 text-sm"
            style={{ border: `1px solid ${C.line}`, color: C.ink }}
          />
        </div>
      </div>

      {error && (
        <div className="rounded-2xl p-4 mb-4 flex items-start gap-2 text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {loading && <PageDataSkeleton />}

      {!loading && data && (
        <>
          <div className="grid gap-3 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))' }}>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>مجموع المداخيل</p>
              <p className="text-xl font-bold" style={{ color: C.forest }}>{money(data.summary.total)}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>عدد التلاميذ الدافعين</p>
              <p className="text-xl font-bold" style={{ color: C.ink }}>{data.summary.students_count}</p>
            </div>
          </div>

          <div className="bg-white rounded-2xl overflow-hidden" style={{ border: `1px solid ${C.line}` }}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{ backgroundColor: C.sage }}>
                  <tr>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>رقم التلميذ</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>الاسم</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>المستوى</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>القسم</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>عدد الدفعات</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>المجموع</th>
                    <th className="px-4 py-3" />
                  </tr>
                </thead>
                <tbody>
                  {data.rows.length === 0 && (
                    <tr>
                      <td colSpan={7} className="px-4 py-8 text-center" style={{ color: C.muted }}>
                        لا توجد مداخيل مطابقة.
                      </td>
                    </tr>
                  )}
                  {data.rows.map((row) => (
                    <tr key={row.student_id} style={{ borderTop: `1px solid ${C.line}` }} className="hover:bg-[#F7F9F4]">
                      <td className="px-4 py-3 whitespace-nowrap" style={{ color: C.muted }}>{row.student_code ?? '—'}</td>
                      <td className="px-4 py-3">
                        <Link
                          to={`/income/revenue/${row.student_id}${detailQuery}`}
                          className="font-semibold hover:underline"
                          style={{ color: C.forest }}
                        >
                          {row.name}
                        </Link>
                      </td>
                      <td className="px-4 py-3" style={{ color: C.muted }}>{row.level ?? '—'}</td>
                      <td className="px-4 py-3" style={{ color: C.muted }}>{row.section ?? '—'}</td>
                      <td className="px-4 py-3" style={{ color: C.muted }}>{row.payments_count}</td>
                      <td className="px-4 py-3 font-semibold" style={{ color: C.forest }}>{money(row.total)}</td>
                      <td className="px-4 py-3">
                        <Link
                          to={`/income/revenue/${row.student_id}${detailQuery}`}
                          className="inline-flex items-center gap-1 text-xs"
                          style={{ color: C.muted }}
                        >
                          <span>التفصيل</span>
                          <ChevronLeft size={14} />
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
