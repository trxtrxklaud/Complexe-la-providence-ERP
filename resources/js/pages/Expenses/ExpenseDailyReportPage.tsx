import { CalendarDays } from 'lucide-react';
import { PeriodReport } from '../../components/PeriodReport';
import { fetchExpenseReport } from '../../api/reports';
import { monthStart, today } from '../../lib/format';

/**
 * التقرير اليومي للمصاريف — الأجور والسلف والمصاريف لكل يوم.
 */
export function ExpenseDailyReportPage() {
  return (
    <PeriodReport
      title="التقرير اليومي للمصاريف"
      subtitle="مصاريف كل يوم مفصّلة على البنود"
      icon={CalendarDays}
      granularity="day"
      periodLabel="التاريخ"
      initialFrom={monthStart()}
      initialTo={today()}
      load={fetchExpenseReport}
    />
  );
}
