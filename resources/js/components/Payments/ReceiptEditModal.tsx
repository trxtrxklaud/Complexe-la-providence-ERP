import React, { useState, useEffect } from 'react';
import { X, Check, AlertCircle, Edit3, PlusCircle, Calendar, DollarSign, FileText, Layers, Loader2 } from 'lucide-react';
import { paymentsApi } from '../../api/payments';
import { getFeeTypes, type FeeType } from '../../api/feeTypes';

interface Props {
  isOpen: boolean;
  paymentId: number | string | null;
  onClose: () => void;
  onSuccess?: (updated: any) => void;
}

interface AllocationItem {
  id: number;
  description: string;
  category: string;
  originalAmount: number;
  amount: number | string;
}

export function ReceiptEditModal({ isOpen, paymentId, onClose, onSuccess }: Props) {
  const [activeTab, setActiveTab] = useState<'correct' | 'add'>('correct');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  // بيانات الوصل الأصلي
  const [payment, setPayment] = useState<any>(null);
  const [items, setItems] = useState<AllocationItem[]>([]);
  const [totalAmount, setTotalAmount] = useState<number | string>('');
  const [reason, setReason] = useState('');
  const [leaveAsDebt, setLeaveAsDebt] = useState(false);

  // بيانات إضافة المستلزمات (البند الجديد)
  const [feeTypes, setFeeTypes] = useState<FeeType[]>([]);
  const [selectedFeeTypeId, setSelectedFeeTypeId] = useState<number | ''>('');
  const [additionAmount, setAdditionAmount] = useState<number | string>('');
  const [additionDate, setAdditionDate] = useState<string>(new Date().toISOString().split('T')[0]);
  const [additionDescription, setAdditionDescription] = useState<string>('');

  useEffect(() => {
    if (!isOpen || !paymentId) return;

    let active = true;
    setLoading(true);
    setError(null);
    setSuccessMsg(null);

    Promise.all([
      paymentsApi.show(Number(paymentId)),
      getFeeTypes().catch(() => []),
    ])
      .then(([p, fts]) => {
        if (!active) return;
        setPayment(p);

        // إعداد البنود للتصحيح
        const allocs: AllocationItem[] = (p.payment_allocations || []).map((a: any) => ({
          id: a.id,
          description: a.student_fee?.description || a.student_fee?.fee_type?.name_ar || 'بند مالي',
          category: a.student_fee?.fee_type?.ledger_category || 'other_income',
          originalAmount: Number(a.amount_allocated || 0),
          amount: Number(a.amount_allocated || 0),
        }));

        setItems(allocs);
        setTotalAmount(Number(p.amount || 0));
        setReason('');
        setLeaveAsDebt(false);

        // إعداد أنواع الرسوم لشاشة الإضافة
        setFeeTypes(fts);
        const suppliesType = fts.find((f: FeeType) => f.ledger_category === 'product_sale' || f.name_ar.includes('مستلزمات') || f.name_ar.includes('ميدعة'));
        if (suppliesType) {
          setSelectedFeeTypeId(suppliesType.id);
          setAdditionAmount(suppliesType.price ? Number(suppliesType.price) : '');
          setAdditionDescription(suppliesType.name_ar);
        }
      })
      .catch((err: any) => {
        if (active) setError(err.message || 'تعذر تحميل بيانات الوصل');
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [isOpen, paymentId]);

  if (!isOpen || !paymentId) return null;

  // الحساب المباشر لمجموع البنود
  const sumOfItems = items.reduce((acc, it) => acc + (Number(it.amount) || 0), 0);
  const isMultiItem = items.length > 1;
  const isSumValid = !isMultiItem || Math.abs(sumOfItems - Number(totalAmount)) < 0.001;

  // معالجة تغيير مبلغ البند
  const handleItemAmountChange = (index: number, val: string) => {
    const updated = [...items];
    updated[index].amount = val;
    setItems(updated);

    // إذا كان بنداً واحداً، يتطابق الإجمالي معه تلقائياً
    if (items.length === 1) {
      setTotalAmount(val);
    } else {
      // للبنود المتعددة، نحدّث الإجمالي ليعكس مجموع البنود فوراً لتسهيل العمل
      const newSum = updated.reduce((acc, it) => acc + (Number(it.amount) || 0), 0);
      setTotalAmount(Number(newSum.toFixed(2)));
    }
  };

  // معالجة تغيير نوع الرسم المضاف
  const handleFeeTypeChange = (typeId: number) => {
    setSelectedFeeTypeId(typeId);
    const ft = feeTypes.find((f) => f.id === typeId);
    if (ft) {
      if (ft.price) setAdditionAmount(Number(ft.price));
      setAdditionDescription(ft.name_ar);
    }
  };

  // تنفيذ التصحيح
  const handleSaveCorrection = async () => {
    setError(null);
    setSaving(true);
    try {
      const numTotal = Number(totalAmount);
      if (isNaN(numTotal) || numTotal <= 0) {
        throw new Error('يرجى إدخال مبلغ صحيح أكبر من صفر');
      }

      if (isMultiItem && Math.abs(sumOfItems - numTotal) > 0.001) {
        throw new Error('مجموع البنود لا يساوي المبلغ الجديد');
      }

      const payload: any = {
        amount: numTotal,
        reason: reason.trim() || undefined,
        notes: reason.trim() || undefined,
        leave_difference_as_debt: leaveAsDebt,
      };

      if (isMultiItem || items.length === 1) {
        payload.allocations = items.map((it) => ({
          id: it.id,
          amount: Number(it.amount),
          category: it.category,
        }));
      }

      const res = await paymentsApi.correct(Number(paymentId), payload);
      setSuccessMsg(res.message || 'تم تصحيح الوصل بنجاح');
      setTimeout(() => {
        onSuccess?.(res.payment);
        onClose();
      }, 1000);
    } catch (err: any) {
      setError(err.message || 'فشل تصحيح الوصل');
    } finally {
      setSaving(false);
    }
  };

  // تنفيذ إضافة المستلزمات (البند المستقل)
  const handleSaveAddition = async () => {
    setError(null);
    setSaving(true);
    try {
      const numAmount = Number(additionAmount);
      if (isNaN(numAmount) || numAmount <= 0) {
        throw new Error('يرجى إدخال مبلغ صحيح للبند المضاف');
      }

      const payload = {
        fee_type_id: selectedFeeTypeId ? Number(selectedFeeTypeId) : undefined,
        amount: numAmount,
        addition_date: additionDate,
        description: additionDescription.trim() || undefined,
      };

      const res = await paymentsApi.addItem(Number(paymentId), payload);
      setSuccessMsg(res.message || 'تمت إضافة البند بنجاح');
      setTimeout(() => {
        onSuccess?.(res);
        onClose();
      }, 1000);
    } catch (err: any) {
      setError(err.message || 'فشل إضافة البند');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs overflow-y-auto"
      onClick={(e) => e.target === e.currentTarget && onClose()}
      dir="rtl"
    >
      <div className="bg-white rounded-2xl w-full max-w-xl shadow-2xl border border-slate-100 overflow-hidden my-auto animate-in fade-in duration-200">
        {/* Header */}
        <div className="flex items-center justify-between p-4 border-b bg-slate-50/80">
          <div className="flex items-center gap-2.5">
            <div className="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-800 flex items-center justify-center font-bold">
              <Edit3 size={18} />
            </div>
            <div>
              <h2 className="font-bold text-slate-800 text-base">
                تعديل الوصل المالي #{paymentId}
              </h2>
              {payment?.student && (
                <p className="text-xs text-slate-500">
                  التلميذ: {payment.student.first_name} {payment.student.last_name} ({payment.student.student_code || '—'})
                </p>
              )}
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-200/60 transition"
          >
            <X size={18} />
          </button>
        </div>

        {/* Tab Switcher */}
        <div className="flex border-b bg-slate-100/70 p-1.5 gap-1.5">
          <button
            type="button"
            onClick={() => { setActiveTab('correct'); setError(null); }}
            className={`flex-1 flex items-center justify-center gap-2 py-2 px-3 rounded-xl text-xs font-bold transition ${
              activeTab === 'correct'
                ? 'bg-white text-emerald-800 shadow-xs'
                : 'text-slate-600 hover:text-slate-900 hover:bg-white/50'
            }`}
          >
            <DollarSign size={15} />
            تصحيح المبلغ (نفس الوصل والقيد)
          </button>
          <button
            type="button"
            onClick={() => { setActiveTab('add'); setError(null); }}
            className={`flex-1 flex items-center justify-center gap-2 py-2 px-3 rounded-xl text-xs font-bold transition ${
              activeTab === 'add'
                ? 'bg-white text-emerald-800 shadow-xs'
                : 'text-slate-600 hover:text-slate-900 hover:bg-white/50'
            }`}
          >
            <PlusCircle size={15} />
            إضافة مستلزمات (قيد مستقل جديد)
          </button>
        </div>

        {/* Body Content */}
        <div className="p-5 max-h-[75vh] overflow-y-auto">
          {loading ? (
            <div className="py-12 flex flex-col items-center justify-center gap-3 text-slate-400">
              <Loader2 className="animate-spin text-emerald-600" size={32} />
              <p className="text-sm">جارٍ تحميل بيانات الوصل...</p>
            </div>
          ) : payment?.cancelled_at ? (
            <div className="p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm flex items-center gap-3">
              <AlertCircle size={20} className="shrink-0" />
              <div>
                <p className="font-bold">هذا الوصل ملغى</p>
                <p className="text-xs mt-0.5">لا يمكن تصحيح أو إضافة بنود على وصل ملغى في النظام.</p>
              </div>
            </div>
          ) : (
            <>
              {error && (
                <div className="mb-4 p-3.5 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs flex items-center gap-2.5">
                  <AlertCircle size={16} className="shrink-0" />
                  <span className="font-semibold">{error}</span>
                </div>
              )}

              {successMsg && (
                <div className="mb-4 p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center gap-2.5">
                  <Check size={16} className="shrink-0" />
                  <span className="font-semibold">{successMsg}</span>
                </div>
              )}

              {activeTab === 'correct' ? (
                /* =================== شاشة تصحيح المبلغ =================== */
                <div className="space-y-4">
                  {/* شريط معلومات التاريخ والقيد */}
                  <div className="p-3 bg-amber-50/70 border border-amber-200/80 rounded-xl text-xs text-amber-900 space-y-1">
                    <div className="flex items-center gap-1.5 font-bold">
                      <Calendar size={14} />
                      تاريخ القبض الأصلي: {payment?.payment_date || '—'}
                    </div>
                    <p className="text-[11px] text-amber-800/90 leading-relaxed">
                      التصحيح يُعدّل قيد ذلك اليوم الأصلي في الخزينة؛ لا يُنشأ قيد ثانٍ، ويوم التعديل يكون بلا حركة.
                    </p>
                  </div>

                  {/* قائمة البنود والتخصيصات */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-2">
                      {isMultiItem ? 'تعديل بنود الوصل (مجموع البنود يجب أن يساوي المبلغ الإجمالي):' : 'مبلغ البند:'}
                    </label>

                    <div className="space-y-2">
                      {items.map((it, idx) => (
                        <div
                          key={it.id}
                          className="flex items-center justify-between gap-3 p-2.5 bg-slate-50 border rounded-xl border-slate-200 text-xs"
                        >
                          <div className="flex-1 min-w-0">
                            <p className="font-semibold text-slate-800 truncate">{it.description}</p>
                            <p className="text-[11px] text-slate-400">
                              السابق: {Number(it.originalAmount).toFixed(3)} د.ت
                            </p>
                          </div>
                          <div className="flex items-center gap-1.5 w-36">
                            <input
                              type="number"
                              step="0.100"
                              min="0"
                              value={it.amount}
                              onChange={(e) => handleItemAmountChange(idx, e.target.value)}
                              className="w-full px-2.5 py-1.5 rounded-lg border border-slate-300 text-sm font-bold text-slate-800 text-left bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition"
                              dir="ltr"
                            />
                            <span className="text-slate-500 text-[11px] shrink-0 font-medium">د.ت</span>
                          </div>
                        </div>
                      ))}
                    </div>

                    {isMultiItem && (
                      <div className="flex items-center justify-between pt-2 px-1 text-xs">
                        <span className="text-slate-500">مجموع البنود:</span>
                        <span className={`font-bold font-mono ${isSumValid ? 'text-emerald-700' : 'text-red-600'}`}>
                          {sumOfItems.toFixed(3)} د.ت
                        </span>
                      </div>
                    )}
                  </div>

                  {/* المبلغ الإجمالي للوصل */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">
                      المبلغ الإجمالي الجديد للوصل:
                    </label>
                    <div className="relative">
                      <input
                        type="number"
                        step="0.100"
                        min="0.01"
                        value={totalAmount}
                        onChange={(e) => {
                          const v = e.target.value;
                          setTotalAmount(v);
                          if (items.length === 1) {
                            handleItemAmountChange(0, v);
                          }
                        }}
                        className={`w-full px-3.5 py-2.5 rounded-xl border text-sm font-bold bg-white focus:outline-none focus:ring-2 transition text-left ${
                          isSumValid
                            ? 'border-slate-300 text-slate-900 focus:ring-emerald-500/20 focus:border-emerald-500'
                            : 'border-red-400 text-red-700 focus:ring-red-500/20 focus:border-red-500'
                        }`}
                        dir="ltr"
                        placeholder="0.000"
                      />
                      <span className="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-400">
                        د.ت
                      </span>
                    </div>
                  </div>

                  {/* خيار ترك الفرق كدين */}
                  <div className="p-3 bg-slate-50 border border-slate-200/90 rounded-xl">
                    <label className="flex items-start gap-2.5 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={leaveAsDebt}
                        onChange={(e) => setLeaveAsDebt(e.target.checked)}
                        className="mt-0.5 h-4 w-4 rounded-md border-slate-300 text-emerald-600 focus:ring-emerald-500"
                      />
                      <div>
                        <span className="text-xs font-bold text-slate-800">
                          اترك الفرق ديناً على التلميذ (leave_difference_as_debt)
                        </span>
                        <p className="text-[11px] text-slate-500 mt-0.5 leading-relaxed">
                          افتراضياً: تصحيح خطأ الصندوق لا يخلق ديناً ويبقى البند خالصاً بالمبلغ الجديد. فعّل هذا الخيار فقط إذا كان المبلغ الكامل مطلوباً وما نقص يُعد متخلداً.
                        </p>
                      </div>
                    </label>
                  </div>

                  {/* حقل سبب التصحيح */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">
                      سبب التصحيح / ملاحظات (للتدقيق الإلزامي):
                    </label>
                    <textarea
                      rows={2}
                      value={reason}
                      onChange={(e) => setReason(e.target.value)}
                      placeholder="مثال: تصحيح خطأ إدخال من القابض، الولي دفع 160 د.ت..."
                      className="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs text-slate-800 bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition"
                    />
                  </div>
                </div>
              ) : (
                /* =================== شاشة إضافة مستلزمات =================== */
                <div className="space-y-4">
                  <div className="p-3 bg-blue-50/70 border border-blue-200/80 rounded-xl text-xs text-blue-900 space-y-1">
                    <div className="flex items-center gap-1.5 font-bold">
                      <PlusCircle size={14} />
                      إضافة بند مستقل بتصنيف بيع منتجات (product_sale)
                    </div>
                    <p className="text-[11px] text-blue-800/90 leading-relaxed">
                      هذا الإجراء ينشئ حركة وقيداً جديداً مستقلاً في الخزينة بالمبلغ المضاف فقط، ولا يمس قيد الترسيم أو الوصولات السابقة.
                    </p>
                  </div>

                  {/* نوع الرسم */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">نوع البند / المستلزمات:</label>
                    <select
                      value={selectedFeeTypeId}
                      onChange={(e) => handleFeeTypeChange(Number(e.target.value))}
                      className="w-full px-3 py-2.5 rounded-xl border border-slate-300 text-xs font-medium bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition"
                    >
                      <option value="">اختر نوع البند...</option>
                      {feeTypes.map((ft) => (
                        <option key={ft.id} value={ft.id}>
                          {ft.name_ar} {ft.price ? `(${Number(ft.price).toFixed(2)} د.ت)` : ''}
                        </option>
                      ))}
                    </select>
                  </div>

                  {/* مبلغ الإضافة */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">مبلغ البند المضاف (د.ت):</label>
                    <div className="relative">
                      <input
                        type="number"
                        step="0.100"
                        min="0.01"
                        value={additionAmount}
                        onChange={(e) => setAdditionAmount(e.target.value)}
                        className="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-sm font-bold text-slate-900 bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition text-left"
                        dir="ltr"
                        placeholder="20.000"
                      />
                      <span className="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-400">
                        د.ت
                      </span>
                    </div>
                  </div>

                  {/* تاريخ الإضافة */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">
                      تاريخ الإضافة (تاريخ قيد الخزينة المستقل):
                    </label>
                    <input
                      type="date"
                      value={additionDate}
                      onChange={(e) => setAdditionDate(e.target.value)}
                      className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-xs font-semibold text-slate-800 bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition text-left"
                      dir="ltr"
                    />
                  </div>

                  {/* الوصف */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">وصف البند المضاف:</label>
                    <input
                      type="text"
                      value={additionDescription}
                      onChange={(e) => setAdditionDescription(e.target.value)}
                      placeholder="مثال: ميدعة ومستلزمات مدرسية..."
                      className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-xs text-slate-800 bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition"
                    />
                  </div>
                </div>
              )}
            </>
          )}
        </div>

        {/* Footer */}
        {!payment?.cancelled_at && !loading && (
          <div className="flex items-center justify-end gap-2.5 p-4 border-t bg-slate-50">
            <button
              type="button"
              onClick={onClose}
              disabled={saving}
              className="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-100 transition disabled:opacity-50"
            >
              إلغاء
            </button>

            {activeTab === 'correct' ? (
              <button
                type="button"
                onClick={handleSaveCorrection}
                disabled={saving || !isSumValid}
                className="flex items-center gap-1.5 px-5 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold shadow-xs hover:bg-emerald-700 transition disabled:opacity-50"
              >
                {saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
                تأكيد التصحيح
              </button>
            ) : (
              <button
                type="button"
                onClick={handleSaveAddition}
                disabled={saving || !additionAmount || Number(additionAmount) <= 0}
                className="flex items-center gap-1.5 px-5 py-2 rounded-xl bg-blue-600 text-white text-xs font-bold shadow-xs hover:bg-blue-700 transition disabled:opacity-50"
              >
                {saving ? <Loader2 size={14} className="animate-spin" /> : <PlusCircle size={14} />}
                إضافة البند المستقل
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

export default ReceiptEditModal;
