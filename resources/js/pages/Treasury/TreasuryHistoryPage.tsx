import { History } from 'lucide-react';
import { PageShell } from '../../components/PageShell';

export function TreasuryHistoryPage() {
  return (
    <div className="px-6 pb-6 max-w-6xl mx-auto">
      <PageShell
        title="سجل حركات الخزينة"
        subtitle="كل الحركات الداخلة والخارجة (مداخيل / مصاريف / سحوبات)"
        icon={History}
      />
    </div>
  );
}
