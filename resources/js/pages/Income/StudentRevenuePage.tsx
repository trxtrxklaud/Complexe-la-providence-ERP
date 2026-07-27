import { TrendingUp } from 'lucide-react';
import { PageShell } from '../../components/PageShell';

export function StudentRevenuePage() {
  return (
    <div className="px-6 pb-6 max-w-6xl mx-auto">
      <PageShell
        title="مداخيل التلاميذ"
        subtitle="إجمالي مداخيل التلاميذ وتفصيلها"
        icon={TrendingUp}
      />
    </div>
  );
}
