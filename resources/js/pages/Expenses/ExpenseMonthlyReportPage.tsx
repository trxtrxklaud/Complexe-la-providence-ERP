import { CalendarRange } from 'lucide-react';
import { PeriodReport } from '../../components/PeriodReport';
import { fetchExpenseReport } from '../../api/reports';
import { today } from '../../lib/format';

function yearStart(): string {
  return `${new Date().getFullYear()}-01-01`;
}

/**
 * التقرير الشهري للمصاريف — نفس بيانات التقرير اليومي بدرجة تجميع أعلى.
 */
export function ExpenseMonthlyReportPage() {
  return (
    <PeriodReport
      title="التقرير الشهري للمصاريف"
      subtitle="مصاريف كل شهر مفصّلة على البنود"
      icon={CalendarRange}
      granularity="month"
      periodLabel="الشهر"
      initialFrom={yearStart()}
      initialTo={today()}
      load={fetchExpenseReport}
    />
  );
}
