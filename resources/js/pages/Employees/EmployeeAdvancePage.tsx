import React, { useState, useEffect, useMemo } from 'react';
import {
  Plus,
  DollarSign,
  Calendar,
  AlertCircle,
  CheckCircle2,
  Clock,
  ChevronDown,
  ChevronUp,
  Banknote,
  Ban,
  Filter,
  Search,
  RefreshCw,
  Eye,
  CreditCard,
  UserCheck,
} from 'lucide-react';
import {
  fetchEmployeeAdvances,
  fetchAdvanceRepayments,
  settleAdvanceRepayment,
  cancelEmployeeAdvance,
  cancelSingleRepayment,
  getEmployees,
  type EmployeeAdvance,
  type AdvanceRepayment,
  type Employee,
} from '../../api/employeeAdvances';
import { AdvanceModal } from '../../components/Employee/AdvanceModal';
import { SettleAdvanceModal, type SettleFormValues } from './SettleAdvanceModal';
import { CancelReasonModal } from '../../components/CancelReasonModal';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  emerald: '#10B981',
  red: '#EF4444',
  amber: '#F59E0B',
  blue: '#3B82F6',
  dangerBtn: '#DC2626',
};

export function EmployeeAdvancePage() {
  const [advances, setAdvances] = useState<EmployeeAdvance[]>([]);
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  // Filters
  const [selectedEmployeeId, setSelectedEmployeeId] = useState<string>('');
  const [selectedType, setSelectedType] = useState<string>('all');
  const [selectedStatus, setSelectedStatus] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');

  // Modals state
  const [showAddModal, setShowAddModal] = useState(false);
  const [settleTarget, setSettleTarget] = useState<EmployeeAdvance | null>(null);
  const [settling, setSettling] = useState(false);

  // Cancel state
  const [cancelTarget, setCancelTarget] = useState<{
    type: 'advance' | 'repayment';
    item: EmployeeAdvance | AdvanceRepayment;
  } | null>(null);
  const [cancelling, setCancelling] = useState(false);

  // Repayments expand state
  const [expandedAdvanceId, setExpandedAdvanceId] = useState<number | null>(null);
  const [repaymentsMap, setRepaymentsMap] = useState<Record<number, AdvanceRepayment[]>>({});
  const [loadingRepayments, setLoadingRepayments] = useState<Record<number, boolean>>({});

  useEffect(() => {
    loadData();
  }, []);

  const loadData = async () => {
    setLoading(true);
    setError(null);
    try {
      const [advRes, empRes] = await Promise.all([
        fetchEmployeeAdvances(),
        getEmployees(),
      ]);
      setAdvances(advRes);
      setEmployees(empRes);
    } catch (err: any) {
      setError(err?.message || 'فشل تحميل بيانات السلف');
    } finally {
      setLoading(false);
    }
  };

  const remainingOf = (a: EmployeeAdvance): number => {
    if (a.cancelled_at) return 0;
    const total = parseFloat(String(a.amount)) || 0;
    const settled = parseFloat(String(a.settled_amount)) || 0;
    return Math.max(0, Math.round((total - settled) * 100) / 100);
  };

  const toggleRepayments = async (advanceId: number) => {
    if (expandedAdvanceId === advanceId) {
      setExpandedAdvanceId(null);
      return;
    }
    setExpandedAdvanceId(advanceId);
    if (!repaymentsMap[advanceId]) {
      setLoadingRepayments((prev) => ({ ...prev, [advanceId]: true }));
      try {
        const reps = await fetchAdvanceRepayments(advanceId);
        setRepaymentsMap((prev) => ({ ...prev, [advanceId]: reps }));
      } catch (err: any) {
        console.error('Failed to load repayments:', err);
      } finally {
        setLoadingRepayments((prev) => ({ ...prev, [advanceId]: false }));
      }
    }
  };

  const handleSettleSubmit = async (values: SettleFormValues) => {
    if (!settleTarget) return;
    setSettling(true);
    try {
      await settleAdvanceRepayment(settleTarget.id, {
        amount: values.amount,
        method: values.method,
        repaid_at: values.repaid_at,
        notes: values.notes,
      });
      setSuccessMsg(`تم تسجيل خلاص دفعة بقيمة ${values.amount.toFixed(2)} د.ت بنجاح`);
      setSettleTarget(null);
      // Reload repayments if currently expanded
      if (expandedAdvanceId === settleTarget.id) {
        const reps = await fetchAdvanceRepayments(settleTarget.id);
        setRepaymentsMap((prev) => ({ ...prev, [settleTarget.id]: reps }));
      }
      await loadData();
    } catch (err: any) {
      setError(err?.message || 'فشل سداد الدفعة');
    } finally {
      setSettling(false);
    }
  };

  const handleCancelConfirm = async (reason: string) => {
    if (!cancelTarget) return;
    setCancelling(true);
    try {
      if (cancelTarget.type === 'advance') {
        await cancelEmployeeAdvance(cancelTarget.item.id, reason);
        setSuccessMsg('تم إلغاء السلفة وتوثيق السبب وعكس أثرها في الخزينة بنجاح');
      } else {
        await cancelSingleRepayment(cancelTarget.item.id, reason);
        setSuccessMsg('تم إلغاء الدفعة وإعادة احتساب المتبقي بنجاح');
        if (expandedAdvanceId) {
          const reps = await fetchAdvanceRepayments(expandedAdvanceId);
          setRepaymentsMap((prev) => ({ ...prev, [expandedAdvanceId]: reps }));
        }
      }
      setCancelTarget(null);
      await loadData();
    } catch (err: any) {
      setError(err?.message || 'فشل الإلغاء');
    } finally {
      setCancelling(false);
    }
  };

  // Filtered Advances
  const filteredAdvances = useMemo(() => {
    return advances.filter((a) => {
      if (selectedEmployeeId && String(a.employee_id) !== selectedEmployeeId) {
        return false;
      }
      if (selectedType !== 'all' && a.type !== selectedType) {
        return false;
      }
      const remaining = remainingOf(a);
      if (selectedStatus === 'settled') {
        if (a.cancelled_at || remaining > 0) return false;
      } else if (selectedStatus === 'outstanding') {
        if (a.cancelled_at || remaining <= 0) return false;
      } else if (selectedStatus === 'cancelled') {
        if (!a.cancelled_at) return false;
      }

      if (searchQuery.trim()) {
        const q = searchQuery.trim().toLowerCase();
        const empName = `${a.employee?.first_name || ''} ${a.employee?.last_name || ''}`.toLowerCase();
        const reason = (a.reason || a.purpose || '').toLowerCase();
        const notes = (a.notes || '').toLowerCase();
        if (!empName.includes(q) && !reason.includes(q) && !notes.includes(q)) {
          return false;
        }
      }
      return true;
    });
  }, [advances, selectedEmployeeId, selectedType, selectedStatus, searchQuery]);

  // Calculations for Stats Cards
  const stats = useMemo(() => {
    const active = advances.filter((a) => !a.cancelled_at);
    const loans = active.filter((a) => a.type === 'loan');
    const advs = active.filter((a) => a.type === 'advance');

    const totalLoans = loans.reduce((sum, a) => sum + (parseFloat(String(a.amount)) || 0), 0);
    const totalAdvances = advs.reduce((sum, a) => sum + (parseFloat(String(a.amount)) || 0), 0);

    const totalOutstanding = active.reduce((sum, a) => sum + remainingOf(a), 0);
    const totalSettled = active.reduce((sum, a) => sum + (parseFloat(String(a.settled_amount)) || 0), 0);

    return { totalLoans, totalAdvances, totalOutstanding, totalSettled };
  }, [advances]);

  return (
    <div className="p-4 sm:p-8 space-y-6 bg-slate-50/50 min-h-screen">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="flex items-center gap-3.5">
          <div className="w-12 h-12 rounded-2xl flex items-center justify-center shadow-xs" style={{ backgroundColor: C.sage }}>
            <DollarSign size={24} style={{ color: C.forest }} />
          </div>
          <div>
            <h1 className="text-xl sm:text-2xl font-bold" style={{ color: C.ink }}>
              سلفات وتسبقات الموظفين
            </h1>
            <p className="text-xs sm:text-sm mt-0.5" style={{ color: C.muted }}>
              إدارة السلف والتسبقات وجدولة الأقساط ومتابعة الاسترداد
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            onClick={loadData}
            title="تحديث البيانات"
            className="p-2.5 rounded-xl border bg-white text-slate-600 hover:bg-slate-100 transition shadow-xs"
            style={{ borderColor: C.line }}
          >
            <RefreshCw size={18} className={loading ? 'animate-spin' : ''} />
          </button>
          <button
            onClick={() => setShowAddModal(true)}
            className="px-5 py-2.5 rounded-xl text-sm font-bold text-white shadow-md hover:shadow-lg transition flex items-center gap-2"
            style={{ backgroundColor: C.forest }}
          >
            <Plus size={18} />
            <span>إضافة سلفة/تسبقة جديدة</span>
          </button>
        </div>
      </div>

      {/* Messages */}
      {error && (
        <div className="p-4 rounded-2xl bg-red-50 text-red-700 text-sm flex items-center justify-between border border-red-200">
          <div className="flex items-center gap-2">
            <AlertCircle size={18} />
            <span>{error}</span>
          </div>
          <button onClick={() => setError(null)} className="text-xs font-bold underline">إغلاق</button>
        </div>
      )}

      {successMsg && (
        <div className="p-4 rounded-2xl bg-emerald-50 text-emerald-800 text-sm flex items-center justify-between border border-emerald-200">
          <div className="flex items-center gap-2">
            <CheckCircle2 size={18} />
            <span>{successMsg}</span>
          </div>
          <button onClick={() => setSuccessMsg(null)} className="text-xs font-bold underline">إغلاق</button>
        </div>
      )}

      {/* Stats Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
        {/* إجمالي السلفات */}
        <div className="bg-white rounded-2xl border shadow-xs p-5" style={{ borderColor: C.line }}>
          <div className="flex items-center justify-between mb-2">
            <span className="text-xs font-semibold" style={{ color: C.muted }}>إجمالي السلفات (Loans)</span>
            <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ backgroundColor: '#EFF6FF' }}>
              <CreditCard size={20} className="text-blue-600" />
            </div>
          </div>
          <p className="text-2xl font-bold font-mono" style={{ color: C.ink }}>
            {stats.totalLoans.toFixed(2)} <span className="text-sm font-sans font-normal text-slate-500">د.ت</span>
          </p>
          <p className="text-xs mt-1 text-slate-400">تقسّط على أشهر بالمهل</p>
        </div>

        {/* إجمالي التسبقات */}
        <div className="bg-white rounded-2xl border shadow-xs p-5" style={{ borderColor: C.line }}>
          <div className="flex items-center justify-between mb-2">
            <span className="text-xs font-semibold" style={{ color: C.muted }}>إجمالي التسبقات (Advances)</span>
            <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ backgroundColor: '#FEF3C7' }}>
              <Clock size={20} className="text-amber-600" />
            </div>
          </div>
          <p className="text-2xl font-bold font-mono" style={{ color: C.ink }}>
            {stats.totalAdvances.toFixed(2)} <span className="text-sm font-sans font-normal text-slate-500">د.ت</span>
          </p>
          <p className="text-xs mt-1 text-slate-400">تُخصم من راتب الشهر نفسه</p>
        </div>

        {/* قيد الاسترداد */}
        <div className="bg-white rounded-2xl border shadow-xs p-5" style={{ borderColor: C.line }}>
          <div className="flex items-center justify-between mb-2">
            <span className="text-xs font-semibold" style={{ color: C.muted }}>قيد الاسترداد (المتبقي)</span>
            <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ backgroundColor: '#FEE2E2' }}>
              <AlertCircle size={20} className="text-rose-600" />
            </div>
          </div>
          <p className="text-2xl font-bold font-mono text-rose-600">
            {stats.totalOutstanding.toFixed(2)} <span className="text-sm font-sans font-normal text-slate-500">د.ت</span>
          </p>
          <p className="text-xs mt-1 text-slate-400">مبالغ مستحقة بذمة الموظفين</p>
        </div>

        {/* تم استرجاعه */}
        <div className="bg-white rounded-2xl border shadow-xs p-5" style={{ borderColor: C.line }}>
          <div className="flex items-center justify-between mb-2">
            <span className="text-xs font-semibold" style={{ color: C.muted }}>المسترجع / المسدّد</span>
            <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ backgroundColor: '#ECFDF5' }}>
              <CheckCircle2 size={20} className="text-emerald-600" />
            </div>
          </div>
          <p className="text-2xl font-bold font-mono text-emerald-700">
            {stats.totalSettled.toFixed(2)} <span className="text-sm font-sans font-normal text-slate-500">د.ت</span>
          </p>
          <p className="text-xs mt-1 text-slate-400">تم تحصيله نقداً أو خصماً</p>
        </div>
      </div>

      {/* Filters Bar */}
      <div className="bg-white rounded-2xl border shadow-xs p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3" style={{ borderColor: C.line }}>
        {/* بحث بالاسم أو الغرض */}
        <div className="relative">
          <Search size={16} className="absolute right-3 top-3 text-slate-400" />
          <input
            type="text"
            placeholder="بحث بالاسم، السبب، الملاحظة..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pr-9 pl-3 py-2 border rounded-xl text-xs bg-slate-50/50 focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-slate-300"
            style={{ borderColor: C.line }}
          />
        </div>

        {/* تصفية بالموظف */}
        <div>
          <select
            value={selectedEmployeeId}
            onChange={(e) => setSelectedEmployeeId(e.target.value)}
            className="w-full px-3 py-2 border rounded-xl text-xs bg-slate-50/50 focus:bg-white focus:outline-hidden"
            style={{ borderColor: C.line }}
          >
            <option value="">كل الموظفين ({employees.length})</option>
            {employees.map((emp) => (
              <option key={emp.id} value={emp.id}>
                {emp.first_name} {emp.last_name}
              </option>
            ))}
          </select>
        </div>

        {/* تصفية بالنوع */}
        <div>
          <select
            value={selectedType}
            onChange={(e) => setSelectedType(e.target.value)}
            className="w-full px-3 py-2 border rounded-xl text-xs bg-slate-50/50 focus:bg-white focus:outline-hidden"
            style={{ borderColor: C.line }}
          >
            <option value="all">كل الأنواع (سلف + تسبقات)</option>
            <option value="loan">سلفات فقط (Loans)</option>
            <option value="advance">تسبقات فقط (Advances)</option>
          </select>
        </div>

        {/* تصفية بالحالة */}
        <div>
          <select
            value={selectedStatus}
            onChange={(e) => setSelectedStatus(e.target.value)}
            className="w-full px-3 py-2 border rounded-xl text-xs bg-slate-50/50 focus:bg-white focus:outline-hidden"
            style={{ borderColor: C.line }}
          >
            <option value="all">كل الحالات</option>
            <option value="outstanding">قيد الاسترداد (متبقي)</option>
            <option value="settled">مسددة بالكامل</option>
            <option value="cancelled">ملغاة</option>
          </select>
        </div>
      </div>

      {/* Main Table */}
      <div className="bg-white rounded-2xl border shadow-xs overflow-hidden" style={{ borderColor: C.line }}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm text-right">
            <thead>
              <tr className="border-b text-xs font-bold" style={{ backgroundColor: C.sage, color: C.forest }}>
                <th className="px-4 py-3.5">#</th>
                <th className="px-4 py-3.5">الموظف</th>
                <th className="px-4 py-3.5">النوع</th>
                <th className="px-4 py-3.5">المبلغ</th>
                <th className="px-4 py-3.5">المسدّد</th>
                <th className="px-4 py-3.5">المتبقي</th>
                <th className="px-4 py-3.5">تاريخ المنح</th>
                <th className="px-4 py-3.5">خطة الاسترجاع</th>
                <th className="px-4 py-3.5">الحالة</th>
                <th className="px-4 py-3.5 text-center">الإجراءات</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading ? (
                <tr>
                  <td colSpan={10} className="py-12 text-center text-slate-400">
                    <RefreshCw className="inline animate-spin mb-2" size={24} />
                    <p className="text-xs">جارٍ تحميل البيانات...</p>
                  </td>
                </tr>
              ) : filteredAdvances.length === 0 ? (
                <tr>
                  <td colSpan={10} className="py-12 text-center text-slate-400">
                    <AlertCircle className="inline mb-2 text-slate-300" size={28} />
                    <p className="text-sm font-semibold">لا توجد سلفات أو تسبقات مطابقة للبحث</p>
                  </td>
                </tr>
              ) : (
                filteredAdvances.map((adv) => {
                  const rem = remainingOf(adv);
                  const isExpanded = expandedAdvanceId === adv.id;
                  const isCancelled = !!adv.cancelled_at;

                  return (
                    <React.Fragment key={adv.id}>
                      <tr
                        className={`hover:bg-slate-50/80 transition ${
                          isCancelled ? 'opacity-60 bg-slate-50/40' : ''
                        }`}
                      >
                        <td className="px-4 py-3.5 font-mono text-xs text-slate-500 font-bold">
                          {adv.id}
                        </td>

                        <td className="px-4 py-3.5">
                          <div className="font-bold" style={{ color: C.ink }}>
                            {adv.employee?.first_name} {adv.employee?.last_name}
                          </div>
                          {adv.employee?.job_title && (
                            <span className="text-[11px] text-slate-400">
                              {adv.employee.job_title}
                            </span>
                          )}
                        </td>

                        <td className="px-4 py-3.5">
                          <span
                            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold ${
                              adv.type === 'loan'
                                ? 'bg-blue-50 text-blue-700 border border-blue-200'
                                : 'bg-amber-50 text-amber-700 border border-amber-200'
                            }`}
                          >
                            {adv.type === 'loan' ? 'سلفة' : 'تسبقة'}
                          </span>
                        </td>

                        <td className="px-4 py-3.5 font-mono font-bold" style={{ color: C.ink }}>
                          {parseFloat(String(adv.amount)).toFixed(2)} د.ت
                        </td>

                        <td className="px-4 py-3.5 font-mono font-semibold" style={{ color: C.emerald }}>
                          {parseFloat(String(adv.settled_amount)).toFixed(2)} د.ت
                        </td>

                        <td className="px-4 py-3.5 font-mono font-bold">
                          <span style={{ color: rem > 0 ? C.red : C.emerald }}>
                            {rem.toFixed(2)} د.ت
                          </span>
                        </td>

                        <td className="px-4 py-3.5 text-xs font-mono text-slate-500">
                          {adv.advance_date ? new Date(adv.advance_date).toLocaleDateString('ar-TN') : '—'}
                        </td>

                        <td className="px-4 py-3.5 text-xs">
                          {adv.type === 'loan' ? (
                            <div>
                              <span className="font-bold text-slate-700">
                                {adv.installment_count && adv.installment_count > 1
                                  ? `${adv.installment_count} أقساط`
                                  : 'قسط شهري'}
                              </span>
                              {adv.repayment_method === 'cash' ? (
                                <span className="block text-[11px] text-slate-400">نقداً بالخزينة</span>
                              ) : (
                                <span className="block text-[11px] text-slate-400">خصم من الراتب</span>
                              )}
                              {adv.due_date && (
                                <span className="block text-[10px] text-slate-400">
                                  استحقاق: {new Date(adv.due_date).toLocaleDateString('ar-TN')}
                                </span>
                              )}
                            </div>
                          ) : (
                            <span className="text-slate-500">تُخصم من راتب الشهر</span>
                          )}
                        </td>

                        <td className="px-4 py-3.5">
                          {isCancelled ? (
                            <span className="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">
                              ملغاة
                            </span>
                          ) : rem <= 0 ? (
                            <span className="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                              مسددة
                            </span>
                          ) : (
                            <span className="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                              قيد السداد
                            </span>
                          )}
                        </td>

                        <td className="px-4 py-3.5 text-center">
                          <div className="flex items-center justify-center gap-1.5">
                            {/* زر عرض سجل الأقساط */}
                            {adv.type === 'loan' && (
                              <button
                                onClick={() => toggleRepayments(adv.id)}
                                className={`p-1.5 rounded-lg border transition ${
                                  isExpanded
                                    ? 'bg-slate-200 text-slate-800'
                                    : 'bg-white hover:bg-slate-100 text-slate-600'
                                }`}
                                style={{ borderColor: C.line }}
                                title="سجل الأقساط والدفعات"
                              >
                                {isExpanded ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
                              </button>
                            )}

                            {/* زر خلاص قسط */}
                            {!isCancelled && adv.type === 'loan' && rem > 0 && (
                              <button
                                onClick={() => setSettleTarget(adv)}
                                className="px-2.5 py-1 rounded-lg text-xs font-bold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 transition flex items-center gap-1"
                                title="خلاص قسط"
                              >
                                <Banknote size={14} />
                                <span>خلاص</span>
                              </button>
                            )}

                            {/* زر إلغاء السلفة الموثق */}
                            {!isCancelled && (
                              <button
                                onClick={() =>
                                  setCancelTarget({
                                    type: 'advance',
                                    item: adv,
                                  })
                                }
                                className="p-1.5 rounded-lg text-rose-600 hover:bg-rose-50 border border-transparent hover:border-rose-200 transition"
                                title="إلغاء موثق"
                              >
                                <Ban size={16} />
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>

                      {/* سجل الأقساط والردّيات الموسع */}
                      {isExpanded && (
                        <tr className="bg-slate-50/70 border-b" style={{ borderColor: C.line }}>
                          <td colSpan={10} className="p-4 sm:p-5">
                            <div className="rounded-2xl bg-white border p-4 shadow-xs space-y-3" style={{ borderColor: C.line }}>
                              <div className="flex items-center justify-between border-b pb-2" style={{ borderColor: C.line }}>
                                <div className="flex items-center gap-2">
                                  <Calendar size={16} style={{ color: C.forest }} />
                                  <span className="text-xs font-bold" style={{ color: C.ink }}>
                                    سجل دفعات وأقساط السلفة رقم #{adv.id}
                                  </span>
                                </div>
                                <span className="text-xs font-mono font-bold" style={{ color: C.muted }}>
                                  المتبقي: {rem.toFixed(2)} د.ت من أصل {parseFloat(String(adv.amount)).toFixed(2)} د.ت
                                </span>
                              </div>

                              {loadingRepayments[adv.id] ? (
                                <p className="text-xs text-slate-400 py-3 text-center">جارٍ تحميل سجل الأقساط...</p>
                              ) : !repaymentsMap[adv.id] || repaymentsMap[adv.id].length === 0 ? (
                                <p className="text-xs text-slate-400 py-3 text-center">
                                  لم تُسجّل أي دفعات أو أقساط لهذه السلفة بعد.
                                </p>
                              ) : (
                                <div className="overflow-x-auto">
                                  <table className="w-full text-xs text-right">
                                    <thead>
                                      <tr className="text-slate-400 border-b" style={{ borderColor: C.line }}>
                                        <th className="py-2 px-3">#</th>
                                        <th className="py-2 px-3">تاريخ الدفعة</th>
                                        <th className="py-2 px-3">المبلغ</th>
                                        <th className="py-2 px-3">طريقة الخلاص</th>
                                        <th className="py-2 px-3">الخزينة المركزية</th>
                                        <th className="py-2 px-3">ملاحظات</th>
                                        <th className="py-2 px-3 text-center">إجراءات</th>
                                      </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                      {repaymentsMap[adv.id].map((rep) => {
                                        const repCancelled = !!rep.cancelled_at;
                                        return (
                                          <tr
                                            key={rep.id}
                                            className={repCancelled ? 'opacity-50 line-through bg-rose-50/20' : ''}
                                          >
                                            <td className="py-2 px-3 font-mono font-bold text-slate-500">
                                              {rep.id}
                                            </td>
                                            <td className="py-2 px-3 font-mono">
                                              {rep.repaid_at ? new Date(rep.repaid_at).toLocaleDateString('ar-TN') : '—'}
                                            </td>
                                            <td className="py-2 px-3 font-mono font-bold text-emerald-700">
                                              {parseFloat(String(rep.amount)).toFixed(2)} د.ت
                                            </td>
                                            <td className="py-2 px-3">
                                              {rep.method === 'cash' ? 'نقداً' : 'خصم من الراتب'}
                                            </td>
                                            <td className="py-2 px-3 text-slate-400">
                                              {rep.method === 'cash' ? 'دخلت الصندوق' : 'لا تمر بالصندوق'}
                                            </td>
                                            <td className="py-2 px-3 text-slate-500">
                                              {repCancelled ? rep.cancellation_reason : rep.notes || '—'}
                                            </td>
                                            <td className="py-2 px-3 text-center">
                                              {!repCancelled && !rep.salary_id && (
                                                <button
                                                  onClick={() =>
                                                    setCancelTarget({
                                                      type: 'repayment',
                                                      item: rep,
                                                    })
                                                  }
                                                  className="p-1 rounded-md text-rose-500 hover:bg-rose-50"
                                                  title="إلغاء الدفعة"
                                                >
                                                  <Ban size={14} />
                                                </button>
                                              )}
                                              {rep.salary_id && (
                                                <span className="text-[10px] text-slate-400">
                                                  ضمن الراتب #{rep.salary_id}
                                                </span>
                                              )}
                                            </td>
                                          </tr>
                                        );
                                      })}
                                    </tbody>
                                  </table>
                                </div>
                              )}
                            </div>
                          </td>
                        </tr>
                      )}
                    </React.Fragment>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Modal إضافة سلفة أو تسبقة */}
      {showAddModal && (
        <AdvanceModal
          employeesList={employees}
          onClose={() => setShowAddModal(false)}
          onSuccess={() => {
            setShowAddModal(false);
            setSuccessMsg('تمت إضافة السلفة/التسبقة بنجاح');
            loadData();
          }}
        />
      )}

      {/* Modal سداد قسط من السلفة */}
      {settleTarget && (
        <SettleAdvanceModal
          advance={settleTarget}
          saving={settling}
          onClose={() => setSettleTarget(null)}
          onError={(msg) => setError(msg)}
          onSubmit={handleSettleSubmit}
        />
      )}

      {/* Modal الإلغاء الموثق */}
      {cancelTarget && (
        <CancelReasonModal
          title={cancelTarget.type === 'advance' ? 'إلغاء سلفة موظف' : 'إلغاء قسط مسدد'}
          description={
            cancelTarget.type === 'advance'
              ? 'سيتم إلغاء السلفة وعكس أثر خروج المال من الخزينة المركزية مع توثيق سبب الإلغاء.'
              : 'سيتم إلغاء دفعة القسط وإعادة احتساب المتبقي على السلفة وعكس الأثر من الخزينة.'
          }
          isOpen={true}
          isSubmitting={cancelling}
          onClose={() => setCancelTarget(null)}
          onConfirm={handleCancelConfirm}
        />
      )}
    </div>
  );
}
export default EmployeeAdvancePage;
