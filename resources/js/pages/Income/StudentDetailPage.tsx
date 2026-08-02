import { useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { AlertCircle, ArrowRight, Ban, User } from 'lucide-react';
import { fetchStudentDetail, type StudentDetail } from '../../api/reportDetails';
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

const METHOD_LABELS: Record<string, string> = {
  cash: 'نقداً',
  bank_transfer: 'تحويل بنكي',
  check: 'شيك',
  card: 'بطاقة',
};

/**
 * صفحة تلميذ واحد: وصل وصل، وكل وصل مفتوح على بنوده.
 *
 * الوصل الملغى يُعرض مشطوباً مع سبب الإلغاء ولا يدخل في المجموع، لأن الولي
 * قد يحمل ورقة ملغاة فيجب أن يجدها موظف القباضة أمامه لا أن ينكرها.
 */
export function StudentDetailPage() {
  const { studentId } = useParams<{ studentId: string }>();
  const [searchParams] = useSearchParams();
  const from = searchParams.get('from') ?? '';
  const to = searchParams.get('to') ?? '';

  const [data, setData] = useState<StudentDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!studentId) return;

    const controller = new AbortController();
    setLoading(true);
    setError('');

    fetchStudentDetail(
      studentId,
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
  }, [studentId, from, to]);

  const periodLabel = from || to ? `${from || 'البداية'} ← ${to || 'اليوم'}` : 'كل الفترات';
  const backQuery = from || to ? `?from=${from}&to=${to}` : '';

  return (
    <div className="px-6 pb-10 max-w-5xl mx-auto" dir="rtl">
      <Link
        to={`/income/revenue${backQuery}`}
        className="inline-flex items-center gap-1 text-sm mb-4"
        style={{ color: C.muted }}
      >
        <ArrowRight size={16} />
        <span>رجوع إلى مداخيل التلاميذ</span>
      </Link>

      <div className="flex items-center gap-3 mb-4">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
          <User size={20} style={{ color: C.forest }} />
        </div>
        <div>
          <h2 className="text-lg font-bold" style={{ color: C.ink }}>{data ? data.student.name : 'تفصيل التلميذ'}</h2>
          <p className="text-sm" style={{ color: C.muted }}>
            {data
              ? [
                  data.student.student_code,
                  data.student.level,
                  data.student.section ? `قسم ${data.student.section}` : null,
                  data.student.academic_year,
                ]
                  .filter(Boolean)
                  .join(' · ')
              : ''}
          </p>
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
          <div className="grid gap-3 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(11rem, 1fr))' }}>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>مجموع ما دفع</p>
              <p className="text-xl font-bold" style={{ color: C.forest }}>{money(data.summary.total)}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>وصولات نشطة</p>
              <p className="text-xl font-bold" style={{ color: C.ink }}>{data.summary.payments_count}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>وصولات ملغاة</p>
              <p className="text-xl font-bold" style={{ color: data.summary.cancelled_count > 0 ? C.error : C.ink }}>
                {data.summary.cancelled_count}
              </p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>الفترة</p>
              <p className="text-sm font-semibold" style={{ color: C.ink }}>{periodLabel}</p>
            </div>
          </div>

          <div className="bg-white rounded-2xl overflow-hidden mb-4" style={{ border: `1px solid ${C.line}` }}>
            <div className="px-4 py-3" style={{ backgroundColor: C.sage }}>
              <p className="text-sm font-semibold" style={{ color: C.ink }}>توزيع مداخيل التلميذ حسب البند</p>
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

          <h3 className="text-sm font-bold mb-2" style={{ color: C.ink }}>الوصولات</h3>

          {data.payments.length === 0 && (
            <div className="bg-white rounded-2xl p-8 text-center text-sm" style={{ border: `1px solid ${C.line}`, color: C.muted }}>
              لا توجد وصولات لهذا التلميذ في هذه الفترة.
            </div>
          )}

          <div className="space-y-3">
            {data.payments.map((payment) => (
              <div
                key={payment.payment_id}
                className="bg-white rounded-2xl overflow-hidden"
                style={{ border: `1px solid ${payment.cancelled ? C.error : C.line}` }}
              >
                <div className="px-4 py-3 flex flex-wrap items-center gap-3" style={{ backgroundColor: payment.cancelled ? C.errorBg : '#F7F9F4' }}>
                  <span className="font-bold" style={{ color: payment.cancelled ? C.error : C.ink }}>
                    وصل رقم {payment.payment_id}
                  </span>
                  <span className="text-sm" style={{ color: C.muted }}>{payment.transaction_date}</span>
                  {payment.method && (
                    <span className="text-sm" style={{ color: C.muted }}>
                      {METHOD_LABELS[payment.method] ?? payment.method}
                    </span>
                  )}
                  {payment.reference && (
                    <span className="text-sm" style={{ color: C.muted }}>مرجع: {payment.reference}</span>
                  )}
                  <span className="flex-1" />
                  <span
                    className="font-bold"
                    style={{
                      color: payment.cancelled ? C.error : C.forest,
                      textDecoration: payment.cancelled ? 'line-through' : 'none',
                    }}
                  >
                    {money(payment.total)}
                  </span>
                </div>

                {payment.cancelled && (
                  <div className="px-4 py-2 flex items-center gap-2 text-sm" style={{ color: C.error }}>
                    <Ban size={16} />
                    <span>ملغى{payment.cancellation_reason ? `: ${payment.cancellation_reason}` : ''} — غير محتسب في المجموع</span>
                  </div>
                )}

                <table className="w-full text-sm">
                  <tbody>
                    {payment.lines.map((line, index) => (
                      <tr key={`${payment.payment_id}-${line.category}-${index}`} style={{ borderTop: `1px solid ${C.line}` }}>
                        <td className="px-4 py-2" style={{ color: C.ink }}>{line.label}</td>
                        <td className="px-4 py-2 text-left" style={{ color: C.muted }}>{money(line.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  );
}
