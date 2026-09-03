import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Search, ArrowRight, GraduationCap, CreditCard, AlertCircle, CheckCircle, Printer, Loader2, Ban } from 'lucide-react';
import {
  getStudents,
  getSectionOptions,
  reenrollStudent,
  recordRegistrationPayment,
  getStudentPaymentHistory,
  cancelStudentEnrollment,
  type SectionOption,
} from '../../api/students';
import { ListSkeleton } from '../../components/DataSkeleton';
import { EnrollmentFeeItemsSelector } from '../../components/Payments/EnrollmentFeeItemsSelector';
import { ReceiptModal, type ReceiptData } from '../Payments/ReceiptModal';
import { CancelReasonModal } from '../../components/CancelReasonModal';

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
};

/** تاريخ اليوم بصيغة YYYY-MM-DD بتوقيت الجهاز لا بتوقيت UTC. */
function todayLocal(): string {
  const now = new Date();
  const offset = now.getTimezoneOffset() * 60000;
  return new Date(now.getTime() - offset).toISOString().slice(0, 10);
}

export function OldStudentReenroll() {
  const [students, setStudents] = useState<any[]>([]);
  const [search, setSearch] = useState('');
  // فلتر القسم: يُرسل إلى الخادم باسم level لأنّ StudentController يقرأ منه section_id
  // (نفس السلوك المعتمد في شاشة البحث)؛ الفلترة في الخادم لا في المتصفّح
  // حتى لا تتوقّف النتيجة على عدد الصفوف المُحمّلة.
  const [sectionFilter, setSectionFilter] = useState('');
  const [loading, setLoading] = useState(true);
  const [selectedStudent, setSelectedStudent] = useState<any>(null);

  // الأقسام: تُحمّل مرّة واحدة عند فتح الشاشة وتُعاد عند الفشل بطلب المستخدم.
  const [sections, setSections] = useState<SectionOption[]>([]);
  const [sectionsLoading, setSectionsLoading] = useState(true);
  const [sectionsError, setSectionsError] = useState('');

  const [sectionId, setSectionId] = useState('');
  const [submitted, setSubmitted] = useState(false);

  const [paymentMethod, setPaymentMethod] = useState('');
  const [amount, setAmount] = useState('');
  const [paymentDate, setPaymentDate] = useState('');
  const [paymentNotes, setPaymentNotes] = useState('');
  const [feeItems, setFeeItems] = useState<Array<{ fee_type_id: number; amount: number; description: string }>>([]);
  const [createdReceipt, setCreatedReceipt] = useState<ReceiptData | null>(null);
  const [recentReceipts, setRecentReceipts] = useState<Record<number, ReceiptData>>({});
  const [printingStudentId, setPrintingStudentId] = useState<number | null>(null);
  const [studentToCancel, setStudentToCancel] = useState<any | null>(null);
  const [cancelling, setCancelling] = useState(false);

  // يُرفع حين يردّ الخادم code = already_enrolled: التلميذ ترسيمه قائم في السنة
  // النشطة (546 تلميذاً دخلوا عبر ترحيل الترقية دون أن يُقبض معلومهم)، فالمطلوب
  // قبض المعلوم على الترسيم القائم لا إنشاء ترسيم ثانٍ.
  const [alreadyEnrolled, setAlreadyEnrolled] = useState(false);

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  useEffect(() => {
    loadSections();
  }, []);

  useEffect(() => {
    loadStudents(sectionFilter);
  }, [sectionFilter]);

  async function loadStudents(section: string) {
    try {
      setLoading(true);
      const data = section
        ? await getStudents({ level: section, student_name: '', phone: '', birthday: '', year: '', cnte: '', per_page: 100 })
        : await getStudents();
      setStudents(data || []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  }

  async function loadSections() {
    try {
      setSectionsLoading(true);
      setSectionsError('');
      const data = await getSectionOptions();
      setSections(data || []);
      if (!data || data.length === 0) {
        setSectionsError('لا توجد أقسام مسجّلة. أضفها من شاشة المستويات والأقسام قبل الترسيم.');
      }
    } catch (err: any) {
      setSectionsError(err?.message || 'تعذّر تحميل قائمة الأقسام');
    } finally {
      setSectionsLoading(false);
    }
  }

  const filtered = students.filter((s) => {
    const fullName = `${s.first_name || ''} ${s.last_name || ''}`.toLowerCase();
    return fullName.includes(search.toLowerCase()) ||
           (s.student_code || '').toLowerCase().includes(search.toLowerCase());
  });

  // التحقّق في الواجهة يطابق قاعدة الخادم: required|exists:sections,id
  // ويسقط في وضع «مُرسَّم سلفاً» لأنّ القبض لا يمسّ القسم أصلاً.
  const sectionError = !alreadyEnrolled && sectionId === ''
    ? 'القسم إجباري: اختر قسم التلميذ لهذه السنة الدراسية.'
    : '';
  const showSectionError = submitted && sectionError !== '';

  // الدفع اختياري ككتلة واحدة: إمّا مبلغ وطريقة وتاريخ، أو لا شيء.
  // نفس قاعدة الخادم (required_with:registration_amount)، مكرّرة هنا ليرى القابض
  // الخطأ قبل إرسال الطلب، لا لتحلّ محلّه — الخادم يبقى هو الحارس الأخير.
  const amountValue = amount.trim() === '' ? 0 : Number(amount);
  const hasAmount = amount.trim() !== '';
  const amountInvalid = hasAmount && (!Number.isFinite(amountValue) || amountValue <= 0);

  let paymentError = '';
  if (amountInvalid) {
    paymentError = 'مبلغ الترسيم يجب أن يكون رقماً أكبر من صفر.';
  } else if (alreadyEnrolled && !hasAmount) {
    paymentError = 'أدخل المبلغ المقبوض لتسجيله على الترسيم القائم.';
  } else if (hasAmount && paymentMethod === '') {
    paymentError = 'اختر طريقة الدفع الموافقة للمبلغ المقبوض.';
  } else if (hasAmount && paymentDate === '') {
    paymentError = 'تاريخ الدفع إجباري مع وجود مبلغ مقبوض.';
  } else if (!hasAmount && (paymentMethod !== '' || paymentNotes.trim() !== '')) {
    paymentError = 'أدخل المبلغ المقبوض، أو أفرغ حقول الدفع لترسيم بلا دفع.';
  }
  const showPaymentError = submitted && paymentError !== '';

  function resetForm() {
    setSectionId('');
    setSubmitted(false);
    setPaymentMethod('');
    setAmount('');
    setPaymentDate('');
    setPaymentNotes('');
    setAlreadyEnrolled(false);
  }

  function getStudentStatus(student: any) {
    if (!student) return { isEnrolled: false, isPaid: false, sectionName: '', levelName: '' };

    if (recentReceipts[student.id]) {
      const enr = student.enrollments?.[0];
      return {
        isEnrolled: true,
        isPaid: true,
        sectionName: enr?.section?.name || '',
        levelName: enr?.level?.name || '',
      };
    }

    const enr = student.enrollments?.[0];
    if (!enr || enr.status !== 'active') {
      return { isEnrolled: false, isPaid: false, sectionName: '', levelName: '' };
    }

    const fees = enr.student_fees || enr.studentFees || [];
    const hasPaidFee = fees.some((f: any) => {
      if (f.status !== 'paid' && Number(f.amount_paid || 0) <= 0) {
        return false;
      }
      const allocations = f.payment_allocations || f.paymentAllocations || [];
      const hasActiveAllocations = allocations.length > 0 && allocations.some((a: any) => !a.payment?.cancelled_at);

      const isReg = f.description?.includes('ترسيم') ||
        f.description?.includes('تسجيل') ||
        f.fee_type?.ledger_category === 'registration_fee';

      return isReg && (f.status === 'paid' || hasActiveAllocations);
    });

    return {
      isEnrolled: true,
      isPaid: hasPaidFee,
      sectionName: enr.section?.name || '',
      levelName: enr.level?.name || '',
    };
  }

  async function handlePrintReceipt(student: any, e?: React.MouseEvent) {
    if (e) e.stopPropagation();

    if (recentReceipts[student.id]) {
      setCreatedReceipt(recentReceipts[student.id]);
      return;
    }

    try {
      setPrintingStudentId(student.id);
      const history = await getStudentPaymentHistory(student.id);
      const payment = history.find((p: any) =>
        !p.cancelled_at && (
          p.allocations?.some((a: any) => a.fee?.description?.includes('ترسيم') || a.fee?.description?.includes('تسجيل')) ||
          p.reference?.includes('registration') ||
          p.reference?.includes('reg') ||
          p.notes?.includes('ترسيم')
        )
      ) || history.find((p: any) => !p.cancelled_at);

      const guardian = student.guardians?.[0];
      const guardianName = guardian ? `${guardian.first_name || ''} ${guardian.last_name || ''}`.trim() : (student.guardian_name || '');
      const activeEnrollment = student.enrollments?.[0];
      const sectionName = activeEnrollment?.section?.name || '';
      const levelName = activeEnrollment?.level?.name || '';
      const academicYear = payment?.enrollment?.academic_year?.name || activeEnrollment?.academic_year?.name || '2026-2027';

      if (payment) {
        const receiptData: ReceiptData = {
          payment_id: payment.id,
          receipt_number: `REC-${String(payment.id).padStart(6, '0')}`,
          payment_date: payment.payment_date || todayLocal(),
          method: payment.method || 'cash',
          notes: payment.reference || payment.notes || 'معلوم الترسيم',
          student_name: `${student.first_name || ''} ${student.last_name || ''}`.trim(),
          student_code: student.student_code || '',
          guardian_name: guardianName,
          guardian_phone: guardian?.phone || student.guardian_phone || '',
          section_name: `${levelName} ${sectionName}`.trim(),
          academic_year: academicYear,
          amount: payment.amount,
          total: payment.amount,
          items: payment.allocations && payment.allocations.length > 0
            ? payment.allocations.map((a: any) => ({
                description: a.fee?.description || 'معلوم الترسيم',
                amount: a.amount,
              }))
            : [{ description: 'معلوم الترسيم', amount: payment.amount }],
        };

        setRecentReceipts((prev) => ({ ...prev, [student.id]: receiptData }));
        setCreatedReceipt(receiptData);
      } else {
        alert('لا توجد دفعة مالية سارية للترسيم (العملية ملغاة أو لم يتم الخلاص بعد).');
      }
    } catch (err: any) {
      alert(err?.message || 'تعذر جلب وصل الترسيم');
    } finally {
      setPrintingStudentId(null);
    }
  }

  async function handleConfirmCancel(reason: string) {
    if (!studentToCancel) return;
    try {
      setCancelling(true);
      const res = await cancelStudentEnrollment(studentToCancel.id, reason);
      const cancelledId = studentToCancel.id;
      setRecentReceipts((prev) => {
        const copy = { ...prev };
        delete copy[cancelledId];
        return copy;
      });
      setSuccess(res.message || 'تم إلغاء خلاص الترسيم واسترجاع المبالغ من الخزينة بنجاح');
      setStudentToCancel(null);
      closeStudent();
      loadStudents(sectionFilter);
    } catch (err: any) {
      alert(err?.message || 'تعذّر إلغاء الترسيم');
    } finally {
      setCancelling(false);
    }
  }

  function openStudent(student: any) {
    setSelectedStudent(student);
    const status = getStudentStatus(student);
    setSectionId(status.sectionName && student.enrollments?.[0]?.section_id ? String(student.enrollments[0].section_id) : sectionFilter);
    setSubmitted(false);
    setError('');
    setSuccess('');
    setAlreadyEnrolled(status.isEnrolled && !status.isPaid);
    // تاريخ اليوم افتراضاً: القبض يقع لحظة الترسيم في الحالة الغالبة،
    // ويبقى قابلاً للتعديل لمن يسجّل قبضاً وقع أمس.
    setPaymentDate(todayLocal());
    setPaymentMethod('');
    setAmount('');
    setPaymentNotes('');
  }

  function closeStudent() {
    setSelectedStudent(null);
    setError('');
    resetForm();
  }

  function announceSuccess(response: any, prefix: string) {
    const student = `${selectedStudent?.first_name || ''} ${selectedStudent?.last_name || ''}`.trim();
    const placed = response?.enrollment?.section?.name
      ? ` — القسم: ${response.enrollment.level?.name || ''} ${response.enrollment.section.name}`.trimEnd()
      : '';
    const paid = response?.payment
      ? ` — دخل الخزينة: ${Number(response.payment.amount).toFixed(2)} د`
      : '';
    setSuccess(`${prefix} ${student}${placed}${paid}`);

    if (response?.payment) {
      const guardianName = `${selectedStudent?.guardians?.[0]?.first_name || ''} ${selectedStudent?.guardians?.[0]?.last_name || ''}`.trim();
      const sectionName = response.enrollment?.section?.name || '';
      const levelName = response.enrollment?.level?.name || '';

      const receiptData: ReceiptData = {
        payment_id: response.payment.id,
        receipt_number: response.payment.receipt_number || `REC-${String(response.payment.id).padStart(6, '0')}`,
        payment_date: response.payment.payment_date || paymentDate,
        method: response.payment.method || paymentMethod,
        notes: response.payment.notes || paymentNotes,
        student_name: student,
        student_code: selectedStudent?.student_code || '',
        guardian_name: guardianName,
        guardian_phone: selectedStudent?.guardians?.[0]?.phone || '',
        section_name: `${levelName} ${sectionName}`.trim(),
        academic_year: response.enrollment?.academic_year?.name || '2026-2027',
        amount: response.payment.amount || amount,
        total: response.payment.amount || amount,
        items: response.payment.items && response.payment.items.length > 0
          ? response.payment.items.map((i: any) => ({
              name: i.name || i.description,
              description: i.name || i.description,
              amount: i.amount,
            }))
          : (feeItems && feeItems.length > 0
              ? feeItems.map((fi) => ({ name: fi.description, description: fi.description, amount: fi.amount }))
              : [{ description: 'معلوم تجديد الترسيم', amount: amount }]),
      };

      if (selectedStudent?.id) {
        setRecentReceipts((prev) => ({ ...prev, [selectedStudent.id]: receiptData }));
      }
      setCreatedReceipt(receiptData);
    }

    setSelectedStudent(null);
    resetForm();
    // إعادة تحميل القائمة حتى تعكس الحالة بعد الحفظ.
    loadStudents(sectionFilter);
  }

  async function handleSave() {
    setSubmitted(true);
    setError('');

    if (!selectedStudent || sectionError || paymentError) return;

    setSaving(true);
    try {
      const response = await reenrollStudent(selectedStudent.id, {
        section_id: Number(sectionId),
        ...(hasAmount
          ? {
              registration_amount: amountValue,
              payment_method: paymentMethod as 'cash' | 'bank_transfer' | 'check' | 'card',
              payment_date: paymentDate,
              ...(paymentNotes.trim() !== '' ? { payment_notes: paymentNotes.trim() } : {}),
              ...(feeItems.length > 0 ? { fee_items: feeItems } : {}),
            }
          : {}),
      });
      announceSuccess(response, 'تم تجديد ترسيم');
    } catch (err: any) {
      // التلميذ مُرسَّم سلفاً: لا يُعاد ترسيمه ولا يُحذف ترسيمه، بل يُقبض معلومه.
      if (err?.code === 'already_enrolled') {
        setAlreadyEnrolled(true);
        setError('التلميذ مُرسَّم فعلاً في السنة الدراسية النشطة، ولا يُرسّم مرتين. إن قبضت معلوم الترسيم منه فأدخل المبلغ وطريقة الدفع ثمّ اضغط «تسجيل المعلوم على الترسيم القائم».');
      } else {
        setError(err?.message || 'حدث خطأ أثناء الترسيم');
      }
    } finally {
      setSaving(false);
    }
  }

  /**
   * قبض المعلوم على ترسيم قائم دون إنشاء ترسيم جديد.
   *
   * مسار منفصل عن الحفظ عمداً: القابض يرى أنّ العملية اختلفت، فلا يظنّ
   * أنّ النظام أنشأ له ترسيماً ثانياً في الخفاء.
   */
  async function handleRecordPaymentOnly() {
    setSubmitted(true);
    setError('');

    if (!selectedStudent || paymentError || !hasAmount) return;

    setSaving(true);
    try {
      const response = await recordRegistrationPayment(selectedStudent.id, {
        registration_amount: amountValue,
        payment_method: paymentMethod as 'cash' | 'bank_transfer' | 'check' | 'card',
        payment_date: paymentDate,
        ...(paymentNotes.trim() !== '' ? { payment_notes: paymentNotes.trim() } : {}),
        ...(feeItems.length > 0 ? { fee_items: feeItems } : {}),
      });
      announceSuccess(response, 'تم تسجيل معلوم الترسيم لـ');
    } catch (err: any) {
      setError(err?.message || 'تعذّر تسجيل المبلغ');
    } finally {
      setSaving(false);
    }
  }

  if (selectedStudent) {
    return (
      <div className="p-6 md:p-8" dir="rtl">
        <div className="flex items-center gap-4 mb-6">
          <button
            onClick={closeStudent}
            className="flex h-10 w-10 items-center justify-center rounded-full bg-white border"
            style={{ borderColor: C.line, color: C.muted }}
          >
            <ArrowRight size={18} />
          </button>
          <div>
            <h1 className="text-2xl font-bold" style={{ color: C.ink }}>
              ترسيم تلميذ قديم
            </h1>
            <p className="text-sm" style={{ color: C.muted }}>
              تجديد ترسيم {selectedStudent.first_name} {selectedStudent.last_name}
            </p>
          </div>
        </div>

        {error && (
          <div
            className="mb-5 flex items-start gap-2 rounded-xl border px-4 py-3 text-sm"
            style={{ borderColor: C.danger, backgroundColor: C.dangerBg, color: C.danger }}
          >
            <AlertCircle size={18} className="mt-0.5 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        <div className="bg-white rounded-[22px] p-6 mb-5 border" style={{ borderColor: C.line }}>
          <div className="flex items-center gap-4 mb-4">
            <div className="w-14 h-14 rounded-full flex items-center justify-center text-white font-bold text-lg"
                 style={{ backgroundColor: C.forest }}>
              {(selectedStudent.first_name || '؟')[0]}
            </div>
            <div>
              <h2 className="text-xl font-bold" style={{ color: C.ink }}>
                {selectedStudent.first_name} {selectedStudent.last_name}
              </h2>
              <p className="text-sm" style={{ color: C.muted }}>
                المعرف: {selectedStudent.student_code || selectedStudent.id}
              </p>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4 text-sm">
            <div>
              <span style={{ color: C.muted }}>تاريخ الميلاد</span>
              <p className="font-medium" style={{ color: C.ink }}>
                {selectedStudent.dob ? new Date(selectedStudent.dob).toLocaleDateString('ar-TN') : '—'}
              </p>
            </div>
            <div>
              <span style={{ color: C.muted }}>الجنس</span>
              <p className="font-medium" style={{ color: C.ink }}>
                {selectedStudent.gender === 'male' ? 'ذكر' : selectedStudent.gender === 'female' ? 'أنثى' : '—'}
              </p>
            </div>
            <div>
              <span style={{ color: C.muted }}>ولي الأمر</span>
              <p className="font-medium" style={{ color: C.ink }}>
                {selectedStudent.guardians?.[0]?.first_name || '—'} {selectedStudent.guardians?.[0]?.last_name || ''}
              </p>
            </div>
            <div>
              <span style={{ color: C.muted }}>هاتف الولي</span>
              <p className="font-medium" dir="ltr" style={{ color: C.ink }}>
                {selectedStudent.guardians?.[0]?.phone || '—'}
              </p>
            </div>
          </div>
        </div>

        {(() => {
          const currentStudentStatus = getStudentStatus(selectedStudent);
          if (currentStudentStatus.isEnrolled && currentStudentStatus.isPaid) {
            return (
              <div className="bg-white rounded-[22px] p-6 border" style={{ borderColor: C.line }}>
                <div className="flex items-start gap-3 p-5 rounded-2xl bg-emerald-50 border border-emerald-200 mb-6">
                  <CheckCircle className="text-emerald-700 mt-0.5 shrink-0" size={24} />
                  <div>
                    <h3 className="font-bold text-emerald-900 text-lg">
                      التلميذ مُرسَّم بالفعل في السنة الدراسية الحالية وتم خلاص معلوم الترسيم
                    </h3>
                    <p className="text-sm text-emerald-700 mt-1">
                      القسم الحالي: <span className="font-bold">{currentStudentStatus.levelName ? currentStudentStatus.levelName + ' ' : ''}{currentStudentStatus.sectionName}</span> — العملية مسجلة في الخزينة، ويمكنك طباعة الوصل مباشرة دون تكرار العملية.
                    </p>
                  </div>
                </div>

                <div className="space-y-3">
                  <button
                    type="button"
                    onClick={() => handlePrintReceipt(selectedStudent)}
                    disabled={printingStudentId === selectedStudent.id}
                    className="w-full py-4 rounded-xl text-white font-bold flex items-center justify-center gap-2 transition hover:opacity-90 shadow-sm"
                    style={{ backgroundColor: C.forest }}
                  >
                    {printingStudentId === selectedStudent.id ? (
                      <Loader2 size={18} className="animate-spin" />
                    ) : (
                      <Printer size={18} />
                    )}
                    <span>طباعة وصل الترسيم</span>
                  </button>

                  <button
                    type="button"
                    onClick={() => setStudentToCancel(selectedStudent)}
                    className="w-full py-3.5 rounded-xl border border-red-200 bg-red-50 text-red-700 font-bold flex items-center justify-center gap-2 transition hover:bg-red-100 shadow-sm"
                  >
                    <Ban size={18} />
                    <span>إلغاء خلاص الترسيم واسترجاع المبلغ</span>
                  </button>

                  <button
                    type="button"
                    onClick={closeStudent}
                    className="w-full py-3 rounded-xl border font-medium text-center transition hover:bg-slate-50"
                    style={{ borderColor: C.line, color: C.muted }}
                  >
                    العودة إلى قائمة القسم لترسيم تلميذ آخر
                  </button>
                </div>
              </div>
            );
          }

          return (
            <div className="bg-white rounded-[22px] p-6 border" style={{ borderColor: C.line }}>
              <h3 className="font-bold text-lg mb-4 flex items-center gap-2" style={{ color: C.ink }}>
                <GraduationCap size={20} />
                {alreadyEnrolled ? 'قبض معلوم الترسيم — الترسيم قائم' : 'تجديد الترسيم — السنة الدراسية النشطة'}
              </h3>

          <div className="space-y-4">
            {!alreadyEnrolled && (
              <div>
                <label htmlFor="section_id" className="block text-sm font-medium mb-1.5" style={{ color: C.muted }}>
                  القسم <span style={{ color: C.danger }}>*</span>
                </label>

                <select
                  id="section_id"
                  name="section_id"
                  value={sectionId}
                  onChange={(e) => { setSectionId(e.target.value); setError(''); }}
                  disabled={sectionsLoading || sections.length === 0}
                  aria-invalid={showSectionError}
                  aria-describedby={showSectionError ? 'section_id_error' : 'section_id_hint'}
                  className="w-full p-3 rounded-xl border bg-slate-50 outline-none disabled:opacity-60"
                  style={{ borderColor: showSectionError ? C.danger : C.line }}
                >
                  <option value="">
                    {sectionsLoading ? 'جارٍ تحميل الأقسام…' : 'اختر القسم'}
                  </option>
                  {sections.map((section) => (
                    <option key={section.id} value={section.id}>{section.label}</option>
                  ))}
                </select>

                {showSectionError ? (
                  <p id="section_id_error" className="mt-1.5 flex items-center gap-1.5 text-sm" style={{ color: C.danger }}>
                    <AlertCircle size={15} />
                    {sectionError}
                  </p>
                ) : (
                  <p id="section_id_hint" className="mt-1.5 text-xs" style={{ color: C.muted }}>
                    المستوى يُحدّد تلقائياً من القسم المختار.
                  </p>
                )}

                {sectionsError && (
                  <div className="mt-2 flex items-center justify-between gap-3 rounded-xl border px-3 py-2 text-sm"
                       style={{ borderColor: C.danger, backgroundColor: C.dangerBg, color: C.danger }}>
                    <span>{sectionsError}</span>
                    <button
                      type="button"
                      onClick={loadSections}
                      className="shrink-0 rounded-lg px-3 py-1 text-white"
                      style={{ backgroundColor: C.danger }}
                    >
                      إعادة المحاولة
                    </button>
                  </div>
                )}
              </div>
            )}

            <div className={alreadyEnrolled ? '' : 'pt-4 border-t'} style={{ borderColor: C.line }}>
              <h4 className="font-medium mb-1 flex items-center gap-2" style={{ color: C.ink }}>
                <CreditCard size={18} />
                معلوم التجديد المقبوض
              </h4>
              <p id="reenroll_payment_hint" className="mb-3 text-xs" style={{ color: C.muted }}>
                {alreadyEnrolled
                  ? 'ترسيم التلميذ قائم في السنة النشطة، ولن يُمسّ. المبلغ الذي تدخله هنا يُسجّل على ذلك الترسيم ويدخل الخزينة تحت «معاليم التسجيل».'
                  : 'اختياري: إن قبضت مبلغاً الآن سجّله هنا فيدخل الخزينة مباشرة تحت «معاليم التسجيل» ويظهر في السجل اليومي والشهري والدخل الصافي. اتركه فارغاً إن كان الدفع لاحقاً.'}
              </p>

              <div className="mb-4">
                <EnrollmentFeeItemsSelector
                  onTotalChange={(tot, items) => {
                    setAmount(tot > 0 ? String(tot) : '');
                    setFeeItems(items);
                    setError('');
                  }}
                />
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label htmlFor="reenroll_amount" className="block text-sm mb-1.5" style={{ color: C.muted }}>المبلغ الإجمالي المقبوض</label>
                  <input
                    id="reenroll_amount"
                    name="reenroll_amount"
                    type="number"
                    inputMode="decimal"
                    step="0.01"
                    min="0"
                    autoComplete="off"
                    value={amount}
                    onChange={(e) => { setAmount(e.target.value); setError(''); }}
                    placeholder="0.00"
                    aria-describedby="reenroll_payment_hint"
                    className="w-full p-3 rounded-xl border bg-slate-50 font-bold text-slate-800 outline-none"
                    style={{ borderColor: showPaymentError ? C.danger : C.line }}
                  />
                  <p className="text-[11px] text-slate-400 mt-1">يُحسب تلقائياً من المعاليم المختارة أعلاه ويمكن تعديله.</p>
                </div>
                <div>
                  <label htmlFor="reenroll_payment_method" className="block text-sm mb-1.5" style={{ color: C.muted }}>صيغة الدفع</label>
                  <select
                    id="reenroll_payment_method"
                    name="reenroll_payment_method"
                    value={paymentMethod}
                    onChange={(e) => { setPaymentMethod(e.target.value); setError(''); }}
                    aria-describedby="reenroll_payment_hint"
                    className="w-full p-3 rounded-xl border bg-slate-50 outline-none"
                    style={{ borderColor: showPaymentError ? C.danger : C.line }}
                  >
                    <option value="">اختر صيغة الدفع</option>
                    <option value="cash">نقداً</option>
                    <option value="check">شيك</option>
                    <option value="bank_transfer">تحويل بنكي</option>
                    <option value="card">بطاقة</option>
                  </select>
                </div>
                <div>
                  <label htmlFor="reenroll_payment_date" className="block text-sm mb-1.5" style={{ color: C.muted }}>تاريخ الدفع</label>
                  <input
                    id="reenroll_payment_date"
                    name="reenroll_payment_date"
                    type="date"
                    value={paymentDate}
                    onChange={(e) => { setPaymentDate(e.target.value); setError(''); }}
                    aria-describedby="reenroll_payment_hint"
                    className="w-full p-3 rounded-xl border bg-slate-50 outline-none"
                    style={{ borderColor: showPaymentError ? C.danger : C.line }}
                  />
                </div>
                <div>
                  <label htmlFor="reenroll_payment_notes" className="block text-sm mb-1.5" style={{ color: C.muted }}>ملاحظة على الدفع</label>
                  <input
                    id="reenroll_payment_notes"
                    name="reenroll_payment_notes"
                    type="text"
                    autoComplete="off"
                    value={paymentNotes}
                    onChange={(e) => { setPaymentNotes(e.target.value); setError(''); }}
                    placeholder="مثال: دفعة أولى من معلوم الترسيم"
                    aria-describedby="reenroll_payment_hint"
                    className="w-full p-3 rounded-xl border bg-slate-50 outline-none"
                    style={{ borderColor: C.line }}
                  />
                </div>
              </div>

              {showPaymentError && (
                <p className="mt-2 flex items-center gap-1.5 text-sm" style={{ color: C.danger }}>
                  <AlertCircle size={15} />
                  {paymentError}
                </p>
              )}
            </div>

            {alreadyEnrolled ? (
              <div className="mt-6 space-y-3">
                <button
                  onClick={handleRecordPaymentOnly}
                  disabled={saving}
                  className="w-full py-3.5 rounded-xl text-white font-medium transition hover:opacity-90 disabled:opacity-70"
                  style={{ backgroundColor: C.forest }}
                >
                  {saving ? 'جارٍ الحفظ…' : 'تسجيل المعلوم على الترسيم القائم'}
                </button>
                <button
                  type="button"
                  onClick={closeStudent}
                  className="w-full py-3 rounded-xl border font-medium"
                  style={{ borderColor: C.line, color: C.muted }}
                >
                  إلغاء والعودة إلى القائمة
                </button>
              </div>
            ) : (
              <button
                onClick={handleSave}
                disabled={saving || sectionsLoading}
                className="w-full mt-6 py-3.5 rounded-xl text-white font-medium transition hover:opacity-90 disabled:opacity-70"
                style={{ backgroundColor: C.forest }}
              >
                {saving ? 'جارٍ الحفظ…' : hasAmount ? 'حفظ الترسيم وتسجيل المبلغ' : 'حفظ الترسيم'}
              </button>
            )}
          </div>
        </div>
          );
        })()}
      </div>
    );
  }

  return (
    <div className="p-6 md:p-8" dir="rtl">
      <div className="flex items-center gap-4 mb-8">
        <Link
          to="/students/enroll"
          className="flex h-10 w-10 items-center justify-center rounded-full bg-white border"
          style={{ borderColor: C.line, color: C.muted }}
        >
          <ArrowRight size={18} />
        </Link>
        <div>
          <h1 className="text-2xl font-bold" style={{ color: C.ink }}>
            ترسيم تلميذ قديم
          </h1>
          <p className="text-sm" style={{ color: C.muted }}>
            ابحث عن التلميذ بالقسم أو بالاسم لتجديد ترسيمه
          </p>
        </div>
      </div>

      {success && (
        <div
          className="mb-5 flex items-start gap-2 rounded-xl border px-4 py-3 text-sm"
          style={{ borderColor: C.forest, backgroundColor: C.sage, color: C.forest }}
        >
          <CheckCircle size={18} className="mt-0.5 shrink-0" />
          <span>{success}</span>
        </div>
      )}

      <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label htmlFor="search_section" className="mb-1.5 block text-sm font-medium" style={{ color: C.muted }}>
            القسم
          </label>
          <select
            id="search_section"
            name="search_section"
            value={sectionFilter}
            onChange={(e) => setSectionFilter(e.target.value)}
            disabled={sectionsLoading || sections.length === 0}
            className="w-full rounded-xl border bg-white p-3.5 outline-none disabled:opacity-60"
            style={{ borderColor: C.line }}
          >
            <option value="">
              {sectionsLoading ? 'جارٍ تحميل الأقسام…' : 'كل الأقسام'}
            </option>
            {sections.map((section) => (
              <option key={section.id} value={section.id}>{section.label}</option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="search_student" className="mb-1.5 block text-sm font-medium" style={{ color: C.muted }}>
            الاسم أو رقم التلميذ
          </label>
          <div className="relative">
            <Search className="absolute right-4 top-1/2 -translate-y-1/2" size={18} style={{ color: C.muted }} />
            <input
              id="search_student"
              name="search_student"
              type="text"
              autoComplete="off"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="ابحث بالاسم أو رقم التلميذ..."
              className="w-full rounded-xl border bg-white py-3.5 pr-12 pl-4 outline-none"
              style={{ borderColor: C.line }}
            />
          </div>
        </div>
      </div>

      <div className="bg-white rounded-[22px] border overflow-hidden" style={{ borderColor: C.line }}>
        <div className="flex items-center justify-between border-b px-5 py-3 text-sm" style={{ borderColor: C.line, color: C.muted }}>
          <span>نتائج البحث</span>
          {!loading && <span>{filtered.length} تلميذ</span>}
        </div>
        {loading ? (
          <ListSkeleton />
        ) : filtered.length === 0 ? (
          <div className="p-10 text-center" style={{ color: C.muted }}>لا يوجد تلاميذ مطابقون للبحث</div>
        ) : (
          <div className="divide-y" style={{ borderColor: C.line }}>
            {filtered.map((student) => {
              const status = getStudentStatus(student);
              const isPrinting = printingStudentId === student.id;

              return (
                <div
                  key={student.id}
                  onClick={() => openStudent(student)}
                  className="w-full flex items-center justify-between gap-4 p-4 text-right hover:bg-[#FAFBF8] transition cursor-pointer"
                >
                  <div className="flex items-center gap-4 flex-1 min-w-0">
                    <div
                      className="w-11 h-11 rounded-full flex items-center justify-center text-white font-semibold shrink-0"
                      style={{ backgroundColor: status.isEnrolled ? (status.isPaid ? '#2A7A4C' : '#D97706') : C.forest }}
                    >
                      {(student.first_name || '؟')[0]}
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <p className="font-medium text-base truncate" style={{ color: C.ink }}>
                          {student.first_name} {student.last_name}
                        </p>
                        {status.isEnrolled && (
                          <span
                            className="inline-flex items-center gap-1 text-xs font-bold px-2.5 py-0.5 rounded-full shrink-0"
                            style={{
                              backgroundColor: status.isPaid ? '#E8F5E9' : '#FFFBEB',
                              color: status.isPaid ? '#1E6338' : '#B45309',
                              border: `1px solid ${status.isPaid ? '#C8E6C9' : '#FDE68A'}`,
                            }}
                          >
                            <CheckCircle size={12} />
                            {status.isPaid ? 'مُرسَّم — خالص' : 'مُرسَّم — غير خالص'}
                          </span>
                        )}
                      </div>
                      <p className="text-sm truncate mt-0.5" style={{ color: C.muted }}>
                        {student.student_code || `ID: ${student.id}`}
                        {status.sectionName ? ` — القسم: ${status.levelName ? status.levelName + ' ' : ''}${status.sectionName}` : ''}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2 shrink-0">
                    {status.isEnrolled && (
                      <>
                        <button
                          type="button"
                          onClick={(e) => handlePrintReceipt(student, e)}
                          disabled={isPrinting}
                          className="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl border transition shadow-sm hover:bg-emerald-50 disabled:opacity-50"
                          style={{
                            borderColor: '#A3D9B5',
                            backgroundColor: '#F0F9F4',
                            color: '#1E6338',
                          }}
                          title="طباعة وصل الترسيم"
                        >
                          {isPrinting ? (
                            <Loader2 size={14} className="animate-spin" />
                          ) : (
                            <Printer size={14} />
                          )}
                          <span>طباعة الوصل</span>
                        </button>

                        <button
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation();
                            setStudentToCancel(student);
                          }}
                          className="inline-flex items-center gap-1 text-xs font-bold px-2.5 py-2 rounded-xl border border-red-200 bg-red-50 text-red-700 transition hover:bg-red-100 shadow-sm"
                          title="إلغاء خلاص الترسيم واسترجاع المبلغ"
                        >
                          <Ban size={14} />
                          <span>إلغاء الخلاص</span>
                        </button>
                      </>
                    )}

                    {!status.isEnrolled ? (
                      <span
                        className="text-xs px-3.5 py-1.5 rounded-full font-bold shadow-sm"
                        style={{ backgroundColor: C.sage, color: C.forest }}
                      >
                        تجديد الترسيم
                      </span>
                    ) : !status.isPaid ? (
                      <span
                        className="text-xs px-3 py-1.5 rounded-full font-semibold border"
                        style={{ backgroundColor: '#FFFBEB', color: '#B45309', borderColor: '#FDE68A' }}
                      >
                        تسجيل الخلاص
                      </span>
                    ) : null}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {createdReceipt && (
        <ReceiptModal
          receipt={createdReceipt}
          onClose={() => setCreatedReceipt(null)}
        />
      )}

      {studentToCancel && (
        <CancelReasonModal
          title={`إلغاء خلاص معلوم ترسيم التلميذ: ${studentToCancel.first_name || ''} ${studentToCancel.last_name || ''}`}
          description="سيتم إلغاء عملية الدفع واسترجاع معلوم الترسيم من الخزينة المركزية، مع إبقاء التلميذ في قسمه وتحويل وضعه إلى غير خالص."
          busy={cancelling}
          onConfirm={handleConfirmCancel}
          onClose={() => setStudentToCancel(null)}
        />
      )}
    </div>
  );
}
