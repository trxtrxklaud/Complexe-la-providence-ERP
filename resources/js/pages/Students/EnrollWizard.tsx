import React, { useState, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { enrollStudent } from '../../api/students';
import { ChevronRight, ChevronLeft, Upload, CheckCircle2, User, Users, CreditCard, Trash2, Calendar, BookOpen, FileText, Camera, UserPlus, ChevronDown } from 'lucide-react';

export function EnrollWizard() {
  const navigate = useNavigate();
  const [step, setStep] = useState(1);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [validationError, setValidationError] = useState('');
  const [photoPreview, setPhotoPreview] = useState<string | null>(null);
  const [photoFile, setPhotoFile] = useState<File | null>(null);
  const [openDropdown, setOpenDropdown] = useState<'gender' | 'academic_year' | 'payment_method' | null>(null);
  
  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    dob: '',
    gender: '',
    academic_year_id: '',
    notes: '',
    guardian_first_name: '',
    guardian_last_name: '',
    guardian_phone: '',
    guardian_email: '',
    address: '',
    mother_phone: '',
    mother_email: '',
    payment_method: '',
    registration_amount: '',
    payment_date: '',
    payment_notes: '',
  });

  const formRef = useRef<HTMLFormElement>(null);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
    if (validationError) setValidationError('');
  };

  const handleNext = () => {
    if (step === 1) {
      if (!formData.first_name.trim() || !formData.last_name.trim() || !formData.dob || !formData.gender || !formData.academic_year_id) {
        setValidationError('الرجاء إكمال جميع الحقول الإجبارية (*) للمتابعة');
        return;
      }
    }
    if (step === 2) {
      if (!formData.guardian_first_name.trim() || !formData.guardian_last_name.trim() || !formData.guardian_phone.trim() || !formData.address.trim()) {
        setValidationError('الرجاء إكمال جميع الحقول الإجبارية للولي للمتابعة');
        return;
      }
    }
    setValidationError('');
    setStep(prev => Math.min(prev + 1, 3));
  };
  const handlePrev = () => {
    setValidationError('');
    setStep(prev => Math.max(prev - 1, 1));
  };

  const handlePhotoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      setPhotoFile(file);
      const url = URL.createObjectURL(file);
      setPhotoPreview(url);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (step < 3) {
      handleNext();
      return;
    }

    if (!formData.payment_method || !formData.registration_amount || !formData.payment_date) {
      setValidationError('الرجاء إكمال جميع الحقول الإجبارية للدفع للمتابعة');
      return;
    }

    if (!formRef.current) return;

    try {
      setIsSubmitting(true);
      setError('');
      
      const submitFormData = new FormData(formRef.current);
      if (photoFile) {
        submitFormData.set('photo', photoFile);
      } else {
        submitFormData.delete('photo');
      }
      
      console.log('Sending Registration Payload:', Object.fromEntries(submitFormData.entries()));
      
      const res = await enrollStudent(submitFormData);
      
      alert(res?.message || 'تم تسجيل التلميذ بنجاح');
      navigate('/students', { replace: true });
    } catch (err: any) {
      setError(err.message || 'حدث خطأ غير متوقع');
      console.error('Registration Error:', err);
    } finally {
      setIsSubmitting(false);
    }
  };

  const steps = [
    { num: 1, title: 'بيانات التلميذ', icon: User },
    { num: 2, title: 'بيانات الولي', icon: Users },
    { num: 3, title: 'الدفع', icon: CreditCard },
  ];

  return (
    <div className="p-8 max-w-4xl mx-auto" dir="rtl">
      
      <div className="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center">
            <UserPlus size={24} strokeWidth={2} />
          </div>
          <div>
            <h1 className="text-2xl font-bold text-slate-800">تسجيل تلميذ جديد</h1>
            <p className="text-slate-500 text-sm mt-1">إضافة بيانات التلميذ والولي وتفاصيل الدفع بخطوات بسيطة</p>
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
        <div className="mb-6 bg-red-50 text-red-700 p-4 rounded-xl border border-red-200">
          {error}
        </div>
      )}

      {validationError && (
        <div className="mb-6 bg-amber-50 text-amber-700 p-4 rounded-xl border border-amber-200 font-medium">
          {validationError}
        </div>
      )}

      {/* Timeline Stepper */}
      <div className="mb-8 bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <div className="relative flex justify-between">
          <div className="absolute top-1/2 left-0 w-full h-1 bg-slate-100 -translate-y-1/2 rounded-full z-0"></div>
          <div 
            className="absolute top-1/2 right-0 h-1 bg-blue-600 -translate-y-1/2 rounded-full z-0 transition-all duration-500 ease-in-out"
            style={{ width: `${((step - 1) / (steps.length - 1)) * 100}%` }}
          ></div>
          
          {steps.map((s) => {
            const Icon = s.icon;
            const isCompleted = step > s.num;
            const isCurrent = step === s.num;
            
            return (
              <div key={s.num} className="relative z-10 flex flex-col items-center gap-3">
                <div className={`w-14 h-14 rounded-2xl flex items-center justify-center transition-all duration-300 shadow-sm
                  ${isCompleted ? 'bg-blue-600 text-white shadow-blue-200' : 
                    isCurrent ? 'bg-white text-blue-600 border-2 border-blue-600 shadow-blue-100' : 
                    'bg-slate-50 text-slate-400 border border-slate-200'}`}
                >
                  {isCompleted ? <CheckCircle2 size={24} /> : <Icon size={24} />}
                  {isCurrent && (
                    <span className="absolute -top-2 -right-2 w-6 h-6 bg-blue-100 text-blue-700 rounded-full flex items-center justify-center text-xs font-bold ring-4 ring-white">
                      {s.num}
                    </span>
                  )}
                </div>
                <div className="flex flex-col items-center">
                  <span className={`text-sm font-bold ${isCurrent ? 'text-blue-700' : isCompleted ? 'text-slate-800' : 'text-slate-500'}`}>
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
          
          {/* Step 1: Student Data */}
          <div className={step === 1 ? 'block space-y-10' : 'hidden'}>
            <div className="flex items-center gap-3 pb-6 border-b border-slate-100">
              <div className="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center text-blue-600">
                <User size={20} />
              </div>
              <div>
                <h2 className="text-xl font-bold text-slate-800">المعلومات الشخصية للتلميذ</h2>
                <p className="text-sm text-slate-500 mt-1">الرجاء إدخال البيانات المطلوبة بدقة (*) للتقدم</p>
              </div>
            </div>
            
            {/* Photo Upload */}
            <div className="flex flex-col items-center justify-center p-8 bg-slate-50/50 rounded-2xl border border-slate-100 border-dashed">
              <div className="relative mb-4">
                <label className="cursor-pointer group block">
                  <div className={`w-36 h-36 rounded-full border-4 flex flex-col items-center justify-center overflow-hidden transition-all duration-300 shadow-sm ${photoPreview ? 'border-white ring-4 ring-blue-50' : 'border-dashed border-slate-300 group-hover:border-blue-400 bg-white'}`}>
                    {photoPreview ? (
                      <>
                        <img src={photoPreview} alt="Preview" className="w-full h-full object-cover" />
                        <div className="absolute inset-0 bg-slate-900/40 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                          <Camera className="text-white w-8 h-8 mb-1" />
                          <span className="text-white text-xs font-medium">تغيير الصورة</span>
                        </div>
                      </>
                    ) : (
                      <>
                        <div className="w-14 h-14 rounded-full bg-blue-50 flex items-center justify-center mb-2 group-hover:bg-blue-100 transition-colors">
                          <Upload className="text-blue-500 w-7 h-7" />
                        </div>
                        <span className="text-xs font-medium text-slate-500 group-hover:text-blue-600 transition-colors">رفع صورة التلميذ</span>
                      </>
                    )}
                  </div>
                  <input type="file" name="photo" className="hidden" accept="image/*" onChange={handlePhotoChange} />
                </label>
              </div>
              {photoPreview && (
                <button
                  type="button"
                  onClick={() => {
                    setPhotoPreview(null);
                    setPhotoFile(null);
                    if (formRef.current) {
                      const fileInput = formRef.current.querySelector('input[name="photo"]') as HTMLInputElement;
                      if (fileInput) fileInput.value = '';
                    }
                  }}
                  className="text-sm font-medium text-red-500 hover:text-red-700 hover:bg-red-50 px-4 py-2 rounded-xl transition-colors flex items-center gap-2"
                >
                  <Trash2 size={16} />
                  حذف الصورة
                </button>
              )}
            </div>

            {/* Form Fields */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-8">
              <div className="space-y-2.5">
                <label className="block text-sm font-semibold text-slate-700">الاسم <span className="text-red-500">*</span></label>
                <div className="relative">
                  <div className="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none text-slate-400">
                    <User size={18} />
                  </div>
                  <input type="text" name="first_name" value={formData.first_name} onChange={handleChange} placeholder="مثال: أحمد" className="block w-full pr-12 pl-4 py-3 bg-slate-50/50 hover:bg-white border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200 text-slate-700 placeholder:text-slate-400" />
                </div>
              </div>

              <div className="space-y-2.5">
                <label className="block text-sm font-semibold text-slate-700">اللقب <span className="text-red-500">*</span></label>
                <div className="relative">
                  <div className="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none text-slate-400">
                    <User size={18} />
                  </div>
                  <input type="text" name="last_name" value={formData.last_name} onChange={handleChange} placeholder="مثال: بن علي" className="block w-full pr-12 pl-4 py-3 bg-slate-50/50 hover:bg-white border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200 text-slate-700 placeholder:text-slate-400" />
                </div>
              </div>

              <div className="space-y-2.5">
                <label className="block text-sm font-semibold text-slate-700">تاريخ الولادة <span className="text-red-500">*</span></label>
                <div className="relative">
                  <div className="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none text-slate-400">
                    <Calendar size={18} />
                  </div>
                  <input type="date" name="dob" value={formData.dob} onChange={handleChange} className="block w-full pr-12 pl-4 py-3 bg-slate-50/50 hover:bg-white border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200 text-slate-700" />
                </div>
              </div>

              <div className="space-y-2.5">
                <label className="block text-sm font-semibold text-slate-700">الجنس</label>
                <div className="relative">
                  <input type="hidden" name="gender" value={formData.gender} />
                  <div className="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none text-slate-400 z-10">
                    <User size={18} />
                  </div>
                  <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 z-10">
                    <ChevronDown size={18} className={`transition-transform duration-200 ${openDropdown === 'gender' ? 'rotate-180' : ''}`} />
                  </div>
                  <div 
                    onClick={() => setOpenDropdown(openDropdown === 'gender' ? null : 'gender')}
                    className={`flex items-center w-full pr-12 pl-12 py-3 bg-slate-50/50 hover:bg-white border rounded-xl outline-none transition-all duration-200 cursor-pointer min-h-[50px] ${openDropdown === 'gender' ? 'border-blue-500 ring-2 ring-blue-500/20 bg-white' : 'border-slate-200'}`}
                  >
                    <span className={formData.gender ? 'text-slate-700' : 'text-slate-400'}>
                      {formData.gender === 'male' ? 'ذكر' : formData.gender === 'female' ? 'أنثى' : 'اختر الجنس'}
                    </span>
                  </div>
                  
                  {openDropdown === 'gender' && (
                    <>
                      <div className="fixed inset-0 z-40" onClick={() => setOpenDropdown(null)} />
                      <div className="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-lg z-50 overflow-hidden py-1">
                        {[
                          { id: 'male', label: 'ذكر' },
                          { id: 'female', label: 'أنثى' }
                        ].map(option => (
                          <div 
                            key={option.id}
                            onClick={() => {
                              setFormData(prev => ({ ...prev, gender: option.id }));
                              setOpenDropdown(null);
                              if (validationError) setValidationError('');
                            }}
                            className={`px-4 py-3 cursor-pointer transition-colors flex items-center justify-between ${formData.gender === option.id ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-slate-50 text-slate-700'}`}
                          >
                            {option.label}
                            {formData.gender === option.id && <CheckCircle2 size={16} className="text-blue-600" />}
                          </div>
                        ))}
                      </div>
                    </>
                  )}
                </div>
              </div>

              <div className="space-y-2.5 md:col-span-2">
                <label className="block text-sm font-semibold text-slate-700">القسم <span className="text-red-500">*</span></label>
                <div className="relative">
                  <input type="hidden" name="academic_year_id" value={formData.academic_year_id} />
                  <div className="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none text-slate-400 z-10">
                    <BookOpen size={18} />
                  </div>
                  <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 z-10">
                    <ChevronDown size={18} className={`transition-transform duration-200 ${openDropdown === 'academic_year' ? 'rotate-180' : ''}`} />
                  </div>
                  <div 
                    onClick={() => setOpenDropdown(openDropdown === 'academic_year' ? null : 'academic_year')}
                    className={`flex items-center w-full pr-12 pl-12 py-3 bg-slate-50/50 hover:bg-white border rounded-xl outline-none transition-all duration-200 cursor-pointer min-h-[50px] ${openDropdown === 'academic_year' ? 'border-blue-500 ring-2 ring-blue-500/20 bg-white' : 'border-slate-200'}`}
                  >
                    <span className={formData.academic_year_id ? 'text-slate-700' : 'text-slate-400'}>
                      {formData.academic_year_id === '1' ? 'السنة الأولى' : 
                       formData.academic_year_id === '2' ? 'السنة الثانية' : 
                       formData.academic_year_id === '3' ? 'السنة الثالثة' : 'اختر القسم المراد التسجيل فيه'}
                    </span>
                  </div>
                  
                  {openDropdown === 'academic_year' && (
                    <>
                      <div className="fixed inset-0 z-40" onClick={() => setOpenDropdown(null)} />
                      <div className="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-lg z-50 overflow-hidden py-1">
                        {[
                          { id: '1', label: 'السنة الأولى' },
                          { id: '2', label: 'السنة الثانية' },
                          { id: '3', label: 'السنة الثالثة' }
                        ].map(option => (
                          <div 
                            key={option.id}
                            onClick={() => {
                              setFormData(prev => ({ ...prev, academic_year_id: option.id }));
                              setOpenDropdown(null);
                              if (validationError) setValidationError('');
                            }}
                            className={`px-4 py-3 cursor-pointer transition-colors flex items-center justify-between ${formData.academic_year_id === option.id ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-slate-50 text-slate-700'}`}
                          >
                            {option.label}
                            {formData.academic_year_id === option.id && <CheckCircle2 size={16} className="text-blue-600" />}
                          </div>
                        ))}
                      </div>
                    </>
                  )}
                </div>
              </div>

              <div className="md:col-span-2 space-y-2.5">
                <label className="block text-sm font-semibold text-slate-700">ملاحظات إضافية</label>
                <div className="relative">
                  <div className="absolute top-4 right-4 flex items-start pointer-events-none text-slate-400">
                    <FileText size={18} />
                  </div>
                  <textarea name="notes" value={formData.notes} onChange={handleChange} rows={3} placeholder="أي معلومات إضافية أو طبية يجب معرفتها (اختياري)..." className="block w-full pr-12 pl-4 py-3 bg-slate-50/50 hover:bg-white border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200 text-slate-700 placeholder:text-slate-400"></textarea>
                </div>
              </div>
            </div>
          </div>

          {/* Step 2: Guardian Data */}
          <div className={step === 2 ? 'block' : 'hidden'}>
            <h2 className="text-xl font-semibold text-slate-800 mb-6">بيانات الولي</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">الاسم الأول للولي</label>
                <input required={step === 2} type="text" name="guardian_first_name" value={formData.guardian_first_name} onChange={handleChange} className="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">لقب الولي</label>
                <input required={step === 2} type="text" name="guardian_last_name" value={formData.guardian_last_name} onChange={handleChange} className="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">رقم هاتف الولي</label>
                <input required={step === 2} type="tel" dir="ltr" name="guardian_phone" value={formData.guardian_phone} onChange={handleChange} className="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-right" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">البريد الإلكتروني</label>
                <input type="email" dir="ltr" name="guardian_email" value={formData.guardian_email} onChange={handleChange} className="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-right" />
              </div>
              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-slate-700 mb-2">العنوان</label>
                <input required={step === 2} type="text" name="address" value={formData.address} onChange={handleChange} className="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">رقم هاتف الأم</label>
                <input type="tel" dir="ltr" name="mother_phone" value={formData.mother_phone} onChange={handleChange} className="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-right" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">البريد الإلكتروني للأم</label>
                <input type="email" dir="ltr" name="mother_email" value={formData.mother_email} onChange={handleChange} className="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-right" />
              </div>
            </div>
          </div>

          {/* Step 3: Payment */}
          <div className={step === 3 ? 'block' : 'hidden'}>
            <h2 className="text-xl font-semibold text-slate-800 mb-6">تفاصيل الدفع</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">طريقة الدفع</label>
                <div className="relative">
                  <input type="hidden" name="payment_method" value={formData.payment_method} />
                  <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 z-10">
                    <ChevronDown size={18} className={`transition-transform duration-200 ${openDropdown === 'payment_method' ? 'rotate-180' : ''}`} />
                  </div>
                  <div 
                    onClick={() => setOpenDropdown(openDropdown === 'payment_method' ? null : 'payment_method')}
                    className={`flex items-center w-full pr-4 pl-12 py-2.5 bg-slate-50 hover:bg-white border rounded-lg outline-none transition-all duration-200 cursor-pointer min-h-[46px] ${openDropdown === 'payment_method' ? 'border-blue-500 ring-2 ring-blue-500/20 bg-white' : 'border-slate-200'}`}
                  >
                    <span className={formData.payment_method ? 'text-slate-700' : 'text-slate-400'}>
                      {formData.payment_method === 'cash' ? 'نقداً' : 
                       formData.payment_method === 'bank_transfer' ? 'تحويل بنكي' : 
                       formData.payment_method === 'check' ? 'شيك' : 'اختر الطريقة'}
                    </span>
                  </div>
                  
                  {openDropdown === 'payment_method' && (
                    <>
                      <div className="fixed inset-0 z-40" onClick={() => setOpenDropdown(null)} />
                      <div className="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-lg z-50 overflow-hidden py-1">
                        {[
                          { id: 'cash', label: 'نقداً' },
                          { id: 'bank_transfer', label: 'تحويل بنكي' },
                          { id: 'check', label: 'شيك' }
                        ].map(option => (
                          <div 
                            key={option.id}
                            onClick={() => {
                              setFormData(prev => ({ ...prev, payment_method: option.id }));
                              setOpenDropdown(null);
                            }}
                            className={`px-4 py-3 cursor-pointer transition-colors flex items-center justify-between ${formData.payment_method === option.id ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-slate-50 text-slate-700'}`}
                          >
                            {option.label}
                            {formData.payment_method === option.id && <CheckCircle2 size={16} className="text-blue-600" />}
                          </div>
                        ))}
                      </div>
                    </>
                  )}
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">مبلغ التسجيل</label>
                <input required={step === 3} type="number" step="0.01" min="0" dir="ltr" name="registration_amount" value={formData.registration_amount} onChange={handleChange} className="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-right" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">تاريخ الدفع</label>
                <input required={step === 3} type="date" name="payment_date" value={formData.payment_date} onChange={handleChange} className="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" />
              </div>
              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-slate-700 mb-2">معلومات إضافية</label>
                <textarea name="payment_notes" value={formData.payment_notes} onChange={handleChange} rows={3} className="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"></textarea>
              </div>
            </div>
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
                {isSubmitting ? 'جاري الحفظ...' : 'حفظ نهائي'}
                {!isSubmitting && <CheckCircle2 size={18} />}
              </button>
            )}
          </div>

        </form>
      </div>
    </div>
  );
}
