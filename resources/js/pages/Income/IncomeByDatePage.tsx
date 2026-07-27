import { CalendarDays } from 'lucide-react';
import { PageShell } from '../../components/PageShell';

export function IncomeByDatePage() {
  return (
    <div className="px-6 pb-6 max-w-6xl mx-auto">
      <PageShell
        title="المداخيل حسب التاريخ"
        subtitle="عرض مداخيل التلاميذ ضمن مجال تاريخي محدد (يوم / مدة)"
        icon={CalendarDays}
      />
    </div>
  );
}
