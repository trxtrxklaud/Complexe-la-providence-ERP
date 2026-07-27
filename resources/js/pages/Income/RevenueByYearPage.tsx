import { CalendarRange } from 'lucide-react';
import { PageShell } from '../../components/PageShell';

export function RevenueByYearPage() {
  return (
    <div className="px-6 pb-6 max-w-6xl mx-auto">
      <PageShell
        title="المداخيل حسب السنة"
        subtitle="مقارنة المداخيل حسب السنة الدراسية"
        icon={CalendarRange}
      />
    </div>
  );
}
