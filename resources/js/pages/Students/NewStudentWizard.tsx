import React, { useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChevronRight, ChevronLeft, CheckCircle2, User, Users, CreditCard, UserPlus, AlertCircle } from 'lucide-react';
import { enrollStudent } from '../../api/students';
import { EMPTY_FORM, type WizardFormData } from './NewStudent/types';
import { StudentStep } from './NewStudent/StudentStep';
import { GuardianStep } from './NewStudent/GuardianStep';
import { PaymentStep } from './NewStudent/PaymentStep';
import { ReceiptModal, type ReceiptData } from '../Payments/ReceiptModal';

const STEPS = [
  { num: 1, title: 'بيانات التلميذ', icon: User },
  { num: 2, title: 'بيانات الولي', icon: Users },
  { num: 3, title: 'الدفع', icon: CreditCard },
];

export function NewStudentWizard() {
  const navigate = useNavigate();
  const formRef = useRef<HTMLFormElement>(null);

  const [step, setStep] = useState(1);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [createdReceipt, setCreatedReceipt] = useState<ReceiptData | null>(null);
  const [error, setError] = useState('');
  const [validationError, setValidationError] = useState('');
  const [showStepErrors, setShowStepErrors] = useState(false);
  const [photoPreview, setPhotoPreview] = useState<string | null>(null);
  const [photoFile, setPhotoFile] = useState<File | null>(null);
  const [formData, setFormData] = useState<WizardFormData>(EMPTY_FORM);

  const handleChange = (
    e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>,
  ) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
    if (validationError) setValidationError('');
  };

  const setField = (name: keyof WizardFormData, value: string) => {
    setFormData((prev) => ({ ...prev, [name]: value }));
    if (validationError) setValidationError('');
  };

  const handlePhotoChange = (file: File | null) => {
    setPhotoFile(file);
    setPhotoPreview(file ? URL.createObjectURL(file) : null);
  };

  /** يعيد رسالة الخطأ للخطوة المطلوبة، أو سلسلة فارغة إن كانت مكتملة. */
  const stepError = (target: number): string => {
    if (target === 1) {
      if (!formData.first_name.trim() || !formData.last_name.trim() || !formData.dob || !formData.gender) {
        return 'الرجاء إكمال جميع الحقول الإجبارية (*) للمتابعة';
      }
      if (!formData.section_id) {
        return 'القسم إجباري: اختر قسم التلميذ لهذه السنة الدراسية.';
      }
      return '';
    }
    if (target === 2) {
      if (
        !formData.guardian_first_name.trim() ||
        !formData.guardian_last_name.trim() ||
        !formData.guardian_phone.trim() ||
        !formData.address.trim()
      ) {
        return 'الرجاء إكمال جميع الحقول الإجبارية للولي للمتابعة';
      }
      return '';
    }
    if (!formData.payment_method || !formData.registration_amount || !formData.payment_date) {
      return 'الرجاء إكمال حقول الدفع الإجبارية';
    }
    return '';
  };

  const handleNext = () => {
    const message = stepError(step);
    if (message) {
      setShowStepErrors(true);
      setValidationError(message);
      return;
    }
    setShowStepErrors(false);
    setValidationError('');
    setStep((prev) => Math.min(prev + 1, 3));
  };

  const handlePrev = () => {
    setValidationError('');
    setStep((prev) => Math.max(prev - 1, 1));
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLFormElement>) => {
    if (e.key === 'Enter' && (e.target as HTMLElement).tagName !== 'TEXTAREA') {
      e.preventDefault();
      if (step < 3) {
        handleNext();
      }
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (step !== 3) {
      return;
    }

    for (const target of [1, 2, 3]) {
      const message = stepError(target);
      if (message) {
        setShowStepErrors(true);
        setValidationError(message);
        setStep(target);
        return;
      }
    }

    if (!formRef.current) return;

    try {
      setIsSubmitting(true);
      setError('');

      const payload = new FormData(formRef.current);
      if (photoFile) {
        payload.set('photo', photoFile);
      } else {
        payload.delete('photo');
      }

      const res = await enrollStudent(payload);

      if (res?.payment) {
        const studentName = `${formData.first_name} ${formData.last_name}`.trim();
        const guardianName = `${formData.guardian_first_name} ${formData.guardian_last_name}`.trim();
        const sectionName = res.enrollment?.section?.name || '';
        const levelName = res.enrollment?.level?.name || '';

        setCreatedReceipt({
          payment_id: res.payment.id,
          receipt_number: (res.payment as any).receipt_number || `REC-${String(res.payment.id).padStart(6, '0')}`,
          payment_date: res.payment.payment_date || formData.payment_date,
          method: res.payment.method || formData.payment_method,
          notes: (res.payment as any).notes || formData.payment_notes,
          student_name: studentName,
          guardian_name: guardianName,
          guardian_phone: formData.guardian_phone,
          section_name: `${levelName} ${sectionName}`.trim(),
          academic_year: res.enrollment?.academic_year?.name || '2026-2027',
          amount: res.payment.amount || formData.registration_amount,
          total: res.payment.amount || formData.registration_amount,
          items: (res.payment as any).items && (res.payment as any).items.length > 0
            ? (res.payment as any).items.map((i: any) => ({
                name: i.name || i.description,
                description: i.name || i.description,
                amount: i.amount,
              }))
            : [{ description: 'معلوم الترسيم', amount: formData.registration_amount }],
        });
      } else {
        navigate('/students', { replace: true });
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'حدث خطأ غير متوقع');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="p-8 max-w-4xl mx-auto" dir="rtl">
      {/* Hero سينمائي كرتوني محاسبي — صبغة مدرسية */}
      <div className="mb-6 overflow-hidden rounded-3xl border border-slate-200 shadow-sm bg-white">
        <div className="grid md:grid-cols-5 gap-0">
          <div className="md:col-span-3 p-7 flex flex-col justify-center">
            <div className="inline-flex items-center gap-2 text-[11px] font-bold tracking-widest px-3 py-1 rounded-full w-fit" style={{ background: '#E0F0EE', color: '#2a9d8f' }}>
              Complexe La Providence — التسجيل المدرسي
            </div>
            <h1 className="text-2xl md:text-[26px] font-extrabold text-slate-800 mt-3 leading-tight">
              تسجيل تلميذ جديد
            </h1>
          </div>
          <div className="md:col-span-2 relative p-4 flex items-center justify-center" style={{ background: 'linear-gradient(135deg, #F4F6F1 0%, #E0F0EE 100%)' }}>
            <svg viewBox="0 0 320 180" xmlns="http://www.w3.org/2000/svg" className="w-full h-auto max-w-[340px] drop-shadow-sm">
              <rect width="320" height="180" rx="16" fill="#fff" />
              <ellipse cx="40" cy="30" rx="22" ry="10" fill="#fff" opacity="0.9" />
              <ellipse cx="58" cy="28" rx="14" ry="8" fill="#fff" opacity="0.9" />
              <rect x="90" y="60" width="140" height="80" rx="6" fill="#1a3a5c" stroke="#2a9d8f" strokeWidth="2" />
              <rect x="95" y="65" width="130" height="12" rx="3" fill="#2a9d8f" />
              <text x="160" y="74" textAnchor="middle" fontSize="6" fontWeight="800" fill="#fff" fontFamily="Cairo,sans-serif">LA PROVIDENCE</text>
              <rect x="110" y="80" width="8" height="50" rx="3" fill="#E3EBDB" />
              <rect x="150" y="80" width="8" height="50" rx="3" fill="#E3EBDB" />
              <rect x="190" y="80" width="8" height="50" rx="3" fill="#E3EBDB" />
              <rect x="125" y="88" width="16" height="18" rx="2" fill="#c8a96e" stroke="#fff" strokeWidth="1" />
              <rect x="168" y="88" width="16" height="18" rx="2" fill="#c8a96e" stroke="#fff" strokeWidth="1" />
              <rect x="205" y="88" width="16" height="18" rx="2" fill="#c8a96e" stroke="#fff" strokeWidth="1" />
              <line x1="133" y1="88" x2="133" y2="106" stroke="#fff" strokeWidth="0.8" />
              <line x1="125" y1="97" x2="141" y2="97" stroke="#fff" strokeWidth="0.8" />
              <line x1="176" y1="88" x2="176" y2="106" stroke="#fff" strokeWidth="0.8" />
              <line x1="168" y1="97" x2="184" y2="97" stroke="#fff" strokeWidth="0.8" />
              <rect x="148" y="112" width="24" height="28" rx="3" fill="#c8a96e" stroke="#fff" strokeWidth="1" />
              <circle cx="168" cy="126" r="2" fill="#1a3a5c" />
              <polygon points="160,40 230,60 90,60" fill="#2a9d8f" stroke="#1a3a5c" strokeWidth="1.5" />
              <rect x="158" y="20" width="3" height="22" fill="#1a3a5c" />
              <rect x="161" y="22" width="18" height="10" rx="1" fill="#c8a96e" />
              <rect x="18" y="128" width="48" height="10" rx="2" fill="#2a9d8f" />
              <rect x="20" y="118" width="44" height="10" rx="2" fill="#1a3a5c" />
              <rect x="22" y="108" width="40" height="10" rx="2" fill="#c8a96e" />
              <text x="42" y="115" textAnchor="middle" fontSize="5" fill="#fff" fontWeight="700">محاسبة</text>
              <rect x="250" y="108" width="42" height="38" rx="4" fill="#fff" stroke="#2a9d8f" strokeWidth="1.5" />
              <rect x="256" y="114" width="30" height="10" rx="1" fill="#1a3a5c" />
              <circle cx="260" cy="130" r="3" fill="#E3EBDB" stroke="#2a9d8f" strokeWidth="0.5" />
              <circle cx="272" cy="130" r="3" fill="#E3EBDB" stroke="#2a9d8f" strokeWidth="0.5" />
              <circle cx="284" cy="130" r="3" fill="#c8a96e" />
              <circle cx="260" cy="140" r="3" fill="#E3EBDB" stroke="#2a9d8f" strokeWidth="0.5" />
              <circle cx="272" cy="140" r="3" fill="#E3EBDB" stroke="#2a9d8f" strokeWidth="0.5" />
              <circle cx="284" cy="140" r="3" fill="#2a9d8f" />
              <ellipse cx="70" cy="142" rx="12" ry="7" fill="#c8a96e" stroke="#1a3a5c" strokeWidth="1" />
              <text x="70" y="145" textAnchor="middle" fontSize="6" fontWeight="800" fill="#1a3a5c">د.ت</text>
              <ellipse cx="78" cy="135" rx="10" ry="6" fill="#fff" stroke="#c8a96e" strokeWidth="1" />
              <text x="78" y="137" textAnchor="middle" fontSize="5" fill="#c8a96e" fontWeight="700">100</text>
              <ellipse cx="160" cy="165" rx="90" ry="10" fill="#1a3a5c" opacity="0.08" />
            </svg>
          </div>
        </div>
      </div>
      <div className="mb-6 flex justify-end">
        <button
          type="button"
          onClick={() => navigate('/students')}
          className="px-5 py-2.5 text-sm font-medium text-slate-600 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors shadow-sm"
        >
          إلغاء التسجيل
        </button>
      </div>

      {error && (
        <div className="mb-6 flex items-start gap-3 bg-red-50 text-red-700 p-4 rounded-xl border border-red-200">
          <AlertCircle size={18} className="mt-0.5 shrink-0" />
          <span>{error}</span>
        </div>
      )}

      {validationError && (
        <div className="mb-6 flex items-start gap-3 bg-amber-50 text-amber-700 p-4 rounded-xl border border-amber-200 font-medium">
          <AlertCircle size={18} className="mt-0.5 shrink-0" />
          <span>{validationError}</span>
        </div>
      )}

      <div className="mb-8 bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <div className="relative flex justify-between">
          <div className="absolute top-1/2 left-0 w-full h-1 bg-slate-100 -translate-y-1/2 rounded-full z-0" />
          <div
            className="absolute top-1/2 right-0 h-1 bg-blue-600 -translate-y-1/2 rounded-full z-0 transition-all duration-500 ease-in-out"
            style={{ width: `${((step - 1) / (STEPS.length - 1)) * 100}%` }}
          />

          {STEPS.map((s) => {
            const Icon = s.icon;
            const isCompleted = step > s.num;
            const isCurrent = step === s.num;

            return (
              <div key={s.num} className="relative z-10 flex flex-col items-center gap-3">
                <div
                  className={`w-14 h-14 rounded-2xl flex items-center justify-center transition-all duration-300 shadow-sm ${
                    isCompleted
                      ? 'bg-blue-600 text-white shadow-blue-200'
                      : isCurrent
                        ? 'bg-white text-blue-600 border-2 border-blue-600 shadow-blue-100'
                        : 'bg-slate-50 text-slate-400 border border-slate-200'
                  }`}
                >
                  {isCompleted ? <CheckCircle2 size={24} /> : <Icon size={24} />}
                  {isCurrent && (
                    <span className="absolute -top-2 -right-2 w-6 h-6 bg-blue-100 text-blue-700 rounded-full flex items-center justify-center text-xs font-bold ring-4 ring-white">
                      {s.num}
                    </span>
                  )}
                </div>
                <div className="flex flex-col items-center">
                  <span
                    className={`text-sm font-bold ${isCurrent ? 'text-blue-700' : isCompleted ? 'text-slate-800' : 'text-slate-500'}`}
                  >
                    {s.title}
                  </span>
                  {isCurrent && <span className="text-xs text-blue-500 mt-0.5 font-medium">المرحلة الحالية</span>}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      <div className="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
        <form ref={formRef} onSubmit={handleSubmit} onKeyDown={handleKeyDown} className="p-8 md:p-10">
          {/* الخطوات الثلاث تبقى مركّبة دائماً: FormData تقرأ الحقول من DOM لا من الحالة. */}
          <div className={step === 1 ? 'block' : 'hidden'}>
            <StudentStep
              data={formData}
              onChange={handleChange}
              setField={setField}
              photoPreview={photoPreview}
              onPhotoChange={handlePhotoChange}
              showErrors={showStepErrors && step === 1}
            />
          </div>

          <div className={step === 2 ? 'block' : 'hidden'}>
            <GuardianStep data={formData} onChange={handleChange} />
          </div>

          <div className={step === 3 ? 'block' : 'hidden'}>
            <PaymentStep data={formData} onChange={handleChange} setField={setField} />
          </div>

          <div className="mt-8 pt-6 border-t border-slate-100 flex items-center justify-between">
            <button
              key="btn-wizard-prev"
              type="button"
              onClick={handlePrev}
              disabled={step === 1 || isSubmitting}
              className="flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-xl hover:bg-slate-200 disabled:opacity-50 transition-colors cursor-pointer"
            >
              <ChevronRight size={18} />
              السابق
            </button>

            {step < 3 && (
              <button
                key="btn-wizard-next"
                type="button"
                onClick={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  handleNext();
                }}
                className="flex items-center gap-2 px-6 py-2.5 text-sm font-bold text-white bg-emerald-700 hover:bg-emerald-800 rounded-xl transition-colors cursor-pointer shadow-sm"
              >
                التالي
                <ChevronLeft size={18} />
              </button>
            )}

            {step === 3 && (
              <button
                key="btn-wizard-submit"
                type="submit"
                disabled={isSubmitting}
                className="flex items-center gap-2 px-8 py-2.5 text-sm font-bold text-white bg-emerald-700 hover:bg-emerald-800 rounded-xl disabled:opacity-70 transition-colors shadow-sm cursor-pointer"
              >
                {isSubmitting ? 'جارٍ حفظ الترسيم…' : 'تأكيد وحفظ التسجيل'}
                {!isSubmitting && <CheckCircle2 size={18} />}
              </button>
            )}
          </div>
        </form>
      </div>

      {createdReceipt && (
        <ReceiptModal
          receipt={createdReceipt}
          onClose={() => navigate('/students', { replace: true })}
        />
      )}
    </div>
  );
}

export default NewStudentWizard;
