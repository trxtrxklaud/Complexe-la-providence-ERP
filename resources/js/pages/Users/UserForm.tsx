import React, { useEffect, useState } from 'react';
import { useNavigate, useParams, Link } from 'react-router-dom';
import { fetchUser, createUser, updateUser, fetchRoles } from '../../api/users';
import type { Role } from '../../types';
import { ArrowRight, Save, X } from 'lucide-react';

interface FieldErrors {
    [key: string]: string[];
}

export function UserForm() {
    const { id } = useParams();
    const isEdit = !!id;
    const navigate = useNavigate();

    const [roles, setRoles] = useState<Role[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

    const [formData, setFormData] = useState({
        first_name: '',
        last_name: '',
        username: '',
        email: '',
        phone: '',
        role_id: '',
        is_active: true,
        password: '',
        password_confirmation: '',
    });

    useEffect(() => { loadInitialData(); }, [id]);

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
                    phone: userData.phone ?? '',
                    role_id: String(userData.role_id),
                    is_active: userData.is_active,
                    password: '',
                    password_confirmation: '',
                });
            }
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : 'حدث خطأ أثناء تحميل البيانات');
        }
    }

    function handleChange(e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) {
        const { name, value, type } = e.target;
        const val = type === 'checkbox' ? (e.target as HTMLInputElement).checked : value;
        setFormData(prev => ({ ...prev, [name]: val }));
        // إزالة خطأ الحقل عند التعديل
        if (fieldErrors[name]) {
            setFieldErrors(prev => { const n = { ...prev }; delete n[name]; return n; });
        }
    }

    function validateClient(): boolean {
        if (formData.password && formData.password !== formData.password_confirmation) {
            setFieldErrors({ password_confirmation: ['كلمة المرور وتأكيدها غير متطابقتين'] });
            return false;
        }
        return true;
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!validateClient()) return;

        setLoading(true);
        setError(null);
        setFieldErrors({});

        try {
            const payload: Record<string, unknown> = {
                first_name: formData.first_name,
                last_name: formData.last_name,
                username: formData.username,
                email: formData.email,
                phone: formData.phone || null,
                role_id: Number(formData.role_id),
                is_active: formData.is_active,
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
        } catch (err: unknown) {
            if (err instanceof Error) {
                // محاولة تحليل validation errors من Laravel (422)
                try {
                    const parsed = JSON.parse(err.message);
                    if (parsed.errors) { setFieldErrors(parsed.errors); return; }
                } catch { /* not JSON */ }
                setError(err.message);
            } else {
                setError('فشل حفظ بيانات المستخدم');
            }
        } finally {
            setLoading(false);
        }
    }

    function fieldError(name: string) {
        return fieldErrors[name]?.[0];
    }

    const inputClass = (name: string) =>
        `w-full px-4 py-2.5 bg-slate-50 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all ${
            fieldError(name) ? 'border-red-400' : 'border-slate-200'
        }`;

    return (
        <div className="p-8 max-w-4xl mx-auto" dir="rtl">
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

                    {/* الاسم */}
                    <div>
                        <label className="block text-sm font-medium text-slate-700 mb-2">
                            الاسم <span className="text-red-500">*</span>
                        </label>
                        <input type="text" name="first_name" required value={formData.first_name}
                            onChange={handleChange} className={inputClass('first_name')} />
                        {fieldError('first_name') && <p className="mt-1 text-xs text-red-600">{fieldError('first_name')}</p>}
                    </div>

                    {/* اللقب */}
                    <div>
                        <label className="block text-sm font-medium text-slate-700 mb-2">
                            اللقب <span className="text-red-500">*</span>
                        </label>
                        <input type="text" name="last_name" required value={formData.last_name}
                            onChange={handleChange} className={inputClass('last_name')} />
                        {fieldError('last_name') && <p className="mt-1 text-xs text-red-600">{fieldError('last_name')}</p>}
                    </div>

                    {/* اسم المستخدم */}
                    <div>
                        <label className="block text-sm font-medium text-slate-700 mb-2">
                            اسم المستخدم <span className="text-red-500">*</span>
                        </label>
                        <input type="text" name="username" required dir="ltr" value={formData.username}
                            onChange={handleChange} className={`${inputClass('username')} text-left`} />
                        {fieldError('username') && <p className="mt-1 text-xs text-red-600">{fieldError('username')}</p>}
                    </div>

                    {/* البريد */}
                    <div>
                        <label className="block text-sm font-medium text-slate-700 mb-2">
                            البريد الإلكتروني <span className="text-red-500">*</span>
                        </label>
                        <input type="email" name="email" required dir="ltr" value={formData.email}
                            onChange={handleChange} className={`${inputClass('email')} text-left`} />
                        {fieldError('email') && <p className="mt-1 text-xs text-red-600">{fieldError('email')}</p>}
                    </div>

                    {/* الهاتف */}
                    <div>
                        <label className="block text-sm font-medium text-slate-700 mb-2">رقم الهاتف</label>
                        <input type="tel" name="phone" dir="ltr" value={formData.phone}
                            onChange={handleChange} className={`${inputClass('phone')} text-left`} />
                    </div>

                    {/* الدور */}
                    <div>
                        <label className="block text-sm font-medium text-slate-700 mb-2">
                            الوظيفة <span className="text-red-500">*</span>
                        </label>
                        <select name="role_id" required value={formData.role_id}
                            onChange={handleChange} className={inputClass('role_id')}>
                            <option value="">-- اختر الوظيفة --</option>
                            {roles.map(role => (
                                <option key={role.id} value={role.id}>{role.display_name}</option>
                            ))}
                        </select>
                        {fieldError('role_id') && <p className="mt-1 text-xs text-red-600">{fieldError('role_id')}</p>}
                    </div>

                    {/* is_active — يظهر فقط عند التعديل */}
                    {isEdit && (
                        <div className="md:col-span-2">
                            <label className="flex items-center gap-3 cursor-pointer w-fit">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    checked={formData.is_active}
                                    onChange={handleChange}
                                    className="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                />
                                <span className="text-sm font-medium text-slate-700">الحساب مفعَّل</span>
                            </label>
                            {!formData.is_active && (
                                <p className="mt-1 text-xs text-amber-600 mr-7">
                                    هذا الحساب معطَّل — لن يتمكن صاحبه من تسجيل الدخول.
                                </p>
                            )}
                        </div>
                    )}

                    {/* كلمة المرور */}
                    <div className="md:col-span-2 mt-4 pt-6 border-t border-slate-100">
                        <h3 className="text-lg font-medium text-slate-800 mb-4">الأمان وكلمة المرور</h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-2">
                                    كلمة المرور
                                    {!isEdit && <span className="text-red-500"> *</span>}
                                    {isEdit && <span className="text-slate-400 text-xs mr-1">(اتركه فارغاً إذا لم ترد تغييره)</span>}
                                </label>
                                <input type="password" name="password" required={!isEdit} dir="ltr"
                                    value={formData.password} onChange={handleChange}
                                    className={`${inputClass('password')} text-left`} />
                                {fieldError('password') && <p className="mt-1 text-xs text-red-600">{fieldError('password')}</p>}
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-2">
                                    تأكيد كلمة المرور
                                    {(!isEdit || formData.password) && <span className="text-red-500"> *</span>}
                                </label>
                                <input type="password" name="password_confirmation" dir="ltr"
                                    required={!isEdit || !!formData.password}
                                    value={formData.password_confirmation} onChange={handleChange}
                                    className={`${inputClass('password_confirmation')} text-left`} />
                                {fieldError('password_confirmation') && (
                                    <p className="mt-1 text-xs text-red-600">{fieldError('password_confirmation')}</p>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Actions */}
                <div className="mt-8 pt-6 border-t border-slate-100 flex items-center justify-end gap-4">
                    <Link to="/users" className="flex items-center gap-2 px-6 py-2.5 text-slate-600 hover:bg-slate-100 rounded-lg font-medium transition-colors">
                        <X size={18} /> إلغاء
                    </Link>
                    <button type="submit" disabled={loading}
                        className="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-8 py-2.5 rounded-lg font-medium transition-colors shadow-sm disabled:opacity-70">
                        <Save size={18} />
                        {loading ? 'جاري الحفظ...' : 'حفظ'}
                    </button>
                </div>
            </form>
        </div>
    );
}
