import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Search, ArrowRight, User, GraduationCap, CreditCard } from 'lucide-react';
import { getStudents } from '../../api/students';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  beige: '#EFEAE0',
};

export function OldStudentReenroll() {
  const [students, setStudents] = useState<any[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [selectedStudent, setSelectedStudent] = useState<any>(null);
  const [levelId, setLevelId] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('');
  const [amount, setAmount] = useState('');
  const [paymentDate, setPaymentDate] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    loadStudents();
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

  const filtered = students.filter((s) => {
    const fullName = `${s.first_name || ''} ${s.last_name || ''}`.toLowerCase();
    return fullName.includes(search.toLowerCase()) || 
           (s.student_code || '').toLowerCase().includes(search.toLowerCase());
  });


  async function handleSave() {
    if (!levelId || !paymentMethod || !amount || !paymentDate) {
      alert('الرجاء إكمال جميع الحقول');
      return;
    }
    setSaving(true);
    try {
      // TODO: call real re-enroll API when ready
      await new Promise(r => setTimeout(r, 800));
      alert('تم تجديد الترسيم بنجاح');
      setSelectedStudent(null);
    } catch (err: any) {
      alert(err.message || 'حدث خطأ');
    } finally {
      setSaving(false);
    }
  }

  if (selectedStudent) {
    return (
      <div className="p-6 md:p-8" dir="rtl">
        <div className="flex items-center gap-4 mb-6">
          <button
            onClick={() => setSelectedStudent(null)}
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
            تجديد الترسيم — 2025/2026
          </h3>

          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium mb-1.5" style={{ color: C.muted }}>
                القسم / المستوى الدراسي الجديد
              </label>
              <select value={levelId} onChange={(e) => setLevelId(e.target.value)} className="w-full p-3 rounded-xl border bg-slate-50 outline-none" style={{ borderColor: C.line }}>
                <option value="">اختر القسم الجديد</option>
                <option value="1">السنة الأولى</option>
                <option value="2">السنة الثانية</option>
                <option value="3">السنة الثالثة</option>
                <option value="4">السنة الرابعة</option>
                <option value="5">السنة الخامسة</option>
                <option value="6">السنة السادسة</option>
              </select>
            </div>

            <div className="pt-4 border-t" style={{ borderColor: C.line }}>
              <h4 className="font-medium mb-3 flex items-center gap-2" style={{ color: C.ink }}>
                <CreditCard size={18} />
                معلومات الدفع
              </h4>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm mb-1.5" style={{ color: C.muted }}>صيغة الدفع</label>
                  <select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)} className="w-full p-3 rounded-xl border bg-slate-50 outline-none" style={{ borderColor: C.line }}>
                    <option value="">اختر صيغة الدفع</option>
                    <option value="cash">نقداً</option>
                    <option value="check">شيك</option>
                    <option value="bank_transfer">تحويل بنكي</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm mb-1.5" style={{ color: C.muted }}>مبلغ التسجيل</label>
                  <input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="0.00" className="w-full p-3 rounded-xl border bg-slate-50 outline-none" style={{ borderColor: C.line }} />
                </div>
                <div>
                  <label className="block text-sm mb-1.5" style={{ color: C.muted }}>تاريخ الدفع</label>
                  <input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} className="w-full p-3 rounded-xl border bg-slate-50 outline-none" style={{ borderColor: C.line }} />
                </div>
              </div>
            </div>

            <button
              onClick={handleSave}
              disabled={saving}
              className="w-full mt-6 py-3.5 rounded-xl text-white font-medium transition hover:opacity-90 disabled:opacity-70"
              style={{ backgroundColor: C.forest }}
            >
              {saving ? 'جاري الحفظ...' : 'حفظ الترسيم'}
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
          <div className="p-10 text-center" style={{ color: C.muted }}>جاري التحميل...</div>
        ) : filtered.length === 0 ? (
          <div className="p-10 text-center" style={{ color: C.muted }}>لا يوجد تلاميذ</div>
        ) : (
          <div className="divide-y" style={{ borderColor: C.line }}>
            {filtered.map((student) => (
              <button
                key={student.id}
                onClick={() => setSelectedStudent(student)}
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
