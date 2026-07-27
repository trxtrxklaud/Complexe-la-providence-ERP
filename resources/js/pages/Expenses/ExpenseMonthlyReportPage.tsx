import { CalendarRange } from 'lucide-react';
import { PageShell } from '../../components/PageShell';

export function ExpenseMonthlyReportPage() {
  return (
    <div className="px-6 pb-6 max-w-6xl mx-auto">
      <PageShell
        title="التقرير الشهري للمصاريف"
        subtitle="ملخّص المصاريف خلال الشهر"
        icon={CalendarRange}
      />
    </div>
  );
}
