import { useEffect, useState } from 'react';
import { ChevronRight, ChevronLeft, RefreshCw } from 'lucide-react';
import {
  getEmployeeHours, saveEmployeeHours, updateEmployee,
  type Employee, type HoursGrid, type HoursNoteType,
} from '../../api/employees';
import { HOURS_NOTE_LABELS } from '../../api/employees';
import { C, money, type AcademicYearOption } from './shared';
import { PayslipReport } from './PayslipReport';
import { SalaryFormModal, type SalaryFormValues } from './SalaryFormModal';

interface Props {
  employees: Employee[];
  years: AcademicYearOption[];
  defaultYearId: string;
  onCreateSalary: (values: SalaryFormValues) => Promise<void>;
  salarySaving: boolean;
  onError: (message: string) => void;
}

const DAY_NAMES = ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

const NOTE_COLORS: Record<HoursNoteType, { bg: string; fg: string }> = {
  normal: { bg: '#E3EBDB', fg: '#3B4A36' },
  absence: { bg: '#FEE2E2', fg: '#991B1B' },
  replacement: { bg: '#D1FAE5', fg: '#065F46' },
  extra: { bg: '#FEF3C7', fg: '#92400E' },
};

function fmt(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** الاثنين الذي يسبق أو يوافق التاريخ المعطى. */
function mondayOf(input: string): string {
  const d = new Date(`${input}T00:00:00`);
  const offset = (d.getDay() + 6) % 7;
  d.setDate(d.getDate() - offset);
  return fmt(d);
}

function shiftDays(input: string, days: number): string {
  const d = new Date(`${input}T00:00:00`);
  d.setDate(d.getDate() + days);
  return fmt(d);
}

const FIELD = 'w-full border rounded-xl px-2 py-1 text-sm';

/**
 * جدول حصص المعلم الساعي — يحسب ويقترح فحسب، لا يخلّص.
 *
 * شبكة أسبوعية (الاثنين → السبت) لكل أسبوع من الشهر، التنقّل بين
 * الأسابيع بالأزرار، وملخص الشهر في الأسفل. كل خلية تعدّل ساعات يومها
 * (upsert) فيُستبدل صفّ اليوم ولا يتكرّر.
 */
export function ScheduleTab({ employees, years, defaultYearId, onCreateSalary, salarySaving, onError }: Props) {
  const hourly = employees.filter((e) => e.salary_type === 'hourly');

  const [employeeId, setEmployeeId] = useState<number>(hourly[0]?.id ?? 0);
  const [cursor, setCursor] = useState<string>(mondayOf(new Date().toISOString().slice(0, 10)));
  const [grid, setGrid] = useState<HoursGrid | null>(null);
  const [loading, setLoading] = useState(false);
  const [savingDate, setSavingDate] = useState<string | null>(null);
  const [editingRate, setEditingRate] = useState(false);
  const [rateInput, setRateInput] = useState('');
  const [savingRate, setSavingRate] = useState(false);
  const [showPayslip, setShowPayslip] = useState(false);
  const [showSalForm, setShowSalForm] = useState(false);

  const month = cursor.slice(0, 7);

  useEffect(() => {
    if (hourly.length > 0 && !hourly.some((e) => e.id === employeeId)) {
      setEmployeeId(hourly[0].id);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [employees]);

  useEffect(() => {
    if (!employeeId) {
      setGrid(null);
      return;
    }

    let cancelled = false;
    setLoading(true);

    getEmployeeHours(employeeId, month)
      .then((data) => {
        if (cancelled) return;
        setGrid(data);
        if (!data.weeks.some((w) => w.week_start === cursor)) {
          setCursor(data.weeks[0]?.week_start ?? cursor);
        }
      })
      .catch((err: Error) => {
        if (!cancelled) onError(err.message);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [employeeId, month]);

  const week = grid?.weeks.find((w) => w.week_start === cursor) ?? null;
  const summary = grid?.summary;
  const employee = hourly.find((e) => e.id === employeeId) ?? null;

  function move(days: number) {
    setCursor(mondayOf(shiftDays(cursor, days)));
  }

  async function saveDay(date: string, hours: string, noteType: HoursNoteType) {
    if (!employeeId) return;
    setSavingDate(date);

    try {
      await saveEmployeeHours(employeeId, {
        work_date: date,
        hours: Number(hours || 0),
        note_type: noteType,
      });
      const fresh = await getEmployeeHours(employeeId, month);
      setGrid(fresh);
    } catch (err) {
      onError((err as Error).message);
    } finally {
      setSavingDate(null);
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <select className="border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}
          value={employeeId} onChange={(e) => setEmployeeId(Number(e.target.value))}>
          <option value={0}>اختر المعلم الساعي</option>
          {hourly.map((e) => (
            <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>
          ))}
        </select>

        {employee && (
          <div className="flex items-center gap-2 text-sm">
            {editingRate ? (
              <>
                <input
                  type="number" step="0.001" min="0"
                  className="border rounded-xl px-2 py-1 w-32"
                  style={{ borderColor: C.line }}
                  value={rateInput}
                  onChange={(e) => setRateInput(e.target.value)}
                  autoFocus
                />
                <span style={{ color: C.muted }}>د.ت/ساعة</span>
                <button
                  disabled={savingRate}
                  className="px-3 py-1 rounded-xl text-white text-xs font-bold disabled:opacity-50"
                  style={{ background: C.forest }}
                  onClick={async () => {
                    setSavingRate(true);
                    try {
                      await updateEmployee(employeeId, { hourly_rate: Number(rateInput) });
                      const fresh = await getEmployeeHours(employeeId, month);
                      setGrid(fresh);
                      setEditingRate(false);
                    } catch (err) {
                      onError((err as Error).message);
                    } finally {
                      setSavingRate(false);
                    }
                  }}
                >
                  {savingRate ? 'جارٍ...' : 'حفظ'}
                </button>
                <button
                  className="px-3 py-1 rounded-xl border text-xs"
                  style={{ borderColor: C.line }}
                  onClick={() => setEditingRate(false)}
                >
                  إلغاء
                </button>
              </>
            ) : (
              <>
                <span style={{ color: C.muted }}>
                  معلوم الساعة: <strong style={{ color: C.ink }}>
                    {employee.hourly_rate != null ? `${employee.hourly_rate} د.ت` : '—'}
                  </strong>
                </span>
                <button
                  className="px-3 py-1 rounded-xl border text-xs"
                  style={{ borderColor: C.line, color: C.ink }}
                  onClick={() => {
                    setRateInput(employee.hourly_rate?.toString() ?? '');
                    setEditingRate(true);
                  }}
                >
                  تعديل
                </button>
              </>
            )}
          </div>
        )}

        <div className="flex items-center gap-2">
          <button onClick={() => move(-7)} title="الأسبوع السابق"
            className="p-2 rounded-xl border" style={{ borderColor: C.line }}>
            <ChevronRight size={16} />
          </button>
          <span className="text-sm font-bold px-2" style={{ color: C.ink }}>
            {month} — أسبوع يبدأ {cursor}
          </span>
          <button onClick={() => move(7)} title="الأسبوع التالي"
            className="p-2 rounded-xl border" style={{ borderColor: C.line }}>
            <ChevronLeft size={16} />
          </button>
        </div>
      </div>

      {!employeeId && (
        <div className="rounded-2xl border p-6 text-center text-sm" style={{ borderColor: C.line, color: C.muted }}>
          لا يوجد معلم ساعي بعد — أنشئ إطاراً بـ«بالساعة» من تبويب قائمة الإطارات.
        </div>
      )}

      {employeeId && (
        <div className="bg-white rounded-2xl border overflow-x-auto" style={{ borderColor: C.line }}>
          {loading && !grid ? (
            <div className="p-10 text-center animate-pulse text-sm" style={{ color: C.muted }}>جارٍ تحميل الجدول...</div>
          ) : week ? (
            <table className="w-full text-sm">
              <thead>
                <tr style={{ color: C.muted }}>
                  {DAY_NAMES.map((name, i) => (
                    <th key={name} className="p-3">{name} {week.days[i]?.date.slice(5)}</th>
                  ))}
                  <th className="p-3">إجمالي الأسبوع</th>
                </tr>
              </thead>
              <tbody>
                <tr className="align-top">
                  {week.days.map((day) => (
                    <td key={day.date} className="p-2 border-t" style={{ borderColor: C.line }}>
                      {day.in_month && !(employee?.hire_date && day.date < employee.hire_date) ? (
                        <div className="space-y-1.5">
                          <span className="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold"
                            style={{ background: NOTE_COLORS[day.note_type].bg, color: NOTE_COLORS[day.note_type].fg }}>
                            {HOURS_NOTE_LABELS[day.note_type]}
                          </span>
                          <input
                            type="number" step="0.5" min="0" max="24"
                            className={FIELD} style={{ borderColor: C.line }}
                            defaultValue={Number(day.hours)}
                            key={`${day.date}-${day.hours}-${day.note_type}`}
                            disabled={savingDate === day.date}
                          />
                          <select
                            className={FIELD} style={{ borderColor: C.line }}
                            defaultValue={day.note_type}
                            key={`${day.date}-t-${day.note_type}`}
                            disabled={savingDate === day.date}
                            onChange={(e) => {
                              if (e.target.value === 'absence') {
                                const cell = (e.currentTarget as HTMLSelectElement).closest('td')!;
                                const input = cell.querySelector('input') as HTMLInputElement;
                                input.value = '0';
                              }
                            }}
                          >
                            {(Object.keys(HOURS_NOTE_LABELS) as HoursNoteType[]).map((t) => (
                              <option key={t} value={t}>{HOURS_NOTE_LABELS[t]}</option>
                            ))}
                          </select>
                          <button
                            onClick={(e) => {
                              const cell = (e.currentTarget as HTMLButtonElement).closest('td')!;
                              const input = cell.querySelector('input') as HTMLInputElement;
                              const select = cell.querySelector('select') as HTMLSelectElement;
                              void saveDay(day.date, input.value, select.value as HoursNoteType);
                            }}
                            disabled={savingDate === day.date}
                            className="w-full py-1.5 rounded-xl text-xs font-bold text-white disabled:opacity-50"
                            style={{ background: C.forest }}
                          >
                            {savingDate === day.date ? 'جارٍ...' : 'حفظ'}
                          </button>
                          {day.notes && (
                            <p className="text-[10px] leading-tight" style={{ color: C.muted }}>{day.notes}</p>
                          )}
                        </div>
                      ) : (
                        <span style={{ color: C.line }}>—</span>
                      )}
                    </td>
                  ))}
                  <td className="p-3 border-t text-center font-bold" style={{ borderColor: C.line, color: C.forest }}>
                    {week.weekly_hours}
                  </td>
                </tr>
              </tbody>
            </table>
          ) : (
            <div className="p-10 text-center text-sm" style={{ color: C.muted }}>لا توجد بيانات لهذا الشهر.</div>
          )}
        </div>
      )}

      {summary && employeeId && (
        <div className="rounded-2xl border p-4 grid grid-cols-2 md:grid-cols-4 gap-3" style={{ borderColor: C.line, background: C.sage }}>
          <div>
            <p className="text-xs" style={{ color: C.muted }}>إجمالي ساعات {month}</p>
            <p className="text-lg font-bold" style={{ color: C.ink }}>{summary.total_hours} ساعة</p>
          </div>
          <div>
            <p className="text-xs" style={{ color: C.muted }}>الراتب المحتسب ({money(summary.hourly_rate)}/ساعة)</p>
            <p className="text-lg font-bold" style={{ color: C.forest }}>{money(summary.total_salary)} د.ت</p>
          </div>
          <div>
            <p className="text-xs" style={{ color: C.muted }}>أيام العمل</p>
            <p className="text-lg font-bold" style={{ color: C.ink }}>{summary.work_days}</p>
          </div>
          <div>
            <p className="text-xs" style={{ color: C.muted }}>أيام الغياب</p>
            <p className="text-lg font-bold" style={{ color: C.danger }}>{summary.absence_days}</p>
          </div>
          <p className="col-span-full text-xs" style={{ color: C.muted }}>
            هذا الحساب اقتراح فقط — الخلاص يتم يدوياً من تبويب الرواتب ولا يُنشئ النظام أي دفع تلقائي.
            <RefreshCw size={12} className="inline mr-1" />
          </p>
          <div className="col-span-full flex justify-end gap-2">
            <button
              onClick={() => setShowSalForm(true)}
              className="px-4 py-2 rounded-xl text-white text-sm font-bold"
              style={{ background: C.ink }}
            >
              خلّص الراتب
            </button>
            <button
              onClick={() => setShowPayslip(true)}
              className="px-4 py-2 rounded-xl text-white text-sm font-bold"
              style={{ background: C.forest }}
            >
              طباعة كشف الخلاص
            </button>
          </div>
        </div>
      )}

      {showPayslip && employee && summary && (
        <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4 print:bg-transparent print:p-0 print:items-start print:overflow-visible">
          <div className="bg-white rounded-2xl p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto print:max-h-none print:p-0 print:rounded-none print:shadow-none print:overflow-visible">
            <div className="flex justify-end mb-3 print:hidden">
              <button
                onClick={() => setShowPayslip(false)}
                className="px-4 py-2 rounded-xl border text-sm"
                style={{ borderColor: C.line }}
              >
                إغلاق
              </button>
            </div>
            <PayslipReport
              employee={employee}
              month={month}
              summary={summary}
              days={
                (grid?.weeks ?? [])
                  .flatMap((w) => w.days)
                  .filter((d) => d.in_month && (d.id !== null || Number(d.hours) > 0 || d.note_type !== 'normal'))
                  .map((d) => ({ date: d.date, note_type: d.note_type, hours: d.hours }))
              }
            />
          </div>
        </div>
      )}

      {showSalForm && employee && (
        <SalaryFormModal
          employees={[employee]}
          years={years}
          defaultYearId={defaultYearId}
          saving={salarySaving}
          onClose={() => setShowSalForm(false)}
          onError={onError}
          onSubmit={async (values) => {
            await onCreateSalary(values);
            setShowSalForm(false);
          }}
        />
      )}
    </div>
  );
}