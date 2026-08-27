import { useEffect, useState } from 'react';
import { FileText } from 'lucide-react';
import { PageShell } from '../../components/PageShell';
import { fetchYears, type AcademicYear } from '../../api/roster';
import { DEBT_STATUS_LABELS, DEBT_TYPE_LABELS, fetchManualDebtsReport, type ManualDebtsReport } from '../../api/manualDebts';
import { errorMessage, money, personName } from '../../lib/format';
import { ListSkeleton } from '../../components/DataSkeleton';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  error: '#A03434',
  errorBg: '#FDECEC',
};

export function OldDebtReportPage() {
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [yearId, setYearId] = useState<number | ''>('');
  const [status, setStatus] = useState('');
  const [data, setData] = useState<ManualDebtsReport | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    (async () => {
      try {
        const yrs = await fetchYears();
        setYears(yrs);
        const active = yrs.find((y) => y.is_active) ?? yrs[0];
        if (active) setYearId(active.id);
      } catch (err) {
        setError(errorMessage(err));
      }
    })();
  }, []);

  useEffect(() => {
    (async () => {
      setLoading(true);
      setError('');
      try {
        const report = await fetchManualDebtsReport({
          academic_year_id: yearId === '' ? null : yearId,
          status: status || null,
        });
        setData(report);
      } catch (err) {
        setError(errorMessage(err));
      } finally {
        setLoading(false);
      }
    })();
  }, [yearId, status]);

  const fieldStyle = { border: '1px solid ' + C.line, backgroundColor: '#fff', color: C.ink };

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <style>{`
        @media print {
          @page { size: A4 landscape; margin: 10mm 8mm; }
          body { background: white !important; font-size: 10pt !important; color: black !important; }
          .no-print { display: none !important; }
          .print-only { display: block !important; }
          table { width: 100% !important; border-collapse: collapse !important; margin-top: 15px !important; direction: rtl !important; }
          thead { display: table-header-group !important; }
          tr, th, td { break-inside: avoid !important; page-break-inside: avoid !important; }
          th, td { border: 1px solid #000000 !important; padding: 5px 6px !important; font-size: 9.5pt !important; text-align: right !important; color: black !important; }
          th { background-color: #F2F2F2 !important; font-weight: bold !important; }
        }
        .print-only { display: none; }
      `}</style>

      <div className="print-only mb-4 p-4 border border-slate-900 rounded-xl bg-white text-slate-900">
        <h1 className="text-xl font-bold">مدرسة العناية — كشف الديون القديمة المدخلة يدوياً</h1>
        <p className="text-xs text-slate-600 mt-1">تاريخ التقرير: {new Date().toLocaleDateString('ar-TN')}</p>
      </div>

      <PageShell
        title="كشف الديون القديمة"
        subtitle="الديون المدخلة يدوياً من سنوات سابقة — المبلغ الأصلي والمحصّل والمتبقّي لكل دَين"
        icon={FileText}
      >
        <div>
          {error ? (
            <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>{error}</div>
          ) : null}

          <div className="no-print flex flex-wrap gap-3 mb-6">
            <select value={yearId} onChange={(e) => setYearId(e.target.value ? Number(e.target.value) : '')} className="rounded-xl px-3 py-2 text-sm" style={fieldStyle}>
              <option value="">كل السنوات</option>
              {years.map((y) => <option key={y.id} value={y.id}>{y.name}{y.is_active ? ' — حالية' : ''}</option>)}
            </select>
            <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-xl px-3 py-2 text-sm" style={fieldStyle}>
              <option value="">كل الحالات</option>
              {Object.entries(DEBT_STATUS_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </div>

          {data ? (
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
              {[
                { label: 'عدد الديون', value: String(data.totals.count), color: C.deep },
                { label: 'المبلغ الأصلي', value: money(data.totals.original_amount), color: C.ink },
                { label: 'المحصّل', value: money(data.totals.collected_amount), color: C.muted },
                { label: 'المتبقّي', value: money(data.totals.outstanding_amount), color: C.forest },
              ].map((card) => (
                <div key={card.label} className="bg-white rounded-2xl p-4" style={{ border: '1px solid ' + C.line }}>
                  <p className="text-xs mb-1" style={{ color: C.muted }}>{card.label}</p>
                  <p className="text-lg font-bold" style={{ color: card.color, direction: 'ltr', textAlign: 'right' }}>{card.value}</p>
                </div>
              ))}
            </div>
          ) : null}

          <div className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
            {loading ? (
              <ListSkeleton rows={6} />
            ) : !data || data.items.length === 0 ? (
              <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>لا ديون في هذه الفلاتر.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr style={{ borderBottom: '1px solid ' + C.line, color: C.muted }}>
                      <th className="text-right px-3 py-3 font-medium">التلميذ</th>
                      <th className="text-right px-3 py-3 font-medium">السنة</th>
                      <th className="text-right px-3 py-3 font-medium">النوع</th>
                      <th className="text-right px-3 py-3 font-medium">الوصف</th>
                      <th className="text-right px-3 py-3 font-medium">السنة الأصلية</th>
                      <th className="text-right px-3 py-3 font-medium">الأصلي</th>
                      <th className="text-right px-3 py-3 font-medium">المحصّل</th>
                      <th className="text-right px-3 py-3 font-medium">المتبقّي</th>
                      <th className="text-right px-3 py-3 font-medium">الحالة</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.items.map((item) => {
                      const cancelled = Boolean(item.cancelled_at);
                      return (
                        <tr key={item.id} style={{ borderBottom: '1px solid ' + C.line, opacity: cancelled ? 0.55 : 1 }}>
                          <td className="px-3 py-2.5 font-medium" style={{ color: C.ink }}>{personName(item.student)}</td>
                          <td className="px-3 py-2.5" style={{ color: C.muted }}>{item.academic_year?.name ?? '—'}</td>
                          <td className="px-3 py-2.5" style={{ color: C.muted }}>{DEBT_TYPE_LABELS[item.debt_type] ?? item.debt_type}</td>
                          <td className="px-3 py-2.5" style={{ color: C.ink }}>
                            {item.description}
                            {cancelled ? <span className="block text-xs" style={{ color: C.error }}>ملغى</span> : null}
                          </td>
                          <td className="px-3 py-2.5 text-xs" style={{ color: C.muted }}>{item.original_year_label}</td>
                          <td className="px-3 py-2.5" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{money(item.original_amount)}</td>
                          <td className="px-3 py-2.5" style={{ color: C.muted, direction: 'ltr', textAlign: 'right' }}>{money(item.collected_amount)}</td>
                          <td className="px-3 py-2.5 font-medium" style={{ color: C.forest, direction: 'ltr', textAlign: 'right' }}>{money(item.outstanding_amount)}</td>
                          <td className="px-3 py-2.5" style={{ color: C.muted }}>{DEBT_STATUS_LABELS[item.status] ?? item.status}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      </PageShell>
    </div>
  );
}

export default OldDebtReportPage;