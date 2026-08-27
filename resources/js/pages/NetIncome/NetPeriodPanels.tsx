/**
 * مكوّنات كشف الدخل الصافي.
 *
 * تخطيط ثابت بعمودين: المداخيل يميناً والمصاريف يساراً داخل جدول واحد
 * مأطّر، ثم سطر المجاميع، ثم سطر الدخل الصافي ممتدّاً على العمودين، ليسهل
 * تدقيقه ومقارنته ورقياً. السبب عملي لا جمالي: الإدارة تقابل المطبوع
 * بالشاشة سطراً بسطر، وأيّ إعادة ترتيب للأعمدة تجعل هذه المقابلة مرهقة
 * ومصدر شكّ في الأرقام.
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

/* ترويسة المطبوع الرسمية للمؤسسة. */
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
 * الدينار بثلاث خانات عشرية (الخانة الثالثة هي المليم).
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
 *
 * لماذا كانت الجداول تتجاوز الورقة قبل هذا التعديل: العناصر المخفيّة بـ
 * visibility تبقى محتلّة مكانها، فيبقى عرض التخطيط عرض الشاشة (بما فيه القائمة
 * الجانبية)، ومنطقة التقرير المقيسة بـ 100% كانت تقيس على ذلك العرض العريض ثم
 * يقصّ المتصفّح ما زاد عن الورقة. العلاج: تثبيت العرض والحشو والقياس على صندوق
 * الورقة نفسه، وإلزام الجدول بألّا يتجاوزه (table-layout: fixed + كسر الكلمات).
 */
export function PrintStyles() {
  return (
    <style>{`
      @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800&display=swap');
      @media print {
        @page { size: A4 landscape; margin: 8mm; }

        body * { visibility: hidden !important; }
        #net-print-area, #net-print-area * { visibility: visible !important; }

        #net-print-area {
          position: absolute !important;
          top: 0 !important; left: 0 !important; right: 0 !important;
          width: 100% !important;
          min-height: 88vh !important;
          height: auto !important;
          margin: 0 !important;
          padding: 8mm !important;
          box-sizing: border-box !important;
          background: #fff !important;
          border: 1px solid #2a9d8f !important;
          border-radius: 0 !important;
          display: block !important;
          font-family: 'Cairo', sans-serif !important;
          font-size: 14px !important;
          font-weight: 600 !important;
          overflow: visible !important;
        }

        #net-print-area table {
          width: 100% !important;
          max-width: 100% !important;
          table-layout: fixed !important;
          border-collapse: collapse !important;
          font-size: 11pt !important;
        }

        #net-print-area th {
          background: #2a9d8f !important;
          color: #fff !important;
          font-size: 10.5pt !important;
          font-weight: 700 !important;
          padding: 6px 8px !important;
          border: 1px solid #2a9d8f !important;
        }
        #net-print-area td {
          font-size: 10pt !important;
          font-weight: 600 !important;
          padding: 5px 6px !important;
          white-space: normal !important;
          word-break: break-word !important;
          overflow-wrap: break-word !important;
          border: 1px solid #ccc !important;
        }

        #net-print-area thead { display: table-header-group; }
        #net-print-area tr { break-inside: avoid; page-break-inside: avoid; }

        .no-print { display: none !important; }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
      }
    `}</style>
  );
}

/** ترويسة لا تظهر إلا في المطبوع، تحمل هوية المؤسسة — تصميم احترافي مثل وصل الاستخلاص. */
export function PrintHeader({ date }: { date: string }) {
  return (
    <>
      <div className="hidden print:block text-center mb-2" style={{ borderBottom: '2px solid #2a9d8f', paddingBottom: 6 }}>
        <div style={{ fontWeight: 800, fontSize: 15, color: '#c8a96e', letterSpacing: 0.5, lineHeight: 1.3 }}>Complexe La Providence</div>
        <div style={{ fontSize: 9, fontWeight: 600, color: '#7d93a8', direction: 'ltr' }}>Tel: 95420350 / 76624400 — {date}</div>
      </div>
      <table className="w-full hidden print:table mb-4" style={{ color: '#1a3a5c', border: '1px solid #2a9d8f' }}>
        <tbody>
          <tr>
            <td className="w-1/2 text-center align-top" style={{ padding: '8px', fontWeight: 600 }}>
              <strong style={{ color: '#c8a96e' }}>{SCHOOL_FR}</strong>
              <br />
              {CITY_FR}
              <br />
              {date}
            </td>
            <td className="w-1/2 text-center align-top" style={{ padding: '8px', fontWeight: 600 }}>
              <strong style={{ color: '#2a9d8f' }}>{SCHOOL_AR}</strong>
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
    </>
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
