import { useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { AlertCircle, ArrowRight, Layers } from 'lucide-react';
import { fetchClassroomDetail, type ClassroomDetail } from '../../api/reportDetails';
import { errorMessage, money } from '../../lib/format';

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
 * صفحة قسم واحد.
 *
 * فلتر التاريخ يُقرأ من الرابط لا من حالة داخلية منفصلة، حتى يبقى مجموع
 * هذه الصفحة مطابقاً تماماً للسطر الذي نُقر عليه في الجدول المجمّع، ولو
 * نُسخ الرابط وأُرسل لغيرك.
 */
export function ClassroomDetailPage() {
  const { sectionId } = useParams<{ sectionId: string }>();
  const [searchParams] = useSearchParams();
  const from = searchParams.get('from') ?? '';
  const to = searchParams.get('to') ?? '';

  const [data, setData] = useState<ClassroomDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!sectionId) return;

    const controller = new AbortController();
    setLoading(true);
    setError('');

    fetchClassroomDetail(
      sectionId,
      { date_from: from || undefined, date_to: to || undefined },
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

    return () => controller.abort();
  }, [sectionId, from, to]);

  const periodLabel = from || to ? `${from || 'البداية'} ← ${to || 'اليوم'}` : 'كل الفترات';
  const studentQuery = from || to ? `?from=${from}&to=${to}` : '';

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <Link
        to={`/income/by-classroom${studentQuery}`}
        className="inline-flex items-center gap-1 text-sm mb-4"
        style={{ color: C.muted }}
      >
        <ArrowRight size={16} />
        <span>رجوع إلى المداخيل حسب القسم</span>
      </Link>

      <div className="flex items-center gap-3 mb-4">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
          <Layers size={20} style={{ color: C.forest }} />
        </div>
        <div>
          <h2 className="text-lg font-bold" style={{ color: C.ink }}>
            {data ? `${data.section.level ?? 'دون مستوى'} — قسم ${data.section.name ?? ''}` : 'تفصيل القسم'}
          </h2>
          <p className="text-sm" style={{ color: C.muted }}>الفترة: {periodLabel}</p>
        </div>
      </div>

      {error && (
        <div className="rounded-2xl p-4 mb-4 flex items-start gap-2 text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {loading && <p className="text-sm py-6 text-center" style={{ color: C.muted }}>جارٍ التحميل…</p>}

      {!loading && data && (
        <>
          <div className="grid gap-3 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(11rem, 1fr))' }}>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>مجموع المداخيل</p>
              <p className="text-xl font-bold" style={{ color: C.forest }}>{money(data.summary.total)}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>المسجّلون</p>
              <p className="text-xl font-bold" style={{ color: C.ink }}>{data.summary.enrolled_count}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>من دفع</p>
              <p className="text-xl font-bold" style={{ color: C.ink }}>{data.summary.payers_count}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>لم يدفع بعد</p>
              <p className="text-xl font-bold" style={{ color: data.summary.unpaid_count > 0 ? C.error : C.ink }}>
                {data.summary.unpaid_count}
              </p>
            </div>
          </div>

          <div className="bg-white rounded-2xl overflow-hidden mb-4" style={{ border: `1px solid ${C.line}` }}>
            <div className="px-4 py-3" style={{ backgroundColor: C.sage }}>
              <p className="text-sm font-semibold" style={{ color: C.ink }}>تفصيل المداخيل حسب البند</p>
            </div>
            <table className="w-full text-sm">
              <tbody>
                {data.by_category.map((line) => (
                  <tr key={line.category} style={{ borderTop: `1px solid ${C.line}` }}>
                    <td className="px-4 py-2" style={{ color: C.ink }}>{line.label}</td>
                    <td className="px-4 py-2 text-left font-semibold" style={{ color: line.total > 0 ? C.forest : C.muted }}>
                      {money(line.total)}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr style={{ backgroundColor: '#F7F9F4', borderTop: `1px solid ${C.line}` }}>
                  <td className="px-4 py-3 font-bold" style={{ color: C.ink }}>المجموع</td>
                  <td className="px-4 py-3 text-left font-bold" style={{ color: C.forest }}>{money(data.summary.total)}</td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div className="bg-white rounded-2xl overflow-hidden" style={{ border: `1px solid ${C.line}` }}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{ backgroundColor: C.sage }}>
                  <tr>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>رقم التلميذ</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>الاسم</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>عدد الوصولات</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>المجموع</th>
                  </tr>
                </thead>
                <tbody>
                  {data.students.length === 0 && (
                    <tr>
                      <td colSpan={4} className="px-4 py-8 text-center" style={{ color: C.muted }}>
                        لا توجد مداخيل لهذا القسم في هذه الفترة.
                      </td>
                    </tr>
                  )}
                  {data.students.map((row) => (
                    <tr key={row.student_id} style={{ borderTop: `1px solid ${C.line}` }} className="hover:bg-[#F7F9F4]">
                      <td className="px-4 py-3 whitespace-nowrap" style={{ color: C.muted }}>{row.student_code ?? '—'}</td>
                      <td className="px-4 py-3">
                        <Link
                          to={`/income/revenue/${row.student_id}${studentQuery}`}
                          className="font-semibold hover:underline"
                          style={{ color: C.forest }}
                        >
                          {row.name}
                        </Link>
                      </td>
                      <td className="px-4 py-3" style={{ color: C.muted }}>{row.payments_count}</td>
                      <td className="px-4 py-3 font-semibold" style={{ color: C.forest }}>{money(row.total)}</td>
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
