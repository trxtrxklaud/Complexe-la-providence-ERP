import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Search, ArrowRight, User, GraduationCap, CreditCard, AlertCircle, CheckCircle } from 'lucide-react';
import { getStudents, getSectionOptions, reenrollStudent, type SectionOption } from '../../api/students';
import { ListSkeleton } from '../../components/DataSkeleton';

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

export function OldStudentReenroll() {
  const [students, setStudents] = useState<any[]>([]);
  const [search, setSearch] = useState('');
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

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  useEffect(() => {
    loadStudents();
    loadSections();
  }, []);

  async function loadStudents() {
    try {
      setLoading(true);
      const data = await getStudents();
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
  const sectionError = sectionId === '' ? 'القسم إجباري: اختر قسم التلميذ لهذه السنة الدراسية.' : '';
  const showSectionError = submitted && sectionError !== '';

  function openStudent(student: any) {
    setSelectedStudent(student);
    setSectionId('');
    setSubmitted(false);
    setError('');
    setSuccess('');
  }

  function closeStudent() {
    setSelectedStudent(null);
    setSectionId('');
    setSubmitted(false);
    setError('');
  }

  async function handleSave() {
    setSubmitted(true);
    setError('');

    if (!selectedStudent || sectionError) return;

    setSaving(true);
    try {
      const response = await reenrollStudent(selectedStudent.id, {
        section_id: Number(sectionId),
      });
      const student = `${selectedStudent.first_name || ''} ${selectedStudent.last_name || ''}`.trim();
      const placed = response?.enrollment?.section?.name
        ? ` — القسم: ${response.enrollment.level?.name || ''} ${response.enrollment.section.name}`.trimEnd()
        : '';
      setSuccess(`تم تجديد ترسيم ${student} بنجاح${placed}`);
      setSelectedStudent(null);
      setSectionId('');
      setSubmitted(false);
      setPaymentMethod('');
      setAmount('');
      setPaymentDate('');
    } catch (err: any) {
      setError(err?.message || 'حدث خطأ أثناء الترسيم');
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

        <div className="bg-white rounded-[22px] p-6 border" style={{ borderColor: C.line }}>
          <h3 className="font-bold text-lg mb-4 flex items-center gap-2" style={{ color: C.ink }}>
            <GraduationCap size={20} />
            تجديد الترسيم — السنة الدراسية النشطة
          </h3>

          <div className="space-y-4">
            <div>
              <label htmlFor="section_id" className="block text-sm font-medium mb-1.5" style={{ color: C.muted }}>
                القسم <span style={{ color: C.danger }}>*</span>
              </label>

              <select
                id="section_id"
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
                  المستوى يُحدَّد تلقائياً من القسم المختار.
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

            <div className="pt-4 border-t" style={{ borderColor: C.line }}>
              <h4 className="font-medium mb-1 flex items-center gap-2" style={{ color: C.ink }}>
                <CreditCard size={18} />
                معلومات الدفع
              </h4>
              <p className="mb-3 text-xs" style={{ color: C.danger }}>
                غير مفعّل بعد: هذه الحقول لا تُرسَل إلى الخزينة. سجّل الدفع من شاشة الاستخلاص بعد الترسيم.
              </p>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm mb-1.5" style={{ color: C.muted }}>صيغة الدفع</label>
                  <select disabled value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)} className="w-full p-3 rounded-xl border bg-slate-50 outline-none disabled:opacity-60" style={{ borderColor: C.line }}>
                    <option value="">اختر صيغة الدفع</option>
                    <option value="cash">نقداً</option>
                    <option value="check">شيك</option>
                    <option value="bank_transfer">تحويل بنكي</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm mb-1.5" style={{ color: C.muted }}>مبلغ التسجيل</label>
                  <input disabled type="number" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="0.00" className="w-full p-3 rounded-xl border bg-slate-50 outline-none disabled:opacity-60" style={{ borderColor: C.line }} />
                </div>
                <div>
                  <label className="block text-sm mb-1.5" style={{ color: C.muted }}>تاريخ الدفع</label>
                  <input disabled type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} className="w-full p-3 rounded-xl border bg-slate-50 outline-none disabled:opacity-60" style={{ borderColor: C.line }} />
                </div>
              </div>
            </div>

            <button
              onClick={handleSave}
              disabled={saving || sectionsLoading}
              className="w-full mt-6 py-3.5 rounded-xl text-white font-medium transition hover:opacity-90 disabled:opacity-70"
              style={{ backgroundColor: C.forest }}
            >
              {saving ? 'جارٍ الحفظ…' : 'حفظ الترسيم'}
            </button>
          </div>
        </div>
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
            ابحث عن التلميذ لتجديد ترسيمه
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

      <div className="relative mb-6">
        <Search className="absolute right-4 top-1/2 -translate-y-1/2" size={18} style={{ color: C.muted }} />
        <input
          type="text"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="ابحث بالاسم أو رقم التلميذ..."
          className="w-full pr-12 pl-4 py-3.5 rounded-xl border bg-white outline-none"
          style={{ borderColor: C.line }}
        />
      </div>

      <div className="bg-white rounded-[22px] border overflow-hidden" style={{ borderColor: C.line }}>
        {loading ? (
          <ListSkeleton />
        ) : filtered.length === 0 ? (
          <div className="p-10 text-center" style={{ color: C.muted }}>لا يوجد تلاميذ</div>
        ) : (
          <div className="divide-y" style={{ borderColor: C.line }}>
            {filtered.map((student) => (
              <button
                key={student.id}
                onClick={() => openStudent(student)}
                className="w-full flex items-center gap-4 p-4 text-right hover:bg-[#FAFBF8] transition"
              >
                <div className="w-11 h-11 rounded-full flex items-center justify-center text-white font-semibold"
                     style={{ backgroundColor: C.forest }}>
                  {(student.first_name || '؟')[0]}
                </div>
                <div className="flex-1">
                  <p className="font-medium" style={{ color: C.ink }}>
                    {student.first_name} {student.last_name}
                  </p>
                  <p className="text-sm" style={{ color: C.muted }}>
                    {student.student_code || `ID: ${student.id}`}
                  </p>
                </div>
                <span className="text-xs px-3 py-1 rounded-full" style={{ backgroundColor: C.sage, color: C.forest }}>
                  تجديد الترسيم
                </span>
              </button>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
