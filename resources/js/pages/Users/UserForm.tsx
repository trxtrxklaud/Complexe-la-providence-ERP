import React, { useEffect, useState } from 'react';
import { useNavigate, useParams, Link } from 'react-router-dom';
import { fetchUser, createUser, updateUser, fetchRoles, Role } from '../../api/users';
import { ArrowRight, Save, X } from 'lucide-react';

export function UserForm() {
  const { id } = useParams();
  const isEdit = !!id;
  const navigate = useNavigate();

  const [roles, setRoles] = useState<Role[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    username: '',
    email: '',
    phone: '',
    role_id: '',
    password: '',
    password_confirmation: '',
  });

  useEffect(() => {
    loadInitialData();
  }, [id]);

  async function loadInitialData() {
    try {
      const rolesData = await fetchRoles();
      setRoles(rolesData);

      if (isEdit) {
        const userData = await fetchUser(Number(id));
        setFormData({
          first_name: userData.first_name,
          last_name: userData.last_name,
          username: userData.username,
          email: userData.email,
          phone: userData.phone || '',
          role_id: String(userData.role_id),
          password: '',
          password_confirmation: '',
        });
      }
    } catch (err: any) {
      setError(err.message || 'حدث خطأ أثناء تحميل البيانات');
    }
  }

  function handleChange(e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) {
    setFormData(prev => ({ ...prev, [e.target.name]: e.target.value }));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const payload: any = {
        first_name: formData.first_name,
        last_name: formData.last_name,
        username: formData.username,
        email: formData.email,
        phone: formData.phone || null,
        role_id: Number(formData.role_id),
      };

      if (formData.password) {
        payload.password = formData.password;
        payload.password_confirmation = formData.password_confirmation;
      }

      if (isEdit) {
        await updateUser(Number(id), payload);
      } else {
        await createUser(payload);
      }
      navigate('/users');
    } catch (err: any) {
      setError(err.message || 'فشل حفظ بيانات المستخدم');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="p-8 max-w-4xl mx-auto">
      <div className="flex items-center gap-4 mb-8">
        <Link to="/users" className="p-2 bg-white text-slate-500 hover:text-slate-800 rounded-full shadow-sm border border-slate-200 transition-colors">
          <ArrowRight size={20} />
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-slate-800">
            {isEdit ? 'تعديل بيانات المستخدم' : 'إضافة مستخدم جديد'}
          </h1>
          <p className="text-slate-500 mt-1">يرجى تعبئة كافة الحقول المطلوبة</p>
        </div>
      </div>

      {error && (
        <div className="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
          {error}
        </div>
      )}

      <form onSubmit={handleSubmit} className="bg-white p-8 rounded-xl shadow-sm border border-slate-200">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-2">الاسم <span className="text-red-500">*</span></label>
            <input
              type="text"
              name="first_name"
              required
              value={formData.first_name}
              onChange={handleChange}
              className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-2">اللقب <span className="text-red-500">*</span></label>
            <input
              type="text"
              name="last_name"
              required
              value={formData.last_name}
              onChange={handleChange}
              className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-2">اسم المستخدم <span className="text-red-500">*</span></label>
            <input
              type="text"
              name="username"
              required
              dir="ltr"
              value={formData.username}
              onChange={handleChange}
              className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-left"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-2">البريد الإلكتروني <span className="text-red-500">*</span></label>
            <input
              type="email"
              name="email"
              required
              dir="ltr"
              value={formData.email}
              onChange={handleChange}
              className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-left"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-2">رقم الهاتف</label>
            <input
              type="tel"
              name="phone"
              dir="ltr"
              value={formData.phone}
              onChange={handleChange}
              className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-left"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-2">الوظيفة (Role) <span className="text-red-500">*</span></label>
            <select
              name="role_id"
              required
              value={formData.role_id}
              onChange={handleChange}
              className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
            >
              <option value="">-- اختر الوظيفة --</option>
              {roles.map(role => (
                <option key={role.id} value={role.id}>
                  {role.display_name}
                </option>
              ))}
            </select>
          </div>
          
          <div className="md:col-span-2 mt-4 pt-6 border-t border-slate-100">
            <h3 className="text-lg font-medium text-slate-800 mb-4">الأمان وكلمة المرور</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">
                  كلمة المرور {isEdit ? '(اتركه فارغاً إذا لم ترد تغييره)' : '<span className="text-red-500">*</span>'}
                </label>
                <input
                  type="password"
                  name="password"
                  required={!isEdit}
                  dir="ltr"
                  value={formData.password}
                  onChange={handleChange}
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-left"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">
                  تأكيد كلمة المرور {isEdit ? '' : '<span className="text-red-500">*</span>'}
                </label>
                <input
                  type="password"
                  name="password_confirmation"
                  required={!isEdit || !!formData.password}
                  dir="ltr"
                  value={formData.password_confirmation}
                  onChange={handleChange}
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-left"
                />
              </div>
            </div>
          </div>
        </div>

        <div className="mt-8 pt-6 border-t border-slate-100 flex items-center justify-end gap-4">
          <Link
            to="/users"
            className="flex items-center gap-2 px-6 py-2.5 text-slate-600 hover:bg-slate-100 rounded-lg font-medium transition-colors"
          >
            <X size={18} />
            إلغاء
          </Link>
          <button
            type="submit"
            disabled={loading}
            className="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-8 py-2.5 rounded-lg font-medium transition-colors shadow-sm disabled:opacity-70"
          >
            <Save size={18} />
            {loading ? 'جاري الحفظ...' : 'حفظ'}
          </button>
        </div>
      </form>
    </div>
  );
}
