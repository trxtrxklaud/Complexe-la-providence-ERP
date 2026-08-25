import { Printer } from 'lucide-react';
import type { Employee, HoursNoteType } from '../../api/employees';
import { HOURS_NOTE_LABELS } from '../../api/employees';

/** يوم مسجّل من أيام الشهر (من الـ grid). */
export interface PayslipDay {
  date: string;
  note_type: HoursNoteType;
  hours: number | string;
}

interface Props {
  employee: Employee;
  month: string; // YYYY-MM
  summary: {
    total_hours: number;
    total_salary: number;
    hourly_rate: number;
    work_days: number;
    absence_days: number;
    entries: number;
  };
  days: PayslipDay[];
}

const TEAL = '#2a9d8f';
const NAVY = '#1a3a5c';

const DAY_NAMES = ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

function dayName(dateStr: string): string {
  const d = new Date(`${dateStr}T00:00:00`);
  const idx = (d.getDay() + 6) % 7;
  return DAY_NAMES[idx] ?? '';
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function money(value: unknown): string {
  return Number(value ?? 0).toFixed(2);
}

const BADGE: Record<HoursNoteType, { bg: string; fg: string }> = {
  normal: { bg: '#E0F0EE', fg: '#1a6b5f' },
  absence: { bg: '#FDE8E8', fg: '#B91C1C' },
  replacement: { bg: '#E0F0EE', fg: '#1a6b5f' },
  extra: { bg: '#FEF3C7', fg: '#92400E' },
};

const ROW_BG: Record<HoursNoteType, string> = {
  normal: '#ffffff',
  absence: '#fff5f5',
  replacement: '#ffffff',
  extra: '#fffbf0',
};

/**
 * كشف حساب الخلاص للمعلم الساعي — فاتورة بنكية بيضاء/تيل قابلة للطباعة.
 *
 * كشف إعلامي فحسب: يعرض الساعات المقترحة، والخلاص النهائي يدوي عبر
 * تبويب الرواتب. زر الطباعة يختفي عند الطباعة (print:hidden).
 */
export function PayslipReport({ employee, month, summary, days }: Props) {
  const workedHours = days
    .filter((d) => d.note_type !== 'absence')
    .reduce((sum, d) => sum + Number(d.hours || 0), 0);
  const absenceHours = days
    .filter((d) => d.note_type === 'absence')
    .reduce((sum, d) => sum + Number(d.hours || 0), 0);
  const netHours = Math.max(0, workedHours - absenceHours);

  return (
    <div className="payslip-print-root" style={{ fontFamily: "'Cairo', sans-serif", direction: 'rtl', position: 'relative', width: '100%', minHeight: '100%', display: 'flex', flexDirection: 'column', fontSize: 14, fontWeight: 600, boxSizing: 'border-box' }}>
      <style>{`
  @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800&display=swap');
  @media print {
    body * { visibility: hidden !important; }
    .payslip-print-root, .payslip-print-root * { visibility: visible !important; }
    .payslip-print-root {
      position: fixed !important;
      top: 0 !important; left: 0 !important;
      width: 100% !important;
      height: 100% !important;
      margin: 0 !important;
      padding: 8mm !important;
      box-sizing: border-box !important;
      background: #fff !important;
      box-shadow: none !important;
      border: none !important;
      border-radius: 0 !important;
      display: flex !important;
      flex-direction: column !important;
      z-index: 99999 !important;
    }
    .payslip-print-root * { font-weight: 600 !important; }
    @page { size: A4 portrait; margin: 8mm; }
  }
`}</style>

      {/* زخرفة: دائرتان متداخلتان تيل فاتح شفاف — لا تؤثر على المحتوى */}
      <div style={{ position: 'absolute', top: -40, left: -40, width: 180, height: 180, borderRadius: '50%', background: 'rgba(42,157,143,0.10)', pointerEvents: 'none' }} />
      <div style={{ position: 'absolute', top: -10, left: 30, width: 100, height: 100, borderRadius: '50%', background: 'rgba(42,157,143,0.12)', pointerEvents: 'none' }} />

      <div style={{ maxWidth: 720, margin: '0 auto', background: '#ffffff', color: '#1f2937', width: '100%', flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
        {/* الترويسة */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '14px 20px 10px', borderBottom: `2px solid ${TEAL}`, position: 'relative' }}>
          <div style={{ textAlign: 'right' }}>
            <h2 style={{ margin: 0, fontSize: 26, fontWeight: 800, color: '#c8a96e', lineHeight: 1.3, letterSpacing: 1 }}>
              مركب العناية للتعليم الخاص
            </h2>
            <p style={{ margin: '4px 0 0', fontSize: 14, fontWeight: 600, color: '#7d93a8' }}>
              كشف حساب — أجر بالساعة
            </p>
          </div>
          <div style={{ textAlign: 'left' }}>
            <h1 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: TEAL }}>
              كشف الخلاص
            </h1>
            <p style={{ margin: '4px 0 0', fontSize: 14, fontWeight: 600, color: '#7d93a8' }}>
              {month.slice(0, 4)} / {month.slice(5, 7)}
            </p>
          </div>
        </div>

        {/* بيانات الإطار */}
        <div style={{ display: 'flex', justifyContent: 'space-between', padding: '10px 20px', fontSize: 14, fontWeight: 600 }}>
          <div style={{ textAlign: 'right' }}>
            <p style={{ margin: 0, color: '#7d93a8', fontWeight: 700 }}>مدفوع إلى:</p>
            <p style={{ margin: '4px 0 0', fontWeight: 800, color: NAVY }}>
              {employee.first_name} {employee.last_name}
            </p>
            <p style={{ margin: '2px 0 0', color: '#6b7280' }}>
              {employee.job_title || 'معلم'}
            </p>
          </div>
          <div style={{ textAlign: 'left' }}>
            <p style={{ margin: 0, color: '#7d93a8' }}>
              رقم الكشف: <strong style={{ color: NAVY }}>{month.replace('-', '')}</strong>
            </p>
            <p style={{ margin: '4px 0 0', color: '#7d93a8' }}>
              تاريخ الإصدار: <strong style={{ color: NAVY }}>{today()}</strong>
            </p>
          </div>
        </div>

        {/* الجدول */}
        {(() => {
          const half = Math.ceil(days.length / 2);
          const col1 = days.slice(0, half);
          const col2 = days.slice(half);

          const renderTable = (rows: typeof days) => (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead>
                <tr style={{ background: TEAL, color: '#ffffff' }}>
                  <th style={{ padding: '7px 8px', textAlign: 'right', fontWeight: 700 }}>التاريخ</th>
                  <th style={{ padding: '7px 8px', textAlign: 'right', fontWeight: 700 }}>اليوم</th>
                  <th style={{ padding: '7px 8px', textAlign: 'right', fontWeight: 700 }}>النوع</th>
                  <th style={{ padding: '7px 8px', textAlign: 'right', fontWeight: 700 }}>ساعات</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((d) => (
                  <tr key={d.date} style={{ background: ROW_BG[d.note_type], borderBottom: '1px solid #e0f0ee' }}>
                    <td style={{ padding: '5px 8px' }}>{d.date.slice(5)}</td>
                    <td style={{ padding: '5px 8px' }}>{dayName(d.date)}</td>
                    <td style={{ padding: '5px 8px' }}>
                      <span style={{
                        display: 'inline-block', padding: '1px 6px',
                        borderRadius: 999, fontSize: 13, fontWeight: 700,
                        background: BADGE[d.note_type].bg, color: BADGE[d.note_type].fg,
                      }}>
                        {HOURS_NOTE_LABELS[d.note_type]}
                      </span>
                    </td>
                    <td style={{ padding: '5px 8px', fontWeight: 700, color: d.note_type === 'absence' ? '#B91C1C' : '#111827' }}>
                      {d.note_type === 'absence' ? `−${Number(d.hours || 0)}` : Number(d.hours || 0)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          );

          return (
            <div style={{ padding: '0 20px' }}>
              {days.length === 0 ? (
                <p style={{ textAlign: 'center', color: '#9ca3af', fontSize: 14, fontWeight: 600 }}>لا ساعات مسجّلة لهذا الشهر</p>
              ) : days.length <= 10 ? (
                renderTable(days)
              ) : (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                  <div>{renderTable(col1)}</div>
                  <div>{renderTable(col2)}</div>
                </div>
              )}
            </div>
          );
        })()}

        {/* الملخص — أسفل اليمين */}
        <div style={{ display: 'flex', justifyContent: 'flex-end', padding: '12px 20px', marginTop: 'auto' }}>
          <div style={{ width: 320, border: `1px solid ${TEAL}`, borderRadius: 8, overflow: 'hidden', fontSize: 14, fontWeight: 600 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', padding: '5px 10px', borderBottom: '1px solid #e0f0ee' }}>
              <span style={{ color: '#6b7280' }}>إجمالي ساعات العمل</span>
              <strong style={{ color: NAVY }}>{money(workedHours)}</strong>
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', padding: '5px 10px', borderBottom: '1px solid #e0f0ee', color: '#B91C1C' }}>
              <span>ساعات الغياب</span>
              <strong>−{money(absenceHours)}</strong>
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', padding: '5px 10px', borderBottom: '1px solid #e0f0ee' }}>
              <span style={{ color: '#6b7280' }}>صافي الساعات</span>
              <strong style={{ color: NAVY }}>{money(netHours)}</strong>
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', padding: '5px 10px', borderBottom: '1px solid #e0f0ee' }}>
              <span style={{ color: '#6b7280' }}>معلوم الساعة</span>
              <strong style={{ color: NAVY }}>{money(summary.hourly_rate)} د.ت</strong>
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 10px', background: TEAL, color: '#ffffff' }}>
              <span style={{ fontWeight: 700 }}>صافي الراتب المحتسب</span>
              <strong style={{ fontSize: 18, fontWeight: 800 }}>{money(summary.total_salary)} د.ت</strong>
            </div>
          </div>
        </div>

        {/* التذييل */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: NAVY, padding: '10px 20px', marginTop: 8 }}>
          <p style={{ margin: 0, color: '#ffffff', fontSize: 13, fontWeight: 600 }}>
            هذا الكشف للإعلام فقط — يُعتمد بعد التوقيع من الإدارة
          </p>
          <button
            onClick={() => window.print()}
            className="print:hidden"
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 6,
              padding: '8px 16px',
              borderRadius: 8,
              background: 'transparent',
              color: '#ffffff',
              border: `1px solid #ffffff`,
              fontSize: 13,
              fontWeight: 700,
              cursor: 'pointer',
            }}
          >
            <Printer size={14} /> طباعة
          </button>
        </div>
      </div>
    </div>
  );
}