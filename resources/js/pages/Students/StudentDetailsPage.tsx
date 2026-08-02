import { useEffect, useMemo, useState } from 'react';
import { AlertCircle, ArrowRight, Phone, UserRound, Wallet } from 'lucide-react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { getStudent, type Student } from '../../api/students';
import { studentFeesApi } from '../../api/payments';
import type { StudentFeesEnrollment } from '../../types';
import { PageDataSkeleton } from '../../components/DataSkeleton';

function money(value: number | string | null | undefined): string {
  return `${Number(value ?? 0).toFixed(3)} د`;
}

function feeLabel(fee: StudentFeesEnrollment['fees'][number]): string {
  return fee.description || 'رسم مسجّل';
}

export function StudentDetailsPage() {
  const { studentId } = useParams<{ studentId: string }>();
  const [searchParams] = useSearchParams();
  const [student, setStudent] = useState<Student | null>(null);
  const [feeEnrollments, setFeeEnrollments] = useState<StudentFeesEnrollment[]>([]);
  const [balance, setBalance] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!studentId) return;

    let active = true;
    setLoading(true);
    setError('');

    Promise.all([
      getStudent(Number(studentId)),
      studentFeesApi.fees(Number(studentId)),
      studentFeesApi.balance(Number(studentId)),
    ])
      .then(([studentData, feesData, balanceData]) => {
        if (!active) return;
        setStudent(studentData);
        setFeeEnrollments(feesData);
        setBalance(balanceData.balance);
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

  const unpaidFees = useMemo(
    () => feeEnrollments.flatMap((enrollment) => enrollment.fees.filter((fee) => Number(fee.remaining) > 0)),
    [feeEnrollments],
  );
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
  const backQuery = searchParams.toString() ? `?${searchParams.toString()}` : '';

  return (
    <div className="mx-auto max-w-6xl p-6 md:p-8" dir="rtl">
      <Link to={`/students/search${backQuery}`} className="mb-5 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#3B4A36]">
        <ArrowRight size={16} />
        <span>رجوع إلى البحث</span>
      </Link>

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
              </div>

              <div className="mb-5 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div className="rounded-2xl bg-[#E3EBDB] p-5">
                  <div className="mb-3 flex items-center gap-2 text-[#3B4A36]"><UserRound size={18} /><span className="text-sm font-semibold">الولي / الأب</span></div>
                  <p className="font-bold text-slate-800">{guardianName}</p>
                  <p className="mt-2 flex items-center gap-2 text-sm text-slate-600" dir="ltr"><Phone size={15} />{guardianPhone}</p>
                </div>
                <div className="rounded-2xl bg-[#EFEAE0] p-5">
                  <div className="mb-3 flex items-center gap-2 text-[#7C6B42]"><Wallet size={18} /><span className="text-sm font-semibold">الرصيد المستحق</span></div>
                  <p className="text-2xl font-extrabold text-slate-800">{money(balance)}</p>
                  <p className="mt-2 text-xs text-slate-500">حسب رسوم التلميذ المسجّلة</p>
                </div>
                <div className="rounded-2xl bg-[#F1E4E2] p-5">
                  <div className="mb-3 flex items-center gap-2 text-[#A03434]"><AlertCircle size={18} /><span className="text-sm font-semibold">الأقساط الشهرية غير المسددة</span></div>
                  <p className="text-2xl font-extrabold text-slate-800">{unpaidMonthlyFees.length}</p>
                  <p className="mt-2 text-xs text-slate-500">المتبقي: {money(monthlyRemaining)}</p>
                </div>
              </div>

              <div className="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="border-b border-slate-100 bg-[#E3EBDB] px-5 py-4">
                  <h2 className="font-bold text-slate-800">ملخص الرسوم والدفعات</h2>
                  <p className="mt-1 text-xs text-slate-500">المبالغ التالية قادمة من الرسوم وتوزيعات الدفعات المحفوظة.</p>
                </div>
                {feeEnrollments.length === 0 ? (
                  <p className="p-6 text-center text-sm text-slate-500">لا توجد رسوم مسجّلة لهذا التلميذ.</p>
                ) : (
                  <div className="divide-y divide-slate-100">
                    {feeEnrollments.map((enrollment) => (
                      <div key={enrollment.enrollment_id} className="p-5">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                          <h3 className="font-semibold text-slate-800">{enrollment.academic_year?.name || 'سنة غير محددة'} — {enrollment.level?.name || 'مستوى غير محدد'}</h3>
                          <span className="text-xs text-slate-500">{enrollment.status}</span>
                        </div>
                        <div className="overflow-x-auto">
                          <table className="w-full text-right text-sm">
                            <thead className="text-xs text-slate-500"><tr><th className="px-3 py-2 font-medium">الرسم / القسط</th><th className="px-3 py-2 font-medium">الاستحقاق</th><th className="px-3 py-2 font-medium">المطلوب</th><th className="px-3 py-2 font-medium">المدفوع</th><th className="px-3 py-2 font-medium">المتبقي</th></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                              {enrollment.fees.map((fee) => (
                                <tr key={fee.id}>
                                  <td className="px-3 py-2 text-slate-800">{feeLabel(fee)}</td>
                                  <td className="px-3 py-2 text-slate-600">{fee.due_date || '—'}</td>
                                  <td className="px-3 py-2 text-slate-600">{money(fee.amount_due)}</td>
                                  <td className="px-3 py-2 text-[#3B4A36]">{money(fee.allocated)}</td>
                                  <td className="px-3 py-2 font-semibold text-[#A03434]">{money(fee.remaining)}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                معاليم الأندية غير متاحة بعد لأن اشتراكات الأندية الحالية لا ترتبط بجدول رسوم أو دفعات مستحقة يمكن حساب المتبقي منه بأمان.
              </div>
            </>
          )}
        </>
      )}
    </div>
  );
}
