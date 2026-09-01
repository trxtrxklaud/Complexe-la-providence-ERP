import { useState, useEffect, useMemo } from 'react';
import {
  Search,
  Users,
  CheckSquare,
  Square,
  ArrowRight,
  ArrowLeft,
  RefreshCw,
  Printer,
  AlertCircle,
  CheckCircle,
  Clock,
  ShieldCheck,
  CreditCard,
  Filter,
  DollarSign,
  ChevronRight,
} from 'lucide-react';
import {
  getStudents,
  getSectionOptions,
  type SectionOption,
  type Student,
} from '../../api/students';
import { apiFetch } from '../../api/http';
import { ListSkeleton } from '../../components/DataSkeleton';
import { EnrollmentFeeItemsSelector } from '../../components/Payments/EnrollmentFeeItemsSelector';
import { ReceiptModal, type ReceiptData } from '../Payments/ReceiptModal';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  beige: '#EFEAE0',
  danger: '#A03434',
  dangerBg: '#FDECEC',
  success: '#2E7D32',
  successBg: '#E8F5E9',
  warning: '#ED6C02',
  warningBg: '#FFF4E5',
};

function todayLocal(): string {
  const now = new Date();
  const offset = now.getTimezoneOffset() * 60000;
  return new Date(now.getTime() - offset).toISOString().slice(0, 10);
}

interface StudentOverride {
  student_id: number;
  student: Student;
  amount: number;
  fee_items?: Array<{ fee_type_id: number; amount: number; description?: string }>;
}

interface BulkReenrollResult {
  student_id: number;
  status: 'enrolled' | 'already_enrolled' | 'failed';
  message: string;
  enrollment_id?: number;
  payment?: {
    id: number;
    amount: number | string;
    payment_date: string | null;
    method: string;
    notes?: string | null;
    receipt_number: string;
    items: Array<{ name: string; amount: number }>;
  } | null;
}

export function BulkEnroll() {
  const [step, setStep] = useState<1 | 2 | 3>(1);

  // Data Loading
  const [students, setStudents] = useState<Student[]>([]);
  const [loadingStudents, setLoadingStudents] = useState(true);
  const [sections, setSections] = useState<SectionOption[]>([]);
  const [sectionsLoading, setSectionsLoading] = useState(true);

  // Filters & Search (Step 1)
  const [search, setSearch] = useState('');
  const [sourceSectionFilter, setSourceSectionFilter] = useState('');
  const [hideEnrolled, setHideEnrolled] = useState(false);

  // Selected Students Map: student_id -> boolean
  const [selectedMap, setSelectedMap] = useState<Record<number, boolean>>({});

  // Common Settings (Step 1)
  const [targetSectionId, setTargetSectionId] = useState('');
  const [paymentMethod, setPaymentMethod] = useState<'cash' | 'bank_transfer' | 'check' | 'card'>('cash');
  const [paymentDate, setPaymentDate] = useState(todayLocal());
  const [commonAmount, setCommonAmount] = useState<number>(70);
  const [commonFeeItems, setCommonFeeItems] = useState<Array<{ fee_type_id: number; amount: number; description: string }>>([]);
  const [commonNotes, setCommonNotes] = useState('معلوم تجديد الترسيم (ترسيم جماعي)');

  // Step 2: Overrides Map: student_id -> number
  const [studentOverrides, setStudentOverrides] = useState<Record<number, number>>({});

  // Execution & Results (Step 2 -> 3)
  const [isProcessing, setIsProcessing] = useState(false);
  const [progressPercent, setProgressPercent] = useState(0);
  const [results, setResults] = useState<BulkReenrollResult[]>([]);
  const [summary, setSummary] = useState({ total: 0, enrolled: 0, already_enrolled: 0, failed: 0 });
  const [errorMessage, setErrorMessage] = useState('');

  // Receipt Modal
  const [activeReceipt, setActiveReceipt] = useState<ReceiptData | null>(null);

  useEffect(() => {
    loadSections();
  }, []);

  useEffect(() => {
    loadStudents(sourceSectionFilter);
  }, [sourceSectionFilter]);

  async function loadSections() {
    try {
      setSectionsLoading(true);
      const data = await getSectionOptions();
      setSections(data || []);
    } catch (err) {
      console.error('Failed to load sections:', err);
    } finally {
      setSectionsLoading(false);
    }
  }

  async function loadStudents(section: string) {
    try {
      setLoadingStudents(true);
      const data = section
        ? await getStudents({ level: section, student_name: '', phone: '', birthday: '', year: '', cnte: '', per_page: 100 })
        : await getStudents({ per_page: 100 });
      setStudents(data || []);
    } catch (err) {
      console.error('Failed to load students:', err);
    } finally {
      setLoadingStudents(false);
    }
  }

  const isAlreadyEnrolled = (st: Student) =>
    Boolean(
      st.enrollments?.some(
        (en: any) =>
          en.status === 'active' &&
          (en.academic_year?.is_active === 1 || en.academic_year?.is_active === true)
      )
    );

  const getActiveEnrollment = (st: Student) =>
    st.enrollments?.find(
      (en: any) =>
        en.status === 'active' &&
        (en.academic_year?.is_active === 1 || en.academic_year?.is_active === true)
    );

  // Filtered students for Step 1
  const filteredStudents = useMemo(() => {
    const q = search.trim().toLowerCase();
    return students.filter((st) => {
      if (hideEnrolled && isAlreadyEnrolled(st)) return false;
      if (!q) return true;
      const fullName = `${st.first_name || ''} ${st.last_name || ''}`.toLowerCase();
      const code = (st.student_code || '').toLowerCase();
      const phone = (st.guardian_phone || st.mother_phone || '').toLowerCase();
      return fullName.includes(q) || code.includes(q) || phone.includes(q);
    });
  }, [students, search, hideEnrolled]);

  const selectedStudentIds = useMemo(() => {
    return Object.keys(selectedMap)
      .map(Number)
      .filter((id) => selectedMap[id]);
  }, [selectedMap]);

  const selectedStudentsList = useMemo(() => {
    const mapById = new Map<number, Student>();
    students.forEach((s) => mapById.set(s.id, s));
    return selectedStudentIds.map((id) => mapById.get(id)).filter(Boolean) as Student[];
  }, [selectedStudentIds, students]);

  // Handle Select All / Deselect All
  function toggleSelectAll() {
    const selectableStudents = filteredStudents.filter((s) => !isAlreadyEnrolled(s));
    const allSelectableChecked = selectableStudents.length > 0 && selectableStudents.every((s) => selectedMap[s.id]);
    const nextMap = { ...selectedMap };
    selectableStudents.forEach((s) => {
      nextMap[s.id] = !allSelectableChecked;
    });
    setSelectedMap(nextMap);
  }

  function toggleStudent(st: Student) {
    if (isAlreadyEnrolled(st)) return;
    setSelectedMap((prev) => ({
      ...prev,
      [st.id]: !prev[st.id],
    }));
  }

  // Handle Step 1 -> Step 2
  function handleGoToPreview() {
    if (selectedStudentIds.length === 0) {
      alert('يرجى تحديد تلميذ واحد على الأقل.');
      return;
    }
    if (!targetSectionId) {
      alert('يرجى اختيار القسم المستهدف.');
      return;
    }

    // Initialize overrides with common amount
    const initialOverrides: Record<number, number> = {};
    selectedStudentIds.forEach((id) => {
      initialOverrides[id] = studentOverrides[id] ?? commonAmount;
    });
    setStudentOverrides(initialOverrides);
    setErrorMessage('');
    setStep(2);
  }

  // Total Expected Cash in Step 2
  const totalExpectedAmount = useMemo(() => {
    return selectedStudentIds.reduce((sum, id) => {
      const amt = Number(studentOverrides[id] ?? commonAmount) || 0;
      return sum + amt;
    }, 0);
  }, [selectedStudentIds, studentOverrides, commonAmount]);

  // Execute Bulk Reenrollment
  async function handleConfirmBulkEnroll() {
    setIsProcessing(true);
    setProgressPercent(10);
    setErrorMessage('');

    try {
      const payload = {
        section_id: Number(targetSectionId),
        payment_method: paymentMethod,
        payment_date: paymentDate,
        notes: commonNotes,
        students: selectedStudentIds.map((id) => {
          const customAmount = Number(studentOverrides[id] ?? commonAmount) || 0;
          return {
            student_id: id,
            registration_amount: customAmount,
            fee_items: commonFeeItems.length > 0
              ? commonFeeItems.map((fi) => ({
                  fee_type_id: fi.fee_type_id,
                  amount: customAmount > 0 ? fi.amount : 0,
                  description: fi.description,
                }))
              : undefined,
          };
        }),
      };

      setProgressPercent(40);

      const response: any = await apiFetch('/students/bulk-reenroll', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      setProgressPercent(100);
      setResults(response.results || []);
      setSummary(response.summary || {
        total: payload.students.length,
        enrolled: (response.results || []).filter((r: any) => r.status === 'enrolled').length,
        already_enrolled: (response.results || []).filter((r: any) => r.status === 'already_enrolled').length,
        failed: (response.results || []).filter((r: any) => r.status === 'failed').length,
      });

      setStep(3);
    } catch (err: any) {
      console.error('Bulk enrollment failed:', err);
      setErrorMessage(err?.message || 'حدث خطأ غير متوقع أثناء إرسال بيانات الترسيم الجماعي.');
    } finally {
      setIsProcessing(false);
    }
  }

  // Open Receipt Modal
  function handleOpenReceipt(res: BulkReenrollResult) {
    if (!res.payment) return;

    const studentObj = students.find((s) => s.id === res.student_id);
    const studentName = studentObj
      ? ([studentObj.first_name, studentObj.last_name].filter(Boolean).join(' ') || `تلميذ #${res.student_id}`)
      : `تلميذ #${res.student_id}`;
    const guardianName = studentObj?.guardian_first_name
      ? `${studentObj.guardian_first_name} ${studentObj.guardian_last_name || ''}`.trim()
      : studentObj?.guardians?.[0]
      ? `${studentObj.guardians[0].first_name} ${studentObj.guardians[0].last_name}`.trim()
      : '';
    const guardianPhone = studentObj?.guardian_phone || studentObj?.guardians?.[0]?.phone || '';

    const targetSec = sections.find((s) => s.id === Number(targetSectionId));
    const sectionName = targetSec ? targetSec.label : '';

    const receiptData: ReceiptData = {
      payment_id: res.payment.id,
      receipt_number: res.payment.receipt_number || `REC-${String(res.payment.id).padStart(6, '0')}`,
      payment_date: res.payment.payment_date || paymentDate,
      method: res.payment.method || paymentMethod,
      notes: res.payment.notes || commonNotes,
      student_name: studentName,
      student_code: studentObj?.student_code || undefined,
      guardian_name: guardianName,
      guardian_phone: guardianPhone,
      section_name: sectionName,
      academic_year: '2026-2027',
      amount: res.payment.amount,
      total: res.payment.amount,
      items: res.payment.items && res.payment.items.length > 0
        ? res.payment.items.map((i) => ({
            name: i.name,
            description: i.name,
            amount: i.amount,
          }))
        : commonFeeItems.length > 0
        ? commonFeeItems.map((fi) => ({ name: fi.description, description: fi.description, amount: fi.amount }))
        : [{ description: 'معلوم تجديد الترسيم', amount: res.payment.amount }],
    };

    setActiveReceipt(receiptData);
  }

  function handleStartOver() {
    setSelectedMap({});
    setStudentOverrides({});
    setResults([]);
    setErrorMessage('');
    setStep(1);
    loadStudents(sourceSectionFilter);
  }

  const targetSectionLabel = useMemo(() => {
    const s = sections.find((sec) => sec.id === Number(targetSectionId));
    return s ? s.label : 'غير محدد';
  }, [sections, targetSectionId]);

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6" dir="rtl">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b pb-4" style={{ borderColor: C.line }}>
        <div>
          <div className="flex items-center gap-2">
            <Users className="w-7 h-7" style={{ color: C.forest }} />
            <h1 className="text-2xl font-black" style={{ color: C.ink }}>
              الترسيم الجماعي للتلاميذ القدامى
            </h1>
          </div>
          <p className="text-sm mt-1" style={{ color: C.muted }}>
            إعادة ترسيم مجموعة من التلاميذ ونقلهم إلى قسم جديد مع تسجيل معاليم الترسيم في الخزينة دفعة واحدة.
          </p>
        </div>

        {/* Wizard Step Indicator */}
        <div className="flex items-center gap-2">
          <div className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold ${step === 1 ? 'bg-[#3B4A36] text-white shadow-sm' : 'bg-gray-100 text-gray-600'}`}>
            <span>1. اختيار التلاميذ والخطة</span>
          </div>
          <ChevronRight className="w-4 h-4 text-gray-400" />
          <div className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold ${step === 2 ? 'bg-[#3B4A36] text-white shadow-sm' : 'bg-gray-100 text-gray-600'}`}>
            <span>2. المعاينة والتخصيص</span>
          </div>
          <ChevronRight className="w-4 h-4 text-gray-400" />
          <div className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold ${step === 3 ? 'bg-[#3B4A36] text-white shadow-sm' : 'bg-gray-100 text-gray-600'}`}>
            <span>3. النتائج والطباعة</span>
          </div>
        </div>
      </div>

      {/* ========================================================================= */}
      {/* STEP 1: Student Selection & Common Settings                                */}
      {/* ========================================================================= */}
      {step === 1 && (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          {/* Left Column: Student Selection Table (7 cols) */}
          <div className="lg:col-span-7 bg-white rounded-xl border shadow-sm p-5 space-y-4" style={{ borderColor: C.line }}>
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <h2 className="text-base font-bold flex items-center gap-2" style={{ color: C.ink }}>
                <Users className="w-5 h-5" style={{ color: C.forest }} />
                <span>قائمة التلاميذ</span>
                <span className="text-xs px-2 py-0.5 rounded-full font-bold bg-[#E3EBDB] text-[#2E3B2A]">
                  {selectedStudentIds.length} محدد
                </span>
              </h2>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setHideEnrolled(!hideEnrolled)}
                  className={`text-xs font-bold px-3 py-1.5 rounded-lg border flex items-center gap-1.5 transition-colors ${
                    hideEnrolled ? 'bg-[#3B4A36] text-white border-[#3B4A36]' : 'border-gray-200 text-gray-700 hover:bg-gray-50'
                  }`}
                >
                  {hideEnrolled ? 'إظهار المرسّمين' : 'إخفاء المرسّمين'}
                </button>

                <button
                  type="button"
                  onClick={toggleSelectAll}
                  className="text-xs font-bold px-3 py-1.5 rounded-lg border flex items-center gap-1.5 hover:bg-gray-50 transition-colors"
                  style={{ borderColor: C.line, color: C.forest }}
                >
                  {filteredStudents.filter((s) => !isAlreadyEnrolled(s)).length > 0 &&
                  filteredStudents.filter((s) => !isAlreadyEnrolled(s)).every((s) => selectedMap[s.id]) ? (
                    <>
                      <CheckSquare className="w-4 h-4" /> إلغاء تحديد الكل
                    </>
                  ) : (
                    <>
                      <Square className="w-4 h-4" /> تحديد الكل ({filteredStudents.filter((s) => !isAlreadyEnrolled(s)).length})
                    </>
                  )}
                </button>
              </div>
            </div>

            {/* Search and Filters */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div className="relative">
                <Search className="w-4 h-4 absolute right-3 top-3 text-gray-400" />
                <input
                  type="text"
                  placeholder="بحث بالاسم أو الرمز..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="w-full pr-9 pl-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-[#3B4A36] focus:outline-none"
                  style={{ borderColor: C.line }}
                />
              </div>

              <div>
                <select
                  value={sourceSectionFilter}
                  onChange={(e) => setSourceSectionFilter(e.target.value)}
                  className="w-full px-3 py-2 text-sm border rounded-lg bg-white focus:ring-2 focus:ring-[#3B4A36] focus:outline-none"
                  style={{ borderColor: C.line }}
                >
                  <option value="">جميع الأقسام السابقة</option>
                  {sections.map((sec) => (
                    <option key={sec.id} value={sec.id}>
                      {sec.label}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            {/* Students Table */}
            <div className="border rounded-lg overflow-hidden max-h-[420px] overflow-y-auto" style={{ borderColor: C.line }}>
              {loadingStudents ? (
                <div className="p-4">
                  <ListSkeleton count={6} />
                </div>
              ) : filteredStudents.length === 0 ? (
                <div className="p-8 text-center text-sm" style={{ color: C.muted }}>
                  لا يوجد تلاميذ يطابقون خيارات البحث.
                </div>
              ) : (
                <table className="w-full text-right text-xs">
                  <thead className="bg-[#FAF8F5] sticky top-0 border-b font-bold" style={{ borderColor: C.line, color: C.ink }}>
                    <tr>
                      <th className="p-3 w-10 text-center">اختيار</th>
                      <th className="p-3">اسم التلميذ</th>
                      <th className="p-3">الرمز</th>
                      <th className="p-3">القسم الحالي</th>
                      <th className="p-3">جوال الولي</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y" style={{ borderColor: C.line }}>
                    {filteredStudents.map((st) => {
                      const enrolled = isAlreadyEnrolled(st);
                      const isChecked = Boolean(selectedMap[st.id]);
                      const activeEn = getActiveEnrollment(st);
                      const currentSec = activeEn
                        ? `${activeEn.level?.name || ''} ${activeEn.section?.name || ''}`.trim()
                        : st.enrollments?.[0]?.section?.name
                        ? `${st.enrollments[0]?.level?.name || ''} ${st.enrollments[0].section.name}`.trim()
                        : '—';
                      const phone = st.guardian_phone || st.mother_phone || '—';

                      return (
                        <tr
                          key={st.id}
                          onClick={() => toggleStudent(st)}
                          className={`transition-colors ${
                            enrolled
                              ? 'opacity-40 bg-gray-50/70 cursor-not-allowed'
                              : isChecked
                              ? 'bg-[#F4F7F2] cursor-pointer'
                              : 'hover:bg-gray-50 cursor-pointer'
                          }`}
                        >
                          <td className="p-3 text-center" onClick={(e) => e.stopPropagation()}>
                            <input
                              type="checkbox"
                              checked={isChecked}
                              disabled={enrolled}
                              onChange={() => toggleStudent(st)}
                              className="w-4 h-4 rounded text-[#3B4A36] focus:ring-[#3B4A36] disabled:opacity-30 disabled:cursor-not-allowed"
                            />
                          </td>
                          <td className="p-3 font-bold" style={{ color: C.ink }}>
                            <div className="flex items-center gap-2 flex-wrap">
                              <span>{[st.first_name, st.last_name].filter(Boolean).join(' ') || `تلميذ #${st.id}`}</span>
                              {enrolled && (
                                <span className="text-[11px] px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 font-bold inline-flex items-center gap-1 shadow-2xs">
                                  مرسَّم ✓
                                </span>
                              )}
                            </div>
                          </td>
                          <td className="p-3 font-mono text-gray-500">{st.student_code || '—'}</td>
                          <td className="p-3">
                            <span className={enrolled ? 'font-bold text-emerald-800' : ''}>
                              {currentSec}
                            </span>
                          </td>
                          <td className="p-3 font-mono">{phone}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              )}
            </div>
          </div>

          {/* Right Column: Settings & Fee Plan (5 cols) */}
          <div className="lg:col-span-5 bg-white rounded-xl border shadow-sm p-5 space-y-5" style={{ borderColor: C.line }}>
            <h2 className="text-base font-bold flex items-center gap-2" style={{ color: C.ink }}>
              <DollarSign className="w-5 h-5" style={{ color: C.forest }} />
              <span>إعدادات الترسيم الموحدة</span>
            </h2>

            {/* Target Section Selection */}
            <div>
              <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                القسم المستهدف للتسجيل <span className="text-red-500">*</span>
              </label>
              <select
                value={targetSectionId}
                onChange={(e) => setTargetSectionId(e.target.value)}
                className="w-full px-3 py-2 text-sm border rounded-lg bg-white focus:ring-2 focus:ring-[#3B4A36] focus:outline-none"
                style={{ borderColor: C.line }}
              >
                <option value="">-- حدد القسم المستهدف للسنة النشطة --</option>
                {sections.map((sec) => (
                  <option key={sec.id} value={sec.id}>
                    {sec.label}
                  </option>
                ))}
              </select>
              <p className="text-xs mt-1" style={{ color: C.muted }}>
                سيتم نقل جميع التلاميذ المحددين وترسيمهم في هذا القسم.
              </p>
            </div>

            {/* Payment Details */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                  طريقة الدفع
                </label>
                <select
                  value={paymentMethod}
                  onChange={(e) => setPaymentMethod(e.target.value as any)}
                  className="w-full px-3 py-2 text-sm border rounded-lg bg-white focus:ring-2 focus:ring-[#3B4A36] focus:outline-none"
                  style={{ borderColor: C.line }}
                >
                  <option value="cash">نقداً (Cash)</option>
                  <option value="bank_transfer">تحويل بنكي</option>
                  <option value="check">شيك</option>
                  <option value="card">بطاقة بنكية</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                  تاريخ الدفع
                </label>
                <input
                  type="date"
                  value={paymentDate}
                  onChange={(e) => setPaymentDate(e.target.value)}
                  className="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-[#3B4A36] focus:outline-none"
                  style={{ borderColor: C.line }}
                />
              </div>
            </div>

            {/* Fee Items Component */}
            <div>
              <label className="block text-xs font-bold mb-1.5" style={{ color: C.ink }}>
                بنود ومبلغ الترسيم الافتراضي لكل تلميذ
              </label>
              <EnrollmentFeeItemsSelector
                onTotalChange={(total, items) => {
                  setCommonAmount(total);
                  setCommonFeeItems(items);
                }}
              />
            </div>

            {/* Notes */}
            <div>
              <label className="block text-xs font-bold mb-1" style={{ color: C.ink }}>
                ملاحظات
              </label>
              <input
                type="text"
                value={commonNotes}
                onChange={(e) => setCommonNotes(e.target.value)}
                placeholder="ملاحظات العملية..."
                className="w-full px-3 py-2 text-xs border rounded-lg focus:ring-2 focus:ring-[#3B4A36] focus:outline-none"
                style={{ borderColor: C.line }}
              />
            </div>

            {/* Proceed Button */}
            <div className="pt-2">
              <button
                type="button"
                onClick={handleGoToPreview}
                disabled={selectedStudentIds.length === 0 || !targetSectionId}
                className={`w-full py-3 rounded-lg text-sm font-bold flex items-center justify-center gap-2 text-white transition-all ${
                  selectedStudentIds.length === 0 || !targetSectionId
                    ? 'bg-gray-300 cursor-not-allowed'
                    : 'bg-[#3B4A36] hover:bg-[#2E3B2A] shadow-md'
                }`}
              >
                <span>المتابعة للمعاينة والتأكيد ({selectedStudentIds.length} تلميذ)</span>
                <ArrowLeft className="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* STEP 2: Preview & Custom Overrides Table                                  */}
      {/* ========================================================================= */}
      {step === 2 && (
        <div className="bg-white rounded-xl border shadow-sm p-6 space-y-6" style={{ borderColor: C.line }}>
          {/* Summary Cards */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div className="p-4 rounded-xl border bg-[#FAF8F5]" style={{ borderColor: C.line }}>
              <div className="text-xs font-bold text-gray-500">القسم المستهدف</div>
              <div className="text-lg font-black mt-1" style={{ color: C.ink }}>
                {targetSectionLabel}
              </div>
            </div>

            <div className="p-4 rounded-xl border bg-[#FAF8F5]" style={{ borderColor: C.line }}>
              <div className="text-xs font-bold text-gray-500">إجمالي التلاميذ المحددين</div>
              <div className="text-lg font-black mt-1" style={{ color: C.forest }}>
                {selectedStudentIds.length} تلميذ
              </div>
            </div>

            <div className="p-4 rounded-xl border bg-[#FAF8F5]" style={{ borderColor: C.line }}>
              <div className="text-xs font-bold text-gray-500">إجمالي المقبوضات المتوقعة</div>
              <div className="text-lg font-black mt-1 text-emerald-700">
                {totalExpectedAmount.toFixed(2)} د.ت
              </div>
            </div>
          </div>

          {/* Table of selected students with individual override */}
          <div>
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-bold" style={{ color: C.ink }}>
                مراجعة وتخصيص المبالغ للتلاميذ المحددين:
              </h3>
              <span className="text-xs text-gray-500">
                يمكنك تعديل مبلغ الترسيم لأي تلميذ استثنائي مباشرة في الجدول (0 = تسجيل بلا دفع فوري).
              </span>
            </div>

            <div className="border rounded-lg overflow-hidden" style={{ borderColor: C.line }}>
              <table className="w-full text-right text-xs">
                <thead className="bg-[#FAF8F5] border-b font-bold" style={{ borderColor: C.line, color: C.ink }}>
                  <tr>
                    <th className="p-3 w-12 text-center">#</th>
                    <th className="p-3">اسم التلميذ</th>
                    <th className="p-3">الرمز المدرسي</th>
                    <th className="p-3">ولي الأمر</th>
                    <th className="p-3">الهاتف</th>
                    <th className="p-3 w-40 text-center">مبلغ الترسيم (د.ت)</th>
                  </tr>
                </thead>
                <tbody className="divide-y" style={{ borderColor: C.line }}>
                  {selectedStudentsList.map((st, index) => {
                    const currentAmt = studentOverrides[st.id] ?? commonAmount;
                    const guardian = st.guardian_first_name
                      ? `${st.guardian_first_name} ${st.guardian_last_name || ''}`.trim()
                      : st.guardians?.[0]
                      ? `${st.guardians[0].first_name} ${st.guardians[0].last_name}`.trim()
                      : '—';
                    const phone = st.guardian_phone || st.mother_phone || '—';

                    return (
                      <tr key={st.id} className="hover:bg-gray-50">
                        <td className="p-3 text-center text-gray-400 font-mono">{index + 1}</td>
                        <td className="p-3 font-bold" style={{ color: C.ink }}>
                          {[st.first_name, st.last_name].filter(Boolean).join(' ') || `تلميذ #${st.id}`}
                        </td>
                        <td className="p-3 font-mono text-gray-500">{st.student_code || '—'}</td>
                        <td className="p-3">{guardian}</td>
                        <td className="p-3 font-mono">{phone}</td>
                        <td className="p-3 text-center">
                          <input
                            type="number"
                            min="0"
                            step="0.5"
                            value={currentAmt}
                            onChange={(e) => {
                              const val = parseFloat(e.target.value) || 0;
                              setStudentOverrides((prev) => ({
                                ...prev,
                                [st.id]: val,
                              }));
                            }}
                            className="w-28 px-2 py-1 text-center font-bold border rounded focus:ring-2 focus:ring-[#3B4A36] focus:outline-none"
                            style={{ borderColor: C.line }}
                          />
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>

          {/* Error Banner */}
          {errorMessage && (
            <div className="p-3 rounded-lg border text-sm flex items-center gap-2 bg-[#FDECEC] border-[#A03434] text-[#A03434]">
              <AlertCircle className="w-5 h-5 flex-shrink-0" />
              <span>{errorMessage}</span>
            </div>
          )}

          {/* Progress Bar while executing */}
          {isProcessing && (
            <div className="space-y-2">
              <div className="flex justify-between text-xs font-bold" style={{ color: C.forest }}>
                <span>جاري معالجة الترسيم الجماعي وتسجيل القيود المالية...</span>
                <span>{progressPercent}%</span>
              </div>
              <div className="w-full bg-gray-200 h-2.5 rounded-full overflow-hidden">
                <div
                  className="bg-[#3B4A36] h-full transition-all duration-300 rounded-full"
                  style={{ width: `${progressPercent}%` }}
                />
              </div>
            </div>
          )}

          {/* Action Buttons */}
          <div className="flex items-center justify-between pt-4 border-t" style={{ borderColor: C.line }}>
            <button
              type="button"
              onClick={() => setStep(1)}
              disabled={isProcessing}
              className="px-5 py-2.5 border rounded-lg text-sm font-bold flex items-center gap-2 hover:bg-gray-50 transition-colors"
              style={{ borderColor: C.line, color: C.ink }}
            >
              <ArrowRight className="w-4 h-4" />
              <span>الرجوع للتعديل</span>
            </button>

            <button
              type="button"
              onClick={handleConfirmBulkEnroll}
              disabled={isProcessing}
              className="px-6 py-2.5 bg-[#3B4A36] hover:bg-[#2E3B2A] text-white rounded-lg text-sm font-bold shadow-md flex items-center gap-2 transition-all disabled:bg-gray-400"
            >
              {isProcessing ? (
                <>
                  <RefreshCw className="w-4 h-4 animate-spin" />
                  <span>جاري الترسيم...</span>
                </>
              ) : (
                <>
                  <CheckCircle className="w-4 h-4" />
                  <span>تأكيد الترسيم الجماعي ({selectedStudentIds.length} تلميذ)</span>
                </>
              )}
            </button>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* STEP 3: Results & Receipts                                               */}
      {/* ========================================================================= */}
      {step === 3 && (
        <div className="bg-white rounded-xl border shadow-sm p-6 space-y-6" style={{ borderColor: C.line }}>
          {/* Result Banner */}
          <div className="flex items-center justify-between border-b pb-4" style={{ borderColor: C.line }}>
            <div>
              <h2 className="text-xl font-black flex items-center gap-2" style={{ color: C.ink }}>
                <CheckCircle className="w-6 h-6 text-emerald-600" />
                <span>اكتملت معالجة الترسيم الجماعي</span>
              </h2>
              <p className="text-xs text-gray-500 mt-1">
                القسم المستهدف: <span className="font-bold text-gray-800">{targetSectionLabel}</span> — تم تحديث السجلات وقيود الخزينة بنجاح.
              </p>
            </div>

            <button
              type="button"
              onClick={handleStartOver}
              className="px-4 py-2 border rounded-lg text-xs font-bold flex items-center gap-1.5 hover:bg-gray-50 transition-colors"
              style={{ borderColor: C.line, color: C.forest }}
            >
              <RefreshCw className="w-4 h-4" />
              <span>ترسيم دفعة جديدة</span>
            </button>
          </div>

          {/* Stats Badges */}
          <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div className="p-4 rounded-xl border bg-gray-50 text-center">
              <div className="text-xs font-bold text-gray-500">إجمالي المعالجة</div>
              <div className="text-2xl font-black mt-1" style={{ color: C.ink }}>
                {summary.total}
              </div>
            </div>

            <div className="p-4 rounded-xl border bg-[#E8F5E9] border-emerald-200 text-center">
              <div className="text-xs font-bold text-emerald-800">تم الترسيم بنجاح</div>
              <div className="text-2xl font-black mt-1 text-emerald-700">
                {summary.enrolled}
              </div>
            </div>

            <div className="p-4 rounded-xl border bg-[#FFF4E5] border-amber-200 text-center">
              <div className="text-xs font-bold text-amber-800">مُرسَّم سلفاً</div>
              <div className="text-2xl font-black mt-1 text-amber-700">
                {summary.already_enrolled}
              </div>
            </div>

            <div className="p-4 rounded-xl border bg-[#FDECEC] border-rose-200 text-center">
              <div className="text-xs font-bold text-rose-800">تعذّر الترسيم</div>
              <div className="text-2xl font-black mt-1 text-rose-700">
                {summary.failed}
              </div>
            </div>
          </div>

          {/* Results Details Table */}
          <div className="border rounded-lg overflow-hidden" style={{ borderColor: C.line }}>
            <table className="w-full text-right text-xs">
              <thead className="bg-[#FAF8F5] border-b font-bold" style={{ borderColor: C.line, color: C.ink }}>
                <tr>
                  <th className="p-3 w-10 text-center">#</th>
                  <th className="p-3">اسم التلميذ</th>
                  <th className="p-3">حالة الترسيم</th>
                  <th className="p-3">المبلغ المسجل</th>
                  <th className="p-3">رقم الوصل</th>
                  <th className="p-3 w-32 text-center">الإجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y" style={{ borderColor: C.line }}>
                {results.map((res, index) => {
                  const studentObj = students.find((s) => s.id === res.student_id);
                  const studentName = studentObj
                    ? ([studentObj.first_name, studentObj.last_name].filter(Boolean).join(' ') || `تلميذ #${res.student_id}`)
                    : `تلميذ #${res.student_id}`;

                  return (
                    <tr key={res.student_id} className="hover:bg-gray-50">
                      <td className="p-3 text-center text-gray-400 font-mono">{index + 1}</td>
                      <td className="p-3 font-bold" style={{ color: C.ink }}>
                        {studentName}
                        {studentObj?.student_code && (
                          <span className="text-[11px] font-mono text-gray-400 mr-2">({studentObj.student_code})</span>
                        )}
                      </td>
                      <td className="p-3">
                        {res.status === 'enrolled' && (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#E8F5E9] text-[#2E7D32]">
                            <CheckCircle className="w-3.5 h-3.5" /> تم الترسيم
                          </span>
                        )}
                        {res.status === 'already_enrolled' && (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#FFF4E5] text-[#ED6C02]">
                            <AlertCircle className="w-3.5 h-3.5" /> مُرسَّم سلفاً
                          </span>
                        )}
                        {res.status === 'failed' && (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#FDECEC] text-[#A03434]">
                            <AlertCircle className="w-3.5 h-3.5" /> {res.message || 'فشل'}
                          </span>
                        )}
                      </td>
                      <td className="p-3 font-bold">
                        {res.payment ? `${Number(res.payment.amount).toFixed(2)} د.ت` : '0.00 د.ت'}
                      </td>
                      <td className="p-3 font-mono text-gray-600">
                        {res.payment?.receipt_number || '—'}
                      </td>
                      <td className="p-3 text-center">
                        {res.payment ? (
                          <button
                            type="button"
                            onClick={() => handleOpenReceipt(res)}
                            className="px-3 py-1.5 bg-[#3B4A36] hover:bg-[#2E3B2A] text-white rounded text-xs font-bold flex items-center justify-center gap-1.5 transition-colors mx-auto"
                          >
                            <Printer className="w-3.5 h-3.5" />
                            <span>طباعة الوصل</span>
                          </button>
                        ) : (
                          <span className="text-gray-400 text-xs">—</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Reusable Receipt Modal */}
      {activeReceipt && (
        <ReceiptModal
          receipt={activeReceipt}
          onClose={() => setActiveReceipt(null)}
        />
      )}
    </div>
  );
}
export default BulkEnroll;
