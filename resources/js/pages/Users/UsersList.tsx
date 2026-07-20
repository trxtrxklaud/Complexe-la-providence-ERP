import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchUsers, deleteUser, User } from '../../api/users';
import { Plus, Edit2, Trash2, AlertCircle, Users } from 'lucide-react';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
};

export function UsersList() {
  const [users, setUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    loadUsers();
  }, []);

  async function loadUsers() {
    try {
      setLoading(true);
      setError(null);
      const data = await fetchUsers();
      setUsers(data);
    } catch (err: any) {
      setError(err.message || 'حدث خطأ أثناء جلب المستخدمين.');
    } finally {
      setLoading(false);
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('هل أنت متأكد من حذف هذا المستخدم؟')) return;
    try {
      await deleteUser(id);
      setUsers(users.filter((u) => u.id !== id));
    } catch (err: any) {
      alert(err.message || 'فشل حذف المستخدم');
    }
  }

  return (
    <div className="p-6 md:p-8" dir="rtl">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: C.ink }}>
            إدارة المستخدمين
          </h1>
          <p className="mt-1 text-sm" style={{ color: C.muted }}>
            عرض وإدارة حسابات الأساتذة والموظفين
          </p>
        </div>

        <Link
          to="/users/add"
          className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-white font-medium transition hover:opacity-90"
          style={{ backgroundColor: C.forest }}
        >
          <Plus size={18} />
          إضافة مستخدم جديد
        </Link>
      </div>

      {error && (
        <div className="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 flex items-center gap-3">
          <AlertCircle size={20} />
          <p>{error}</p>
        </div>
      )}

      {/* Table Card */}
      <div className="bg-white rounded-[22px] border overflow-hidden" style={{ borderColor: C.line }}>
        <div className="overflow-x-auto">
          <table className="w-full text-right text-sm">
            <thead>
              <tr style={{ backgroundColor: '#F7F9F5' }}>
                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>الاسم الكامل</th>
                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>اسم المستخدم</th>
                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>البريد</th>
                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>الهاتف</th>
                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>الوظيفة</th>
                <th className="px-6 py-4 font-semibold w-28" style={{ color: C.muted }}>إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={6} className="px-6 py-12 text-center" style={{ color: C.muted }}>
                    جاري التحميل...
                  </td>
                </tr>
              ) : users.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-6 py-12 text-center" style={{ color: C.muted }}>
                    لا يوجد مستخدمين لعرضهم
                  </td>
                </tr>
              ) : (
                users.map((user) => (
                  <tr key={user.id} className="border-t hover:bg-[#FAFBF8] transition" style={{ borderColor: C.line }}>
                    <td className="px-6 py-4 font-medium" style={{ color: C.ink }}>
                      {user.first_name} {user.last_name}
                    </td>
                    <td className="px-6 py-4" style={{ color: C.muted }} dir="ltr">
                      {user.username}
                    </td>
                    <td className="px-6 py-4" style={{ color: C.muted }}>
                      {user.email}
                    </td>
                    <td className="px-6 py-4" style={{ color: C.muted }} dir="ltr">
                      {user.phone || '—'}
                    </td>
                    <td className="px-6 py-4">
                      <span
                        className="inline-flex px-3 py-1 rounded-full text-xs font-medium"
                        style={{ backgroundColor: C.sage, color: C.forest }}
                      >
                        {user.role?.display_name || 'غير محدد'}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <Link
                          to={`/users/edit/${user.id}`}
                          className="p-2 rounded-lg hover:bg-[#E3EBDB] transition"
                          style={{ color: C.forest }}
                          title="تعديل"
                        >
                          <Edit2 size={17} />
                        </Link>
                        <button
                          onClick={() => handleDelete(user.id)}
                          className="p-2 rounded-lg hover:bg-red-50 transition text-red-500"
                          title="حذف"
                        >
                          <Trash2 size={17} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
