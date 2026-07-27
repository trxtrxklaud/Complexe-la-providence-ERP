import { CalendarDays } from 'lucide-react';
import { PageShell } from '../../components/PageShell';

export function ExpenseDailyReportPage() {
  return (
    <div className="px-6 pb-6 max-w-6xl mx-auto">
      <PageShell
        title="التقرير اليومي للمصاريف"
        subtitle="ملخّص مصاريف اليوم الواحد"
        icon={CalendarDays}
      />
    </div>
  );
}
