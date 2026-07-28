/**
 * مكوّنات كشف الدخل الصافي.
 *
 * الشكل منسوخ عن تقرير المنصّة القديمة عن قصد: عمود المداخيل يميناً وعمود
 * المصاريف يساراً داخل جدول واحد مؤطَّر، ثم سطر المجاميع، ثم سطر الدخل الصافي
 * ممتدّاً على العمودين. السبب عملي لا جمالي: الإدارة تقابل الورقة القديمة
 * بالشاشة الجديدة سطراً بسطر أثناء فترة الانتقال، وأيّ إعادة ترتيب للأعمدة
 * تجعل هذه المقابلة مرهقة ومصدر شكّ في الأرقام.
 *
 * قاعدة صارمة: لا عملية حسابية واحدة في هذا الملف. كل رقم يصل محسوباً من
 * الخادم انطلاقاً من cash_transactions.
 */
import type { ReactNode } from 'react';
import { Printer } from 'lucide-react';

export const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  bg: '#F4F6F1',
  error: '#A03434',
  errorBg: '#FDECEC',
};

/* ترويسة المطبوع كما في التقرير القديم. */
const SCHOOL_FR = 'COMPLEXE LA PROVIDENCE';
const CITY_FR = 'Sidi Bouzid';
const SCHOOL_AR = 'مركب العناية للتعليم الخاص';
const CITY_AR = 'سيدي بوزيد';
const PHONE_1 = 'الهاتف: 400 624 76';
const PHONE_2 = 'الجوال: 502 476 93';

export type ReportLine = { category: string; label: string; total: number };
export type ReportSide = { lines: ReportLine[]; total: number };
export type DetailLine = {
  id: number | string;
  label: string;
  description?: string | null;
  amount: number;
  direction: 'in' | 'out';
};

/**
 * الدينار بثلاث خانات كما في المطبوع القديم (الخانة الثالثة هي المليم).
 *
 * تنبيه مقصود: أعمدة المبالغ في قاعدة البيانات مخزَّنة بخانتين، فالخانة الثالثة
 * المعروضة هنا هي دائماً صفر. العرض صادق لأنّه لا يخترع رقماً، لكن إدخال مبالغ
 * بالمليم يحتاج تعديل المخطّط لا تعديل هذه الدالة.
 */
export function dinar(value: number | string | null | undefined): string {
  return `${Number(value ?? 0).toFixed(3)} د`;
}

/**
 * إخفاء كل ما عدا منطقة التقرير عند الطباعة.
 *
 * استُعملت visibility بدل display لأنّها لا تتعلّق ببنية القائمة الجانبية،
 * فلا تنكسر الطباعة إذا تغيّر ترميز Sidebar لاحقاً.
 */
export function PrintStyles() {
  return (
    <style>{`
      @media print {
        body * { visibility: hidden !important; }
        #net-print-area, #net-print-area * { visibility: visible !important; }
        #net-print-area {
          position: absolute;
          top: 0;
          right: 0;
          left: 0;
          width: 100%;
        }
        .no-print { display: none !important; }
      }
    `}</style>
  );
}

/** ترويسة لا تظهر إلا في المطبوع، مطابقة لترويسة التقرير القديم. */
export function PrintHeader({ date }: { date: string }) {
  return (
    <table className="w-full hidden print:table mb-4" style={{ color: '#000' }}>
      <tbody>
        <tr>
          <td className="w-1/2 text-center align-top">
            <strong>{SCHOOL_FR}</strong>
            <br />
            {CITY_FR}
            <br />
            {date}
          </td>
          <td className="w-1/2 text-center align-top">
            <strong>{SCHOOL_AR}</strong>
            <br />
            {CITY_AR}
            <br />
            {PHONE_1}
            <br />
            {PHONE_2}
          </td>
        </tr>
      </tbody>
    </table>
  );
}

/** شريط المرشِّحات: حقول ثم زر طباعة، ولا يظهر في المطبوع. */
export function FilterBar({ children }: { children: ReactNode }) {
  return (
    <div
      className="no-print bg-white rounded-2xl p-4 mb-4 flex flex-wrap items-end gap-4"
      style={{ border: `1px solid ${C.line}` }}
    >
      {children}
      <button
        type="button"
        onClick={() => window.print()}
        className="flex items-center gap-2 rounded-xl px-4 py-2 text-sm text-white mr-auto"
        style={{ backgroundColor: C.forest }}
      >
        <Printer size={16} />
        طباعة
      </button>
    </div>
  );
}

function SideCell({
  side,
  details,
  showDetails,
}: {
  side: ReportSide;
  details: DetailLine[];
  showDetails: boolean;
}) {
  return (
    <td className="align-top p-4" style={{ border: `1px solid ${C.line}`, width: '50%' }}>
      <ul className="space-y-1 text-sm">
        {side.lines.map((line) => (
          <li key={line.category} className="flex items-center justify-between gap-4">
            <span style={{ color: C.muted }}>{line.label}:</span>
            <strong style={{ color: C.ink }}>{dinar(line.total)}</strong>
          </li>
        ))}
      </ul>

      {showDetails && (
        <>
          <hr className="my-3" style={{ borderColor: C.line }} />
          <strong className="text-sm" style={{ color: C.ink }}>
            تفاصيل:
          </strong>
          <ul className="mt-1 space-y-1" style={{ fontSize: '13px', color: C.muted }}>
            {details.length === 0 && <li>لا توجد حركات.</li>}
            {details.map((line) => (
              <li key={line.id}>
                {line.description || line.label} ({dinar(line.amount)})
              </li>
            ))}
          </ul>
        </>
      )}
    </td>
  );
}

/**
 * جدول الكشف الواحد.
 *
 * السحوبات معروضة تحت الدخل الصافي لا ضمن المصاريف، لأنّ السحب نقل أموال
 * لا استهلاك: لا يُنقِص الدخل الصافي لكنّه يُنقِص الرصيد.
 */
export function NetReportTable({
  caption,
  income,
  expenses,
  netIncome,
  withdrawals,
  balance,
  incomeDetails = [],
  expenseDetails = [],
  showDetails = false,
}: {
  caption: string;
  income: ReportSide;
  expenses: ReportSide;
  netIncome: number;
  withdrawals: number;
  balance: number;
  incomeDetails?: DetailLine[];
  expenseDetails?: DetailLine[];
  showDetails?: boolean;
}) {
  return (
    <table className="w-full bg-white text-sm mb-4" style={{ border: `1px solid ${C.line}` }}>
      <thead>
        <tr>
          <th
            colSpan={2}
            className="text-center px-4 py-3 font-bold"
            style={{ backgroundColor: C.sage, color: C.ink, border: `1px solid ${C.line}` }}
          >
            {caption}
          </th>
        </tr>
        <tr>
          <th
            className="text-center px-4 py-2 font-semibold"
            style={{ color: C.forest, border: `1px solid ${C.line}` }}
          >
            المداخيل
          </th>
          <th
            className="text-center px-4 py-2 font-semibold"
            style={{ color: C.error, border: `1px solid ${C.line}` }}
          >
            المصاريف
          </th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <SideCell side={income} details={incomeDetails} showDetails={showDetails} />
          <SideCell side={expenses} details={expenseDetails} showDetails={showDetails} />
        </tr>
        <tr style={{ backgroundColor: '#F7F9F4' }}>
          <td className="px-4 py-3 font-bold" style={{ border: `1px solid ${C.line}`, color: C.forest }}>
            مجموع المداخيل: {dinar(income.total)}
          </td>
          <td className="px-4 py-3 font-bold" style={{ border: `1px solid ${C.line}`, color: C.error }}>
            مجموع المصاريف: {dinar(expenses.total)}
          </td>
        </tr>
        <tr>
          <td
            colSpan={2}
            className="px-4 py-3 font-bold text-center"
            style={{ border: `1px solid ${C.line}`, color: C.error, fontSize: '15px' }}
          >
            الدخل الصافي: {dinar(netIncome)}
          </td>
        </tr>
        <tr>
          <td className="px-4 py-2" style={{ border: `1px solid ${C.line}`, color: C.muted }}>
            السحوبات: {dinar(withdrawals)}
          </td>
          <td className="px-4 py-2 font-bold" style={{ border: `1px solid ${C.line}`, color: C.ink }}>
            الرصيد بعد السحوبات: {dinar(balance)}
          </td>
        </tr>
      </tbody>
    </table>
  );
}
