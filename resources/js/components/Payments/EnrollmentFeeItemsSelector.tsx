import React, { useEffect, useState } from 'react';
import { Shirt, Monitor, Files, Receipt, CheckSquare, Square, RefreshCw, Sparkles, Tag } from 'lucide-react';
import { getFeeTypes, type FeeType } from '../../api/feeTypes';

export interface FeeItemConfig {
  fee_type_id: number;
  name: string;
  name_fr?: string | null;
  category: string;
  default_price: number;
  price: number;
  selected: boolean;
}

interface Props {
  onTotalChange?: (total: number, items: Array<{ fee_type_id: number; amount: number; description: string }>) => void;
  initialSelectedCategory?: string[];
}

export function EnrollmentFeeItemsSelector({ onTotalChange }: Props) {
  const [items, setItems] = useState<FeeItemConfig[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function load() {
      try {
        setLoading(true);
        const types = await getFeeTypes();
        const activeTypes = types.filter((t) => t.is_active);

        // حصر معاليم الترسيم واللوازم في البنود الـ 4 الأساسية فقط وضمان ظهورها جميعاً بدون تكرار
        const enrollmentItems: FeeItemConfig[] = [];

        // 1. معلوم الترسيم (Frais d'inscription)
        const regType = activeTypes.find((t) => {
          const ar = t.name_ar.trim();
          return t.ledger_category === 'registration_fee' || ar === 'معلوم الترسيم' || ar.includes('ترسيم') || ar.includes('تسجيل');
        });
        const regPrice = regType ? (parseFloat(regType.price) || 70) : 70;
        enrollmentItems.push({
          fee_type_id: regType ? regType.id : 8,
          name: regType ? regType.name_ar : 'معلوم الترسيم',
          name_fr: regType?.name_fr || 'Frais d\'inscription',
          category: 'registration_fee',
          default_price: regPrice,
          price: regPrice,
          selected: true, // محدد افتراضياً
        });

        // 2. ميدعة (Tablier / Blouse)
        const blouseType = activeTypes.find((t) => {
          const ar = t.name_ar.trim();
          return ar.includes('ميدعة') || ar.includes('طبلية');
        });
        const blousePrice = blouseType ? (parseFloat(blouseType.price) || 30) : 30;
        enrollmentItems.push({
          fee_type_id: blouseType ? blouseType.id : 2,
          name: blouseType ? blouseType.name_ar : 'ميدعة',
          name_fr: blouseType?.name_fr || 'Tablier / Blouse',
          category: 'product_sale',
          default_price: blousePrice,
          price: blousePrice,
          selected: false,
        });

        // 3. منظومة الحياة المدرسية (ERP vie scolaire)
        const vieType = activeTypes.find((t) => {
          const n = (t.name_ar + ' ' + (t.name_fr || '')).toLowerCase();
          return n.includes('vie scolaire') || n.includes('erp') || n.includes('حياة مدرسية');
        });
        const viePrice = vieType ? (parseFloat(vieType.price) || 20) : 20;
        enrollmentItems.push({
          fee_type_id: vieType ? vieType.id : 4,
          name: vieType ? vieType.name_ar : 'ERP vie scolaire',
          name_fr: vieType?.name_fr || 'Vie Scolaire',
          category: 'other_income',
          default_price: viePrice,
          price: viePrice,
          selected: false,
        });

        // 4. رزمة أوراق (Ram de papier)
        const paperType = activeTypes.find((t) => {
          const n = (t.name_ar + ' ' + (t.name_fr || '')).toLowerCase();
          return n.includes('ورق') || n.includes('papier');
        });
        const paperPrice = paperType ? (parseFloat(paperType.price) || 15) : 15;
        enrollmentItems.push({
          fee_type_id: paperType ? paperType.id : 10,
          name: paperType ? paperType.name_ar : 'رزمة أوراق',
          name_fr: paperType?.name_fr || 'Ram de papier',
          category: 'product_sale',
          default_price: paperPrice,
          price: paperPrice,
          selected: false,
        });

        setItems(enrollmentItems);

        // إرسال المجموع الأولي
        notifyParent(enrollmentItems);
      } catch (err) {
        console.error('Failed to load fee types for enrollment', err);
      } finally {
        setLoading(false);
      }
    }

    load();
  }, []);

  const notifyParent = (currentItems: FeeItemConfig[]) => {
    const selected = currentItems.filter((i) => i.selected && i.price > 0);
    const total = selected.reduce((sum, i) => sum + (Number(i.price) || 0), 0);
    const formatted = selected.map((i) => ({
      fee_type_id: i.fee_type_id,
      amount: Number(i.price) || 0,
      description: i.name,
    }));
    if (onTotalChange) {
      onTotalChange(Math.round(total * 100) / 100, formatted);
    }
  };

  const toggleSelect = (id: number) => {
    const updated = items.map((item) => {
      if (item.fee_type_id === id) {
        return { ...item, selected: !item.selected };
      }
      return item;
    });
    setItems(updated);
    notifyParent(updated);
  };

  const updatePrice = (id: number, newPrice: number) => {
    const updated = items.map((item) => {
      if (item.fee_type_id === id) {
        return { ...item, price: isNaN(newPrice) ? 0 : Math.max(0, newPrice) };
      }
      return item;
    });
    setItems(updated);
    notifyParent(updated);
  };

  const resetToDefault = (id: number) => {
    const updated = items.map((item) => {
      if (item.fee_type_id === id) {
        return { ...item, price: item.default_price };
      }
      return item;
    });
    setItems(updated);
    notifyParent(updated);
  };

  const getItemIcon = (nameAr: string, nameFr?: string | null) => {
    const str = (nameAr + ' ' + (nameFr || '')).toLowerCase();
    if (str.includes('ميدعة') || str.includes('tablier') || str.includes('blouse')) {
      return <Shirt className="w-5 h-5 text-indigo-600" />;
    }
    if (str.includes('vie scolaire') || str.includes('erp') || str.includes('منظومة')) {
      return <Monitor className="w-5 h-5 text-emerald-600" />;
    }
    if (str.includes('ورق') || str.includes('papier')) {
      return <Files className="w-5 h-5 text-amber-600" />;
    }
    return <Receipt className="w-5 h-5 text-blue-600" />;
  };

  if (loading) {
    return (
      <div className="p-6 bg-slate-50 rounded-2xl border border-slate-200 text-center text-slate-500 text-xs flex items-center justify-center gap-2">
        <RefreshCw className="w-4 h-4 animate-spin text-slate-400" />
        جاري تحميل معاليم الترسيم واللوازم...
      </div>
    );
  }

  const selectedItems = items.filter((i) => i.selected);
  const totalAmount = selectedItems.reduce((acc, curr) => acc + (Number(curr.price) || 0), 0);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-sm font-bold text-slate-800 flex items-center gap-2">
            <Tag size={16} className="text-emerald-700" />
            تفصيل معاليم الترسيم واللوازم المدرسية:
          </h3>
          <p className="text-xs text-slate-500 mt-0.5">
            حدّد المعاليم المطلوبة مع إمكانية تعديل السعر مباشرة لهذا التلميذ
          </p>
        </div>
        <span className="text-[11px] font-bold text-emerald-800 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200">
          {selectedItems.length} بند محدد
        </span>
      </div>

      {/* بطاقات المعاليم */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        {items.map((item, idx) => {
          const isSelected = item.selected;
          return (
            <div
              key={item.fee_type_id}
              className={`relative rounded-2xl p-3.5 border transition-all duration-200 ${
                isSelected
                  ? 'bg-white border-emerald-600/40 shadow-sm ring-1 ring-emerald-600/20'
                  : 'bg-slate-50/70 border-slate-200 opacity-75 hover:opacity-100 hover:bg-white'
              }`}
            >
              {/* مدخلات مخفية للـ FormData لترسل تلقائياً عند حفظ النموذج */}
              {isSelected && (
                <>
                  <input type="hidden" name={`fee_items[${idx}][fee_type_id]`} value={item.fee_type_id} />
                  <input type="hidden" name={`fee_items[${idx}][amount]`} value={item.price} />
                  <input type="hidden" name={`fee_items[${idx}][description]`} value={item.name} />
                </>
              )}

              <div className="flex items-start gap-3">
                {/* زر الاختيار */}
                <button
                  type="button"
                  onClick={() => toggleSelect(item.fee_type_id)}
                  className="mt-0.5 text-slate-600 hover:text-emerald-700 transition"
                  title={isSelected ? 'إلغاء التحديد' : 'تحديد هذا المعلوم'}
                >
                  {isSelected ? (
                    <CheckSquare className="w-5 h-5 text-emerald-700 fill-emerald-100" />
                  ) : (
                    <Square className="w-5 h-5 text-slate-300 hover:text-slate-400" />
                  )}
                </button>

                {/* أيقونة المعلوم */}
                <div className="w-9 h-9 rounded-xl bg-slate-100/80 flex items-center justify-center shrink-0">
                  {getItemIcon(item.name, item.name_fr)}
                </div>

                {/* تفاصيل المعلوم والسعر القابل للتعديل */}
                <div className="flex-1 min-w-0">
                  <div className="flex items-center justify-between gap-2">
                    <span className={`text-xs font-bold truncate ${isSelected ? 'text-slate-800' : 'text-slate-500'}`}>
                      {item.name}
                    </span>
                    {item.name_fr && (
                      <span className="text-[10px] text-slate-400 font-mono truncate" dir="ltr">
                        {item.name_fr}
                      </span>
                    )}
                  </div>

                  {/* حقل السعر القابل للتعديل مباشرة */}
                  <div className="mt-2 flex items-center gap-2">
                    <label className="text-[11px] font-semibold text-slate-500 shrink-0">
                      السعر (د.ت):
                    </label>
                    <input
                      type="number"
                      step="0.5"
                      min="0"
                      disabled={!isSelected}
                      value={item.price}
                      onChange={(e) => updatePrice(item.fee_type_id, parseFloat(e.target.value))}
                      className="w-24 px-2 py-1 text-xs font-bold text-slate-800 bg-white border border-slate-300 rounded-lg text-right focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-none disabled:bg-slate-100 disabled:text-slate-400"
                    />
                    {item.price !== item.default_price && (
                      <button
                        type="button"
                        onClick={() => resetToDefault(item.fee_type_id)}
                        className="text-[10px] text-amber-700 bg-amber-50 hover:bg-amber-100 px-1.5 py-0.5 rounded border border-amber-200 transition"
                        title={`إعادة للسعر الافتراضي (${item.default_price} د.ت)`}
                      >
                        إعادة ({item.default_price})
                      </button>
                    )}
                  </div>
                </div>
              </div>
            </div>
          );
        })}
      </div>

      {/* شريط الإجمالي المحسوب */}
      <div className="flex flex-wrap items-center justify-between p-3.5 bg-gradient-to-r from-emerald-50 to-slate-50 border border-emerald-200/80 rounded-2xl">
        <div className="text-xs text-emerald-950 font-medium">
          المجموع المحسوب للمعاليم واللوازم المختارة:
        </div>
        <div className="text-base font-black text-emerald-800" dir="ltr">
          {totalAmount.toFixed(2)} <span className="text-xs font-bold">د.ت</span>
        </div>
      </div>
    </div>
  );
}
