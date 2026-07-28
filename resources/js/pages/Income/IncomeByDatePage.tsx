import { CalendarDays } from 'lucide-react';
import { PeriodReport } from '../../components/PeriodReport';
import { fetchIncomeByDate } from '../../api/reports';
import { monthStart, today } from '../../lib/format';

/**
 * المداخيل حسب التاريخ — سطر لكل يوم مفصّل على بنود المداخيل الستّة.
 */
export function IncomeByDatePage() {
  return (
    <PeriodReport
      title="المداخيل حسب التاريخ"
      subtitle="مداخيل كل يوم مفصّلة على البنود"
      icon={CalendarDays}
      granularity="day"
      periodLabel="التاريخ"
      initialFrom={monthStart()}
      initialTo={today()}
      load={fetchIncomeByDate}
    />
  );
}
