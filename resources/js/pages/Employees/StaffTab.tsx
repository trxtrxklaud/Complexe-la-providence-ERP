import { Plus, Trash2 } from 'lucide-react';
import type { Employee } from '../../api/employees';
import { C, money } from './shared';

interface Props {
  employees: Employee[];
  onNewEmployee: () => void;
  onDeleteEmployee: (employee: Employee) => void;
}

/** قائمة الإطارات المسجّلين في المدرسة. */
export function StaffTab({ employees, onNewEmployee, onDeleteEmployee }: Props) {
  return (
    <>
      <div className="flex justify-end">
        <button onClick={onNewEmployee} className="px-4 py-2.5 rounded-xl text-white text-sm font-bold flex items-center gap-2" style={{ background: C.forest }}>
          <Plus size={16} /> إضافة إطار
        </button>
      </div>

      <div className="bg-white rounded-2xl border overflow-x-auto" style={{ borderColor: C.line }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ color: C.muted, textAlign: 'right' }}>
              <th className="p-3">#</th>
              <th>الاسم</th>
              <th>اللقب</th>
              <th>الهاتف</th>
              <th>الوظيفة</th>
              <th>الراتب الافتراضي</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {employees.length === 0 ? (
              <tr><td colSpan={7} className="p-6 text-center" style={{ color: C.muted }}>لا يوجد إطارات مسجّلة</td></tr>
            ) : employees.map((e) => (
              <tr key={e.id} className="border-t" style={{ borderColor: C.line }}>
                <td className="p-3">{e.id}</td>
                <td>{e.first_name}</td>
                <td>{e.last_name}</td>
                <td>{e.phone || '—'}</td>
                <td>{e.job_title || '—'}</td>
                <td>{e.default_salary != null ? money(e.default_salary) : '—'}</td>
                <td>
                  <button onClick={() => onDeleteEmployee(e)} title="حذف الإطار"
                    className="p-2" style={{ color: C.dangerBtn }}>
                    <Trash2 size={16} />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
