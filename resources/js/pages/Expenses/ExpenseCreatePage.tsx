import { PlusCircle } from 'lucide-react';
import { PageShell } from '../../components/PageShell';

export function ExpenseCreatePage() {
  return (
    <div className="px-6 pb-6 max-w-6xl mx-auto">
      <PageShell
        title="إنشاء مصروف"
        subtitle="تسجيل مصروف جديد (الفئة، المبلغ، التاريخ، طريقة الدفع)"
        icon={PlusCircle}
      />
    </div>
  );
}
