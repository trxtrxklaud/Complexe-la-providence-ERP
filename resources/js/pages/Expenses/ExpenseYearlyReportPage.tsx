import { CalendarClock } from 'lucide-react';
import { PeriodReport } from '../../components/PeriodReport';
import { fetchExpenseReport } from '../../api/reports';
import { today } from '../../lib/format';

function fiveYearsAgo(): string {
  return `${new Date().getFullYear() - 5}-01-01`;
}

/**
 * التقرير السنوي للمصاريف — أعلى درجة تجميع لنفس الدفتر.
 */
export function ExpenseYearlyReportPage() {
  return (
    <PeriodReport
      title="التقرير السنوي للمصاريف"
      subtitle="مصاريف كل سنة مفصّلة على البنود"
      icon={CalendarClock}
      granularity="year"
      periodLabel="السنة"
      initialFrom={fiveYearsAgo()}
      initialTo={today()}
      load={fetchExpenseReport}
    />
  );
}
