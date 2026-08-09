import { useEffect, useState } from 'react';
import { Plus, Edit2, Trash2, X, Save, AlertCircle, CheckCircle } from 'lucide-react';
import {
    getFeeTypes,
    createFeeType,
    updateFeeType,
    deleteFeeType,
    ledgerCategoryLabel,
    LEDGER_CATEGORY_OPTIONS,
    FeeType,
    FeeTypePayload,
} from '../../api/feeTypes';
import { TableRowsSkeleton } from '../../components/DataSkeleton';

const C = {
    forest: '#3B4A36',
    sage:   '#E3EBDB',
    ink:    '#1F261C',
    muted:  '#7C8677',
    line:   '#EDF1E8',
};

const emptyForm: FeeTypePayload = {
    name_ar: '',
    name_fr: '',
    price: 0,
    ledger_category: '',
    is_active: true,
};

export function FeeTypesPage() {
    const [feeTypes, setFeeTypes]   = useState<FeeType[]>([]);
    const [loading, setLoading]     = useState(true);
    const [error, setError]         = useState<string | null>(null);
    const [showForm, setShowForm]   = useState(false);
    const [editing, setEditing]     = useState<FeeType | null>(null);
    const [form, setForm]           = useState<FeeTypePayload>(emptyForm);
    const [saving, setSaving]       = useState(false);
    const [formError, setFormError] = useState<string | null>(null);

    useEffect(() => {
        load();
        const handleClubFeeUpdated = () => { load(); };
        window.addEventListener('club-fee-updated', handleClubFeeUpdated);
        return () => {
            window.removeEventListener('club-fee-updated', handleClubFeeUpdated);
        };
    }, []);

    async function load() {
        try {
            setLoading(true);
            setError(null);
            setFeeTypes(await getFeeTypes());
        } catch (e: unknown) {
            setError(e instanceof Error ? e.message : 'حدث خطأ');
        } finally {
            setLoading(false);
        }
    }

    function openAdd() {
        setEditing(null);
        setForm(emptyForm);
        setFormError(null);
        setShowForm(true);
    }

    function openEdit(ft: FeeType) {
        setEditing(ft);
        setForm({
            name_ar:         ft.name_ar,
            name_fr:         ft.name_fr ?? '',
            price:           parseFloat(ft.price),
            // إغفال هذا الحقل عند التعديل يعني إرسال حمولة ناقصة تُبقي البند على حاله دون علم المستخدم.
            ledger_category: ft.ledger_category ?? '',
            is_active:       ft.is_active,
        });
        setFormError(null);
        setShowForm(true);
    }

    function closeForm() {
        setShowForm(false);
        setEditing(null);
        setFormError(null);
    }

    async function handleSave() {
        if (!form.name_ar.trim()) {
            setFormError('الاسم بالعربية مطلوب');
            return;
        }
        if (form.price < 0) {
            setFormError('السعر يجب أن يكون 0 أو أكثر');
            return;
        }
        setSaving(true);
        setFormError(null);
        try {
            const payload: FeeTypePayload = {
                ...form,
                name_fr: form.name_fr?.trim() || null,
                // الفارغ يُرسل null لا '' لأن قاعدة in: ترفض النص الفارغ، وnull يعيد الاستدلال من الاسم.
                ledger_category: form.ledger_category?.trim() ? form.ledger_category : null,
            };
            if (editing) {
                const updated = await updateFeeType(editing.id, payload);
                setFeeTypes(prev => prev.map(f => f.id === updated.id ? updated : f));
            } else {
                const created = await createFeeType(payload);
                setFeeTypes(prev => [...prev, created]);
            }
            closeForm();
        } catch (e: unknown) {
            setFormError(e instanceof Error ? e.message : 'فشل الحفظ');
        } finally {
            setSaving(false);
        }
    }

    async function handleDelete(ft: FeeType) {
        if (!confirm(`هل تريد حذف "${ft.name_ar}"؟`)) return;
        try {
            await deleteFeeType(ft.id);
            setFeeTypes(prev => prev.filter(f => f.id !== ft.id));
        } catch (e: unknown) {
            alert(e instanceof Error ? e.message : 'فشل الحذف');
        }
    }

    return (
        <div className="p-6 md:p-8" dir="rtl">

            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
                <div>
                    <h1 className="text-2xl font-bold" style={{ color: C.ink }}>
                        أنواع المعاليم
                    </h1>
                    <p className="mt-1 text-sm" style={{ color: C.muted }}>
                        إدارة رسوم المدرسة والنوادي — وبند كل رسم في الدفتر النقدي
                    </p>
                </div>
                <button
                    onClick={openAdd}
                    className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-white font-medium transition hover:opacity-90"
                    style={{ backgroundColor: C.forest }}
                >
                    <Plus size={18} />
                    إضافة نوع جديد
                </button>
            </div>

            {/* Error */}
            {error && (
                <div className="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 flex items-center gap-3">
                    <AlertCircle size={20} />
                    <p>{error}</p>
                </div>
            )}

            {/* Table */}
            <div className="bg-white rounded-[22px] border overflow-hidden" style={{ borderColor: C.line }}>
                <div className="overflow-x-auto">
                    <table className="w-full text-right text-sm">
                        <thead>
                            <tr style={{ backgroundColor: '#F7F9F5' }}>
                                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>الاسم بالعربية</th>
                                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>الاسم بالفرنسية</th>
                                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>السعر (د.ت)</th>
                                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>بند الدفتر</th>
                                <th className="px-6 py-4 font-semibold" style={{ color: C.muted }}>الحالة</th>
                                <th className="px-6 py-4 font-semibold w-28" style={{ color: C.muted }}>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <TableRowsSkeleton columns={6} />
                            ) : feeTypes.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12 text-center" style={{ color: C.muted }}>
                                        لا توجد أنواع معاليم بعد
                                    </td>
                                </tr>
                            ) : feeTypes.map(ft => (
                                <tr
                                    key={ft.id}
                                    className="border-t hover:bg-[#FAFBF8] transition"
                                    style={{ borderColor: C.line }}
                                >
                                    <td className="px-6 py-4 font-medium" style={{ color: C.ink }}>
                                        {ft.name_ar}
                                    </td>
                                    <td className="px-6 py-4" style={{ color: C.muted }} dir="ltr">
                                        {ft.name_fr || '—'}
                                    </td>
                                    <td className="px-6 py-4 font-medium" style={{ color: C.forest }}>
                                        {parseFloat(ft.price).toFixed(2)}
                                    </td>
                                    <td className="px-6 py-4">
                                        {ft.ledger_category ? (
                                            <span className="inline-flex px-3 py-1 rounded-full text-xs font-medium"
                                                style={{ backgroundColor: C.sage, color: C.forest }}>
                                                {ledgerCategoryLabel(ft.ledger_category)}
                                            </span>
                                        ) : (
                                            <span className="inline-flex px-3 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-700"
                                                title="لم يُحدّد بند؛ يُستدلّ من اسم النوع عند الاستخلاص">
                                                استدلال من الاسم
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-6 py-4">
                                        {ft.is_active ? (
                                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium"
                                                style={{ backgroundColor: C.sage, color: C.forest }}>
                                                <CheckCircle size={13} /> مفعَّل
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-500">
                                                <X size={13} /> موقوف
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-6 py-4">
                                        <div className="flex items-center gap-3">
                                            <button
                                                onClick={() => openEdit(ft)}
                                                className="p-2 rounded-lg hover:bg-[#E3EBDB] transition"
                                                style={{ color: C.forest }}
                                                title="تعديل"
                                            >
                                                <Edit2 size={17} />
                                            </button>
                                            <button
                                                onClick={() => handleDelete(ft)}
                                                className="p-2 rounded-lg hover:bg-red-50 transition text-red-500"
                                                title="حذف"
                                            >
                                                <Trash2 size={17} />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Modal */}
            {showForm && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
                    <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6" dir="rtl">

                        {/* Modal Header */}
                        <div className="flex items-center justify-between mb-6">
                            <h2 className="text-lg font-bold" style={{ color: C.ink }}>
                                {editing ? 'تعديل نوع المعلوم' : 'إضافة نوع جديد'}
                            </h2>
                            <button onClick={closeForm} className="p-2 rounded-lg hover:bg-slate-100 transition text-slate-500">
                                <X size={20} />
                            </button>
                        </div>

                        {/* Form Error */}
                        {formError && (
                            <div className="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm flex items-center gap-2">
                                <AlertCircle size={16} />
                                {formError}
                            </div>
                        )}

                        {/* Fields */}
                        <div className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium mb-1.5" style={{ color: C.ink }}>
                                    الاسم بالعربية <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    value={form.name_ar}
                                    onChange={e => setForm(p => ({ ...p, name_ar: e.target.value }))}
                                    className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#3B4A36] transition"
                                    placeholder="مثال: ميدعة"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium mb-1.5" style={{ color: C.ink }}>
                                    الاسم بالفرنسية
                                </label>
                                <input
                                    type="text"
                                    dir="ltr"
                                    value={form.name_fr ?? ''}
                                    onChange={e => setForm(p => ({ ...p, name_fr: e.target.value }))}
                                    className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#3B4A36] transition text-left"
                                    placeholder="Inscription"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium mb-1.5" style={{ color: C.ink }}>
                                    السعر (د.ت) <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    dir="ltr"
                                    value={form.price}
                                    onChange={e => setForm(p => ({ ...p, price: parseFloat(e.target.value) || 0 }))}
                                    className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#3B4A36] transition text-left"
                                />
                            </div>

                            {/* بند الدفتر — يحدّد أين يظهر المبلغ في كل التقارير المالية */}
                            <div>
                                <label className="block text-sm font-medium mb-1.5" style={{ color: C.ink }}>
                                    بند الدفتر النقدي
                                </label>
                                <select
                                    value={form.ledger_category ?? ''}
                                    onChange={e => setForm(p => ({ ...p, ledger_category: e.target.value }))}
                                    className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#3B4A36] transition"
                                >
                                    <option value="">استدلال تلقائي من اسم النوع</option>
                                    {LEDGER_CATEGORY_OPTIONS.map(opt => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </select>
                                <p className="mt-1.5 text-xs" style={{ color: C.muted }}>
                                    يحدّد البند الذي يُسجّل فيه المبلغ عند الاستخلاص، ومنه تُبنى المداخيل والكشف اليومي.
                                    تركه تلقائياً يجعل النظام يستدلّ من الاسم، وما لا يُعرَف يُصنّف «مداخيل أخرى».
                                </p>
                            </div>

                            <div>
                                <label className="flex items-center gap-3 cursor-pointer w-fit">
                                    <input
                                        type="checkbox"
                                        checked={form.is_active}
                                        onChange={e => setForm(p => ({ ...p, is_active: e.target.checked }))}
                                        className="w-4 h-4 rounded border-slate-300"
                                    />
                                    <span className="text-sm font-medium" style={{ color: C.ink }}>مفعَّل</span>
                                </label>
                            </div>
                        </div>

                        {/* Modal Actions */}
                        <div className="flex items-center justify-end gap-3 mt-6 pt-4 border-t" style={{ borderColor: C.line }}>
                            <button
                                onClick={closeForm}
                                className="px-5 py-2.5 rounded-xl text-sm font-medium hover:bg-slate-100 transition"
                                style={{ color: C.muted }}
                            >
                                إلغاء
                            </button>
                            <button
                                onClick={handleSave}
                                disabled={saving}
                                className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-white text-sm font-medium transition hover:opacity-90 disabled:opacity-60"
                                style={{ backgroundColor: C.forest }}
                            >
                                <Save size={16} />
                                {saving ? 'جاري الحفظ...' : 'حفظ'}
                            </button>
                        </div>

                    </div>
                </div>
            )}
        </div>
    );
}
