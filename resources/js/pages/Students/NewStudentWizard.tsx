import React, { useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChevronRight, ChevronLeft, CheckCircle2, User, Users, CreditCard, UserPlus, AlertCircle } from 'lucide-react';
import { enrollStudent } from '../../api/students';
import { EMPTY_FORM, type WizardFormData } from './NewStudent/types';
import { StudentStep } from './NewStudent/StudentStep';
import { GuardianStep } from './NewStudent/GuardianStep';
import { PaymentStep } from './NewStudent/PaymentStep';

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

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (step < 3) {
      handleNext();
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

      await enrollStudent(payload);
      navigate('/students', { replace: true });
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'حدث خطأ غير متوقع');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="p-8 max-w-4xl mx-auto" dir="rtl">
      <div className="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center">
            <UserPlus size={24} strokeWidth={2} />
          </div>
          <div>
            <h1 className="text-2xl font-bold text-slate-800">تسجيل تلميذ جديد</h1>
            <p className="text-slate-500 text-sm mt-1">بيانات التلميذ والولي ومعلوم الترسيم في ثلاث خطوات</p>
          </div>
        </div>
        <button
          type="button"
          onClick={() => navigate('/students')}
          className="px-5 py-2.5 text-sm font-medium text-slate-600 bg-slate-50 border border-slate-200 rounded-xl hover:bg-slate-100 transition-colors"
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
        <form ref={formRef} onSubmit={handleSubmit} className="p-8 md:p-10">
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
              type="button"
              onClick={handlePrev}
              disabled={step === 1 || isSubmitting}
              className="flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50 transition-colors"
            >
              <ChevronRight size={18} />
              السابق
            </button>

            {step < 3 ? (
              <button
                type="button"
                onClick={handleNext}
                className="flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors"
              >
                التالي
                <ChevronLeft size={18} />
              </button>
            ) : (
              <button
                type="submit"
                disabled={isSubmitting}
                className="flex items-center gap-2 px-8 py-2.5 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 disabled:opacity-70 transition-colors"
              >
                {isSubmitting ? 'جارٍ الحفظ…' : 'حفظ نهائي'}
                {!isSubmitting && <CheckCircle2 size={18} />}
              </button>
            )}
          </div>
        </form>
      </div>
    </div>
  );
}

export default NewStudentWizard;
