import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { 
  Users, UserPlus, Search, DollarSign, PieChart, 
  TrendingUp, ArrowRightLeft, CreditCard, Fingerprint,
  GraduationCap
} from 'lucide-react';
import { getStudents } from '../../api/students';

export function StudentsDashboard() {
  const [students, setStudents] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    async function loadStudents() {
      try {
        const data = await getStudents();
        setStudents(data);
      } catch (err) {
        console.error('Failed to load students:', err);
      } finally {
        setIsLoading(false);
      }
    }
    
    loadStudents();
  }, []);

  const stats = {
    totalStudents: students.length,
    males: students.filter(s => s.gender === 'male').length,
    females: students.filter(s => s.gender === 'female').length,
    currentYearRegistrations: students.length
  };

  const actionCards = [
    { title: 'ترسيم التلاميذ', icon: UserPlus, link: '/students/enroll', color: 'bg-blue-500' },
    { title: 'بحث', icon: Search, link: '/students/search', color: 'bg-indigo-500' },
    { title: 'المداخيل حسب التلاميذ', icon: DollarSign, link: '/students/revenue-by-student', color: 'bg-emerald-500' },
    { title: 'المداخيل حسب القسم', icon: PieChart, link: '/students/revenue-by-class', color: 'bg-teal-500' },
    { title: 'المداخيل السنوية', icon: TrendingUp, link: '/students/annual-revenue', color: 'bg-cyan-500' },
    { title: 'نقل التلاميذ', icon: ArrowRightLeft, link: '/students/transfer', color: 'bg-orange-500' },
    { title: 'قائمة التلاميذ حسب حالة السداد', icon: CreditCard, link: '/students/payment-status', color: 'bg-rose-500' },
    { title: 'البحث حسب الهوية', icon: Fingerprint, link: '/students/search-by-id', color: 'bg-violet-500' },
  ];

  return (
    <div className="p-8 max-w-7xl mx-auto" dir="rtl">
      <div className="mb-8">
        <h1 className="text-2xl font-bold text-slate-800">التلاميذ</h1>
        <p className="text-slate-500 mt-1">إدارة شؤون التلاميذ والتسجيلات</p>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex items-center gap-4">
          <div className="p-3 bg-blue-50 text-blue-600 rounded-lg">
            <Users size={24} />
          </div>
          <div>
            <p className="text-sm text-slate-500 font-medium">إجمالي التلاميذ</p>
            <p className="text-2xl font-bold text-slate-800">{stats.totalStudents}</p>
          </div>
        </div>

        <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex items-center gap-4">
          <div className="p-3 bg-indigo-50 text-indigo-600 rounded-lg">
            <GraduationCap size={24} />
          </div>
          <div>
            <p className="text-sm text-slate-500 font-medium">تلاميذ السنة الحالية</p>
            <p className="text-2xl font-bold text-slate-800">{stats.currentYearRegistrations}</p>
          </div>
        </div>

        <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex items-center gap-4">
          <div className="p-3 bg-emerald-50 text-emerald-600 rounded-lg">
            <Users size={24} />
          </div>
          <div>
            <p className="text-sm text-slate-500 font-medium">الذكور</p>
            <p className="text-2xl font-bold text-slate-800">{stats.males}</p>
          </div>
        </div>

        <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex items-center gap-4">
          <div className="p-3 bg-rose-50 text-rose-600 rounded-lg">
            <Users size={24} />
          </div>
          <div>
            <p className="text-sm text-slate-500 font-medium">الإناث</p>
            <p className="text-2xl font-bold text-slate-800">{stats.females}</p>
          </div>
        </div>
      </div>

      {/* Action Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        {actionCards.map((card, index) => {
          const Icon = card.icon;
          return (
            <Link 
              key={index} 
              to={card.link}
              className="bg-white p-6 rounded-xl shadow-sm border border-slate-200 hover:border-blue-300 hover:shadow-md transition-all flex flex-col items-center justify-center gap-3 text-center group"
            >
              <div className={`p-4 rounded-full text-white ${card.color} shadow-sm group-hover:scale-110 transition-transform`}>
                <Icon size={28} strokeWidth={2} />
              </div>
              <span className="font-medium text-slate-700">{card.title}</span>
            </Link>
          );
        })}
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div className="p-6 border-b border-slate-100 flex justify-between items-center">
          <h2 className="text-lg font-bold text-slate-800">قائمة التلاميذ</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-right text-sm">
            <thead className="bg-slate-50 border-b border-slate-200 text-slate-600">
              <tr>
                <th className="px-6 py-4 font-semibold w-24">رقم</th>
                <th className="px-6 py-4 font-semibold">الاسم الكامل</th>
                <th className="px-6 py-4 font-semibold">الجنس</th>
                <th className="px-6 py-4 font-semibold">تاريخ الميلاد</th>
                <th className="px-6 py-4 font-semibold">رقم الولي</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {isLoading ? (
                <tr>
                  <td colSpan={5} className="px-6 py-8 text-center text-slate-500">
                    جاري التحميل...
                  </td>
                </tr>
              ) : students.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-6 py-8 text-center text-slate-500">
                    لا توجد بيانات للعرض حالياً
                  </td>
                </tr>
              ) : (
                students.map((student) => (
                  <tr key={student.id} className="hover:bg-slate-50">
                    <td className="px-6 py-4 font-medium text-slate-900">{student.id}</td>
                    <td className="px-6 py-4">{student.first_name} {student.last_name}</td>
                    <td className="px-6 py-4">{student.gender === 'male' ? 'ذكر' : student.gender === 'female' ? 'أنثى' : '-'}</td>
                    <td className="px-6 py-4">{student.dob ? new Date(student.dob).toLocaleDateString("ar-TN") : '-'}</td>
                    <td className="px-6 py-4" dir="ltr">{student.guardians?.[0]?.phone || student.guardian_phone || '-'}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
