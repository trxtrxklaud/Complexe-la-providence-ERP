import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchUsers, deleteUser, User } from '../../api/users';
import { Plus, Edit2, Trash2, AlertCircle } from 'lucide-react';

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
      setError(err.message || 'حدث خطأ أثناء جلب المستخدمين. تأكد من عمل الخادم.');
    } finally {
      setLoading(false);
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('هل أنت متأكد من حذف هذا المستخدم؟')) return;
    try {
      await deleteUser(id);
      setUsers(users.filter(u => u.id !== id));
    } catch (err: any) {
      alert(err.message || 'فشل حذف المستخدم');
    }
  }

  return (
    <div className="p-8 max-w-7xl mx-auto">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">إدارة المستخدمين</h1>
          <p className="text-slate-500 mt-1">عرض وإدارة حسابات النظام والصلاحيات</p>
        </div>
        <Link
          to="/users/add"
          className="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-medium transition-colors shadow-sm"
        >
          <Plus size={20} />
          إضافة مستخدم
        </Link>
      </div>

      {error && (
        <div className="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg flex items-center gap-3">
          <AlertCircle size={20} />
          <p>{error}</p>
        </div>
      )}

      <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-right text-sm">
            <thead className="bg-slate-50 border-b border-slate-200 text-slate-600">
              <tr>
                <th className="px-6 py-4 font-semibold">الاسم</th>
                <th className="px-6 py-4 font-semibold">اللقب</th>
                <th className="px-6 py-4 font-semibold">اسم المستخدم</th>
                <th className="px-6 py-4 font-semibold">البريد الإلكتروني</th>
                <th className="px-6 py-4 font-semibold">رقم الهاتف</th>
                <th className="px-6 py-4 font-semibold">الوظيفة</th>
                <th className="px-6 py-4 font-semibold w-24">إجراءات</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading ? (
                <tr>
                  <td colSpan={7} className="px-6 py-8 text-center text-slate-500">
                    جاري التحميل...
                  </td>
                </tr>
              ) : users.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-6 py-8 text-center text-slate-500">
                    لا يوجد مستخدمين لعرضهم
                  </td>
                </tr>
              ) : (
                users.map(user => (
                  <tr key={user.id} className="hover:bg-slate-50 transition-colors">
                    <td className="px-6 py-4 font-medium text-slate-800">{user.first_name}</td>
                    <td className="px-6 py-4 text-slate-700">{user.last_name}</td>
                    <td className="px-6 py-4 text-slate-600" dir="ltr">{user.username}</td>
                    <td className="px-6 py-4 text-slate-600">{user.email}</td>
                    <td className="px-6 py-4 text-slate-600" dir="ltr">{user.phone || '-'}</td>
                    <td className="px-6 py-4">
                      <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200">
                        {user.role?.display_name || 'غير محدد'}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <Link
                          to={`/users/edit/${user.id}`}
                          className="text-slate-400 hover:text-blue-600 transition-colors"
                          title="تعديل"
                        >
                          <Edit2 size={18} />
                        </Link>
                        <button
                          onClick={() => handleDelete(user.id)}
                          className="text-slate-400 hover:text-red-600 transition-colors"
                          title="حذف"
                        >
                          <Trash2 size={18} />
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
