import { CalendarClock } from 'lucide-react';
import { PageShell } from '../../components/PageShell';

export function ExpenseYearlyReportPage() {
  return (
    <div className="px-6 pb-6 max-w-6xl mx-auto">
      <PageShell
        title="التقرير السنوي للمصاريف"
        subtitle="ملخّص المصاريف خلال السنة الدراسية"
        icon={CalendarClock}
      />
    </div>
  );
}
