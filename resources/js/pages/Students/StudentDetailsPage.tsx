import { useEffect, useMemo, useState } from 'react';
import { AlertCircle, ArrowRight, CheckCircle2, Pencil, Phone, Printer, UserRound, Wallet, X } from 'lucide-react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { getStudent, getStudentPaymentHistory, updateStudentGender, type Student, type StudentPaymentHistoryEntry } from '../../api/students';
import { studentFeesApi } from '../../api/payments';
import type { StudentFeesEnrollment } from '../../types';
import { PageDataSkeleton } from '../../components/DataSkeleton';
import { EnrollmentDiscountCard } from './EnrollmentDiscountCard';
import { StudentExemptionsTab } from '../../components/Exemptions/StudentExemptionsTab';
import { fetchClubSubscriptions, type ClubSubscriptionItem } from '../../api/clubs';

function money(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return 'غير متاح';
  return `${Number(value).toFixed(3)} د`;
}

function feeLabel(fee: StudentFeesEnrollment['fees'][number]): string {
  return fee.description || 'رسم مسجّل';
}

function studentStatusLabel(status: string | null | undefined): string {
  if (status === 'active') return 'نشط';
  if (status === 'inactive') return 'غير نشط';
  if (status === 'transferred') return 'منقول';
  return status || 'غير محدد';
}

export function StudentDetailsPage() {
  const { studentId } = useParams<{ studentId: string }>();
  const [searchParams] = useSearchParams();
  const [student, setStudent] = useState<Student | null>(null);
  const [feeEnrollments, setFeeEnrollments] = useState<StudentFeesEnrollment[]>([]);
  const [payments, setPayments] = useState<StudentPaymentHistoryEntry[]>([]);
  const [clubSubscriptions, setClubSubscriptions] = useState<ClubSubscriptionItem[]>([]);
  const [balance, setBalance] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [activeTab, setActiveTab] = useState<'overview' | 'exemptions'>('overview');

  // Gender update state
  const [genderEditing, setGenderEditing] = useState(false);
  const [genderSaving, setGenderSaving] = useState(false);
  const [genderError, setGenderError] = useState('');
  const [genderSelected, setGenderSelected] = useState<'male' | 'female' | ''>('');

  useEffect(() => {
    if (!studentId) return;

    let active = true;
    setLoading(true);
    setError('');

    Promise.all([
      getStudent(Number(studentId)),
      studentFeesApi.fees(Number(studentId)),
      studentFeesApi.balance(Number(studentId)),
      getStudentPaymentHistory(Number(studentId)),
      fetchClubSubscriptions({ student_id: Number(studentId) }).catch(() => ({ data: [] })),
    ])
      .then(([studentData, feesData, balanceData, paymentData, subsData]) => {
        if (!active) return;
        setStudent(studentData);
        setFeeEnrollments(feesData);
        setBalance(balanceData.balance);
        setPayments(paymentData);
        const list = (subsData && 'data' in subsData && Array.isArray(subsData.data))
          ? subsData.data
          : Array.isArray(subsData)
            ? subsData
            : [];
        setClubSubscriptions(list);
      })
      .catch((requestError) => {
        if (active) setError(requestError instanceof Error ? requestError.message : 'تعذّر تحميل تفاصيل التلميذ');
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [studentId]);

  const allFees = useMemo(() => feeEnrollments.flatMap((enrollment) => enrollment.fees), [feeEnrollments]);
  const unpaidFees = useMemo(() => allFees.filter((fee) => Number(fee.remaining) > 0), [allFees]);
  const unpaidMonthlyFees = useMemo(
    () => unpaidFees.filter((fee) => fee.frequency === 'monthly' || fee.category === 'monthly_fee'),
    [unpaidFees],
  );
  const monthlyRemaining = useMemo(
    () => unpaidMonthlyFees.reduce((total, fee) => total + Number(fee.remaining || 0), 0),
    [unpaidMonthlyFees],
  );
  const guardian = student?.guardians?.[0];
  const guardianName = guardian
    ? `${guardian.first_name} ${guardian.last_name}`.trim()
    : [student?.guardian_first_name, student?.guardian_last_name].filter(Boolean).join(' ') || 'غير مسجّل';
  const guardianPhone = guardian?.phone || student?.guardian_phone || student?.mother_phone || 'غير مسجّل';
  const enrollment = student?.enrollments?.[0];
  const backQuery = searchParams.toString() ? `?${searchParams.toString()}` : '';

  function genderLabel(g: string | null | undefined): string {
    if (g === 'male') return 'ذكر';
    if (g === 'female') return 'أنثى';
    return 'غير محدد';
  }

  async function handleGenderSave() {
    if (!student || !genderSelected) {
      setGenderError('اختر قيمة الجنس قبل الحفظ.');
      return;
    }
    setGenderSaving(true);
    setGenderError('');
    try {
      const updated = await updateStudentGender(student.id, genderSelected);
      setStudent((prev) => prev ? { ...prev, gender: updated.gender } : prev);
      setGenderEditing(false);
      setGenderSelected('');
    } catch (err) {
      setGenderError(err instanceof Error ? err.message : 'تعذّر حفظ الجنس');
    } finally {
      setGenderSaving(false);
    }
  }

  function handleGenderEditOpen() {
    setGenderSelected(
      student?.gender === 'male' ? 'male' : student?.gender === 'female' ? 'female' : ''
    );
    setGenderError('');
    setGenderEditing(true);
  }

  return (
    <div className="student-print-profile mx-auto max-w-6xl p-6 md:p-8" dir="rtl">
      <style>{`
        @media print {
          body * { visibility: hidden !important; }
          .student-print-profile, .student-print-profile * { visibility: visible !important; }
          .student-print-profile { position: absolute !important; inset: 0 !important; width: 100% !important; max-width: none !important; padding: 0 !important; }
          .student-print-profile button, .student-print-profile a, .student-print-profile input, .student-print-profile select, .student-print-profile textarea { display: none !important; }
        }
      `}</style>

      <div className="mb-5 flex flex-wrap items-center justify-between gap-3 print:hidden">
        <Link to={`/students/search${backQuery}`} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#3B4A36]">
          <ArrowRight size={16} />
          <span>رجوع إلى البحث</span>
        </Link>
        <button type="button" onClick={() => window.print()} disabled={loading || !student} className="inline-flex items-center gap-2 rounded-xl bg-[#3B4A36] px-4 py-2 text-sm font-semibold text-white hover:bg-[#2E3B2A] disabled:opacity-50">
          <Printer size={16} />
          <span>طباعة الملف</span>
        </button>
      </div>

      {loading ? <PageDataSkeleton cards={3} rows={5} /> : (
        <>
          {error && <div className="mb-5 flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"><AlertCircle size={18} />{error}</div>}

          {student && (
            <>
              <div className="mb-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-center gap-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#E3EBDB] text-[#3B4A36]"><UserRound size={22} /></div>
                  <div>
                    <h1 className="text-2xl font-bold text-slate-800">{student.first_name} {student.last_name}</h1>
                    <p className="mt-1 text-sm text-slate-500" dir="ltr">{student.student_code || '—'}</p>
                  </div>
                </div>
                <div className="mt-5 grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 text-sm text-slate-600 sm:grid-cols-2 lg:grid-cols-4">
                  <p className="flex items-center gap-1.5 flex-wrap">
                    <span className="font-semibold text-slate-800">الجنس:</span>
                    {!genderEditing ? (
                      <>
                        <span className={student.gender === 'female' ? 'text-[#A46E67] font-medium' : student.gender === 'male' ? 'text-[#8A7C57] font-medium' : 'text-slate-400 italic'}>{genderLabel(student.gender)}</span>
                        <button
                          type="button"
                          onClick={handleGenderEditOpen}
                          title="تعديل الجنس"
                          className="print:hidden inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-500 hover:border-[#3B4A36] hover:text-[#3B4A36] transition"
                        >
                          <Pencil size={12} />
                          تعديل
                        </button>
                      </>
                    ) : (
                      <span className="print:hidden inline-flex flex-col gap-1 mt-1">
                        <span className="inline-flex items-center gap-2">
                          <select
                            id="gender_edit_select"
                            value={genderSelected}
                            onChange={(e) => { setGenderSelected(e.target.value as 'male' | 'female' | ''); setGenderError(''); }}
                            disabled={genderSaving}
                            className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm outline-none focus:border-[#3B4A36] focus:ring-1 focus:ring-[#3B4A36]/20"
                          >
                            <option value="">اختر الجنس…</option>
                            <option value="male">ذكر</option>
                            <option value="female">أنثى</option>
                          </select>
                          <button
                            type="button"
                            onClick={handleGenderSave}
                            disabled={genderSaving || !genderSelected}
                            className="inline-flex items-center gap-1 rounded-lg bg-[#3B4A36] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#2E3B2A] disabled:opacity-50 transition"
                          >
                            <CheckCircle2 size={13} />
                            {genderSaving ? 'جارٍ الحفظ…' : 'حفظ'}
                          </button>
                          <button
                            type="button"
                            onClick={() => { setGenderEditing(false); setGenderError(''); setGenderSelected(''); }}
                            disabled={genderSaving}
                            className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-500 hover:border-red-300 hover:text-red-600 transition"
                          >
                            <X size={13} />
                            إلغاء
                          </button>
                        </span>
                        {genderError && <span className="text-xs text-red-600 flex items-center gap-1"><AlertCircle size={12} />{genderError}</span>}
                      </span>
                    )}
                  </p>
                  <p><span className="font-semibold text-slate-800">تاريخ الولادة:</span> {student.dob || 'غير مسجّل'}</p>
                  <p><span className="font-semibold text-slate-800">القسم:</span> {[enrollment?.level?.name, enrollment?.section?.name].filter(Boolean).join(' ') || 'غير مسجّل'}</p>
                  <p><span className="font-semibold text-slate-800">الحالة:</span> {studentStatusLabel(student.status || enrollment?.status)}</p>
                </div>
              </div>

              {/* Tab Navigation */}
              <div className="mb-6 flex items-center gap-2 border-b border-slate-200 print:hidden">
                <button
                  type="button"
                  onClick={() => setActiveTab('overview')}
                  className={`px-5 py-3 text-sm font-bold border-b-2 transition -mb-px ${
                    activeTab === 'overview'
                      ? 'border-[#3B4A36] text-[#3B4A36]'
                      : 'border-transparent text-slate-500 hover:text-slate-800'
                  }`}
                >
                  البيانات والرسوم والدفعات
                </button>
                <button
                  type="button"
                  onClick={() => setActiveTab('exemptions')}
                  className={`px-5 py-3 text-sm font-bold border-b-2 transition -mb-px flex items-center gap-2 ${
                    activeTab === 'exemptions'
                      ? 'border-[#3B4A36] text-[#3B4A36]'
                      : 'border-transparent text-slate-500 hover:text-slate-800'
                  }`}
                >
                  <span>الإعفاءات والتخفيضات الشهرية</span>
                </button>
              </div>

              {activeTab === 'exemptions' ? (
                <div className="mb-6">
                  <StudentExemptionsTab
                    enrollmentId={enrollment?.id || feeEnrollments[0]?.enrollment_id || 0}
                    studentName={`${student.first_name} ${student.last_name}`}
                    clubSubscriptions={clubSubscriptions}
                  />
                </div>
              ) : (
                <>
                  <div className="mb-5 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div className="rounded-2xl bg-[#E3EBDB] p-5">
                      <div className="mb-3 flex items-center gap-2 text-[#3B4A36]"><UserRound size={18} /><span className="text-sm font-semibold">الولي / الأب</span></div>
                      <p className="font-bold text-slate-800">{guardianName}</p>
                      <p className="mt-2 flex items-center gap-2 text-sm text-slate-600" dir="ltr"><Phone size={15} />{guardianPhone}</p>
                      <p className="mt-2 text-sm text-slate-600">الأم: {student.mother_name || 'غير مسجّل'}</p>
                    </div>
                    <div className="rounded-2xl bg-[#EFEAE0] p-5">
                      <div className="mb-3 flex items-center gap-2 text-[#7C6B42] text-sm font-semibold"><Wallet size={18} />الرصيد المستحق</div>
                      <p className="text-2xl font-extrabold text-slate-800">{money(balance)}</p>
                      <p className="mt-2 text-xs text-slate-500">محسوب من الرسوم والتوزيعات غير الملغاة.</p>
                    </div>
                    <div className="rounded-2xl bg-[#F1E4E2] p-5">
                      <div className="mb-3 flex items-center gap-2 text-[#A03434] text-sm font-semibold"><AlertCircle size={18} />الأقساط الشهرية غير المسددة</div>
                      <p className="text-2xl font-extrabold text-slate-800">{unpaidMonthlyFees.length}</p>
                      <p className="mt-2 text-xs text-slate-500">المتبقي: {money(monthlyRemaining)}</p>
                    </div>
                  </div>

                  <div className="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 bg-[#E3EBDB] px-5 py-4">
                      <h2 className="font-bold text-slate-800">الرسوم: غير المسددة والمسددّة</h2>
                      <p className="mt-1 text-xs text-slate-500">المبالغ قادمة من الرسوم وتوزيعات الدفعات المحفوظة فعليًا.</p>
                    </div>
                    {feeEnrollments.length === 0 ? (
                      <p className="p-6 text-center text-sm text-slate-500">لا توجد رسوم مسجّلة لهذا التلميذ.</p>
                    ) : (
                      <div className="divide-y divide-slate-100">
                        {feeEnrollments.map((feeEnrollment) => (
                          <div key={feeEnrollment.enrollment_id} className="p-5">
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                              <h3 className="font-semibold text-slate-800">{feeEnrollment.academic_year?.name || 'سنة غير محددة'} — {feeEnrollment.level?.name || 'مستوى غير محدد'}</h3>
                              <span className="text-xs text-slate-500">{studentStatusLabel(feeEnrollment.status)}</span>
                            </div>
                            <div className="overflow-x-auto">
                              <table className="w-full text-right text-sm">
                                <thead className="text-xs text-slate-500"><tr><th className="px-3 py-2 font-medium">الرسم / القسط</th><th className="px-3 py-2 font-medium">الاستحقاق</th><th className="px-3 py-2 font-medium">المطلوب</th><th className="px-3 py-2 font-medium">المدفوع</th><th className="px-3 py-2 font-medium">المتبقي</th><th className="px-3 py-2 font-medium">الحالة</th></tr></thead>
                                <tbody className="divide-y divide-slate-100">
                                  {feeEnrollment.fees.map((fee) => {
                                    const unpaid = Number(fee.remaining) > 0;
                                    return (
                                      <tr key={fee.id}>
                                        <td className="px-3 py-2 text-slate-800">{feeLabel(fee)}</td>
                                        <td className="px-3 py-2 text-slate-600">{fee.due_date || '—'}</td>
                                        <td className="px-3 py-2 text-slate-600">{money(fee.amount_due)}</td>
                                        <td className="px-3 py-2 text-[#3B4A36]">{money(fee.allocated)}</td>
                                        <td className={`px-3 py-2 font-semibold ${unpaid ? 'text-[#A03434]' : 'text-[#3B4A36]'}`}>{money(fee.remaining)}</td>
                                        <td className="px-3 py-2"><span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${unpaid ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'}`}>{unpaid ? 'غير مسدد' : 'مسدد'}</span></td>
                                      </tr>
                                    );
                                  })}
                                </tbody>
                              </table>
                            </div>

                            <div className="mt-4">
                              <EnrollmentDiscountCard
                                enrollmentId={feeEnrollment.enrollment_id}
                                yearLabel={feeEnrollment.academic_year?.name}
                              />
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>

                  <div className="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 bg-[#EFEAE0] px-5 py-4">
                      <h2 className="font-bold text-slate-800">سجل الدفعات حسب الشهر</h2>
                      <p className="mt-1 text-xs text-slate-500">تاريخ التحصيل هو التاريخ المحفوظ فعليًا لكل دفعة.</p>
                    </div>
                    {payments.length === 0 ? (
                      <p className="p-6 text-center text-sm text-slate-500">لا توجد دفعات محفوظة لهذا التلميذ.</p>
                    ) : (
                      <div className="overflow-x-auto">
                        <table className="w-full text-right text-sm">
                          <thead className="border-b border-slate-100 bg-slate-50 text-xs text-slate-500"><tr><th className="px-5 py-3 font-semibold">الشهر</th><th className="px-5 py-3 font-semibold">مبلغ الدفعة</th><th className="px-5 py-3 font-semibold">تاريخ التحصيل</th><th className="px-5 py-3 font-semibold">الحالة</th></tr></thead>
                          <tbody className="divide-y divide-slate-100">
                            {payments.map((payment) => (
                              <tr key={payment.id}>
                                <td className="px-5 py-3 text-slate-800">{payment.months.length > 0 ? payment.months.join('، ') : 'غير محدد'}</td>
                                <td className="px-5 py-3 text-slate-700">{money(payment.amount)}</td>
                                <td className="px-5 py-3 text-slate-700" dir="ltr">{payment.payment_date || 'غير متاح'}</td>
                                <td className="px-5 py-3"><span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${payment.cancelled_at ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'}`}>{payment.cancelled_at ? 'ملغاة' : 'مدفوعة'}</span></td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                </>
              )}
            </>
          )}
        </>
      )}
    </div>
  );
}
