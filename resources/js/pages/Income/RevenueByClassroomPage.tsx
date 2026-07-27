import { Layers } from 'lucide-react';
import { PageShell } from '../../components/PageShell';

export function RevenueByClassroomPage() {
  return (
    <div className="px-6 pb-6 max-w-6xl mx-auto">
      <PageShell
        title="المداخيل حسب القسم"
        subtitle="توزيع المداخيل حسب الأقسام والمستويات"
        icon={Layers}
      />
    </div>
  );
}
