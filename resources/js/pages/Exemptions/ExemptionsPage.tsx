import React, { useState, useEffect, useMemo } from 'react';
import { Link } from 'react-router-dom';
import {
  ShieldCheck,
  HeartHandshake,
  Award,
  Users,
  Search,
  Plus,
  Filter,
  XCircle,
  CheckCircle2,
  AlertCircle,
  FileText,
  Calendar,
  Layers,
  ChevronDown,
  RefreshCw,
  Eye,
  Trash2,
} from 'lucide-react';
import { apiFetch, ApiError } from '../../api/http';
import {
  fetchAllExemptions,
  cancelMonthlyExemption,
  cancelClubExemption,
  createMonthlyExemption,
  createClubExemption,
  ExemptionItem,
  ExemptionStats,
} from '../../api/exemptions';
import { ExemptionBadge } from '../../components/Exemptions/ExemptionBadge';
import { fetchAcademicYears } from '../../api/years';
import { fetchClubSubscriptions, ClubSubscriptionItem } from '../../api/clubs';
import { AcademicYear } from '../../types';

const C = {
  forest: '#2E3B2A',
  sage: '#E9EEE3',
  ink: '#1F261C',
  border: '#D8E2D2',
  cardBg: '#FFFFFF',
};

type SectionOption = { id: number; name: string; level?: { name: string } | null };
type StudentOption = {
  enrollment_id: number;
  student: { id: number; first_name: string; last_name: string; student_code?: string };
};

export function ExemptionsPage() {
  const [exemptions, setExemptions] = useState<ExemptionItem[]>([]);
  const [stats, setStats] = useState<ExemptionStats>({
    total_exemptions: 0,
    tuition_full_waivers: 0,
    club_full_waivers: 0,
    humanitarian_discounts: 0,
  });

  const [years, setYears] = useState<AcademicYear[]>([]);
  const [selectedYearId, setSelectedYearId] = useState<number | null>(null);
  const [sections, setSections] = useState<SectionOption[]>([]);
  const [selectedSectionId, setSelectedSectionId] = useState<number | null>(null);

  const [searchQuery, setSearchQuery] = useState('');
  const [selectedType, setSelectedType] = useState<string>('all');
  const [selectedStatus, setSelectedStatus] = useState<'active' | 'cancelled' | 'all'>('active');
  const [selectedCategory, setSelectedCategory] = useState<'all' | 'tuition' | 'club'>('all');

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  // New Exemption Modal State
  const [showAddModal, setShowAddModal] = useState(false);
  const [modalCategory, setModalCategory] = useState<'tuition' | 'club'>('tuition');
  const [modalDiscountType, setModalDiscountType] = useState<'full_waiver' | 'humanitarian_fixed' | 'normal_monthly'>('full_waiver');
  const [modalYearId, setModalYearId] = useState<number | null>(null);
  const [modalSectionId, setModalSectionId] = useState<number | null>(null);
  const [modalSections, setModalSections] = useState<SectionOption[]>([]);
  const [loadingModalSections, setLoadingModalSections] = useState(false);
  const [modalStudents, setModalStudents] = useState<StudentOption[]>([]);
  const [loadingModalStudents, setLoadingModalStudents] = useState(false);
  const [modalEnrollmentId, setModalEnrollmentId] = useState<number | null>(null);
  const [modalStudentClubSubs, setModalStudentClubSubs] = useState<ClubSubscriptionItem[]>([]);
  const [modalSelectedClubSubId, setModalSelectedClubSubId] = useState<number | null>(null);

  const [modalMonthlyAmount, setModalMonthlyAmount] = useState<string>('');
  const [modalStartMonth, setModalStartMonth] = useState<string>('');
  const [modalEndMonth, setModalEndMonth] = useState<string>('');
  const [modalReason, setModalReason] = useState<string>('');
  const [modalNotes, setModalNotes] = useState<string>('');
  const [submittingModal, setSubmittingModal] = useState(false);
  const [modalError, setModalError] = useState<string | null>(null);

  // Cancel Exemption Modal State
  const [itemToCancel, setItemToCancel] = useState<ExemptionItem | null>(null);
  const [cancelReasonInput, setCancelReasonInput] = useState('');
  const [cancelling, setCancelling] = useState(false);

  // Load Years on Mount
  useEffect(() => {
    (async () => {
      try {
        const ys = await fetchAcademicYears();
        setYears(ys);
        const active = ys.find((y) => y.is_active) ?? ys[0] ?? null;
        if (active) {
          setSelectedYearId(active.id);
          setModalYearId(active.id);
          setDefaultMonthsForYear(active.name);
        }
      } catch (err: any) {
        setError('تعذّر تحميل السنوات الدراسية');
      }
    })();
  }, []);

  const setDefaultMonthsForYear = (yearName?: string) => {
    if (yearName && /^\d{4}-\d{4}$/.test(yearName)) {
      const [startYear, endYear] = yearName.split('-');
      setModalStartMonth(`${startYear}-09`);
      setModalEndMonth(`${endYear}-06`);
    } else {
      const now = new Date();
      const currentYear = now.getFullYear();
      setModalStartMonth(`${currentYear}-09`);
      setModalEndMonth(`${currentYear + 1}-06`);
    }
  };

  // Load Sections when Filter Year Changes
  useEffect(() => {
    if (!selectedYearId) return;
    (async () => {
      try {
        const secs = await apiFetch<SectionOption[]>(`/collection/years/${selectedYearId}/sections`);
        setSections(secs);
      } catch (err) {
        console.error('Failed to load sections', err);
      }
    })();
  }, [selectedYearId]);

  // Load Data
  const loadExemptionsList = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetchAllExemptions({
        academic_year_id: selectedYearId,
        section_id: selectedSectionId,
        discount_type: selectedType,
        status: selectedStatus,
        search: searchQuery,
      });
      setExemptions(res.data);
      setStats(res.stats);
    } catch (err: any) {
      setError(err instanceof ApiError ? err.firstError : 'تعذّر تحميل قائمة الإعفاءات');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadExemptionsList();
  }, [selectedYearId, selectedSectionId, selectedType, selectedStatus]);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    loadExemptionsList();
  };

  // Filter in memory for category if needed
  const filteredExemptions = useMemo(() => {
    if (selectedCategory === 'all') return exemptions;
    return exemptions.filter((item) => item.type === selectedCategory);
  }, [exemptions, selectedCategory]);

  // Modal Section Loading
  useEffect(() => {
    if (!modalYearId || !showAddModal) return;
    let isMounted = true;
    (async () => {
      setLoadingModalSections(true);
      try {
        const secs = await apiFetch<SectionOption[]>(`/collection/years/${modalYearId}/sections`);
        if (!isMounted) return;
        setModalSections(secs);
        if (secs.length > 0) {
          setModalSectionId((prev) => (secs.some((s) => s.id === prev) ? prev : secs[0].id));
        } else {
          setModalSectionId(null);
          setModalStudents([]);
          setModalEnrollmentId(null);
        }
      } catch (err) {
        if (isMounted) {
          console.error('Failed to load modal sections', err);
          setModalSections([]);
          setModalSectionId(null);
        }
      } finally {
        if (isMounted) setLoadingModalSections(false);
      }
    })();
    return () => {
      isMounted = false;
    };
  }, [modalYearId, showAddModal]);

  // Modal Students Loading
  useEffect(() => {
    if (!modalSectionId || !modalYearId || !showAddModal) {
      if (!modalSectionId) {
        setModalStudents([]);
        setModalEnrollmentId(null);
      }
      return;
    }
    let isMounted = true;
    (async () => {
      setLoadingModalStudents(true);
      try {
        const stus = await apiFetch<StudentOption[]>(
          `/collection/sections/${modalSectionId}/students?year_id=${modalYearId}`
        );
        if (!isMounted) return;
        setModalStudents(stus);
        if (stus.length > 0) {
          setModalEnrollmentId((prev) => (stus.some((s) => s.enrollment_id === prev) ? prev : stus[0].enrollment_id));
        } else {
          setModalEnrollmentId(null);
        }
      } catch (err) {
        if (isMounted) {
          console.error('Failed to load modal students', err);
          setModalStudents([]);
          setModalEnrollmentId(null);
        }
      } finally {
        if (isMounted) setLoadingModalStudents(false);
      }
    })();
    return () => {
      isMounted = false;
    };
  }, [modalSectionId, modalYearId, showAddModal]);

  // Modal Club Subs Loading for chosen Student
  useEffect(() => {
    if (!modalEnrollmentId) {
      setModalStudentClubSubs([]);
      setModalSelectedClubSubId(null);
      return;
    }
    const currentStudentObj = modalStudents.find((s) => s.enrollment_id === modalEnrollmentId);
    if (!currentStudentObj) return;

    (async () => {
      try {
        const res = await fetchClubSubscriptions({
          student_id: currentStudentObj.student.id,
          academic_year_id: modalYearId ?? undefined,
        });
        const subs = Array.isArray(res) ? res : (res?.data ?? []);
        setModalStudentClubSubs(subs);
        if (subs.length > 0) {
          setModalSelectedClubSubId(subs[0].id);
        } else {
          setModalSelectedClubSubId(null);
        }
      } catch (err) {
        console.error('Failed to load student club subscriptions', err);
      }
    })();
  }, [modalEnrollmentId, modalStudents, modalYearId]);

  const handleOpenAddModal = () => {
    setModalError(null);
    setModalReason('');
    setModalNotes('');
    setModalMonthlyAmount('');
    setModalDiscountType('full_waiver');
    setModalCategory('tuition');
    if (years.length > 0) {
      const active = years.find((y) => y.is_active) ?? years[0];
      setModalYearId(active.id);
      setDefaultMonthsForYear(active.name);
    }
    setShowAddModal(true);
  };

  const handleSaveNewExemption = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!modalEnrollmentId) {
      setModalError('يرجى اختيار التلميذ أولاً');
      return;
    }
    if (!modalReason.trim()) {
      setModalError('يرجى إدخال سبب الإعفاء');
      return;
    }
    if (modalDiscountType !== 'full_waiver' && (!modalMonthlyAmount || Number(modalMonthlyAmount) <= 0)) {
      setModalError('يرجى تحديد مبلغ التخفيض الشهري');
      return;
    }

    setSubmittingModal(true);
    setModalError(null);

    try {
      if (modalCategory === 'tuition') {
        await createMonthlyExemption(modalEnrollmentId, {
          discount_type: modalDiscountType,
          monthly_amount: modalDiscountType === 'full_waiver' ? null : Number(modalMonthlyAmount),
          start_month: modalStartMonth,
          end_month: modalEndMonth,
          reason: modalReason.trim(),
          notes: modalNotes.trim() || undefined,
        });
        setSuccessMsg('تم تسجيل إعفاء معلوم التمدرس بنجاح');
      } else {
        if (!modalSelectedClubSubId) {
          setModalError('التلميذ المختار غير مشترك في أي نادٍ مسجل بهذه السنة');
          setSubmittingModal(false);
          return;
        }
        await createClubExemption(modalEnrollmentId, modalSelectedClubSubId, {
          discount_type: modalDiscountType === 'normal_monthly' ? 'humanitarian_fixed' : modalDiscountType,
          monthly_amount: modalDiscountType === 'full_waiver' ? null : Number(modalMonthlyAmount),
          start_month: modalStartMonth,
          end_month: modalEndMonth,
          reason: modalReason.trim(),
          notes: modalNotes.trim() || undefined,
        });
        setSuccessMsg('تم تسجيل إعفاء النادي المدرسي بنجاح');
      }

      setShowAddModal(false);
      loadExemptionsList();
    } catch (err: any) {
      setModalError(err instanceof ApiError ? err.firstError : 'تعذّر حفظ الإعفاء');
    } finally {
      setSubmittingModal(false);
    }
  };

  const handleConfirmCancel = async () => {
    if (!itemToCancel || !cancelReasonInput.trim()) return;
    setCancelling(true);
    try {
      if (itemToCancel.type === 'tuition') {
        await cancelMonthlyExemption(itemToCancel.id, cancelReasonInput.trim());
      } else {
        await cancelClubExemption(itemToCancel.id, cancelReasonInput.trim());
      }
      setSuccessMsg('تم إلغاء الإعفاء بنجاح');
      setItemToCancel(null);
      setCancelReasonInput('');
      loadExemptionsList();
    } catch (err: any) {
      setError(err instanceof ApiError ? err.firstError : 'تعذّر إلغاء الإعفاء');
    } finally {
      setCancelling(false);
    }
  };

  return (
    <div className="p-6 md:p-8 space-y-6 max-w-7xl mx-auto" dir="rtl">
      {/* ─── Header ─── */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-2xl border border-[#D8E2D2] shadow-sm">
        <div className="flex items-center gap-4">
          <div className="w-14 h-14 rounded-2xl bg-[#E9EEE3] text-[#2E3B2A] flex items-center justify-center shadow-inner">
            <HeartHandshake size={28} />
          </div>
          <div>
            <h1 className="text-2xl font-bold text-[#1F261C]">إدارة الإعفاءات والتخفيضات</h1>
            <p className="text-sm text-slate-500 mt-1">
              تسجيل ومتابعة إعفاءات التلاميذ من معاليم التمدرس ومعاليم النوادي المدرسية
            </p>
          </div>
        </div>

        <button
          onClick={handleOpenAddModal}
          className="flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#2E3B2A] text-white font-medium hover:bg-[#242f21] transition shadow"
        >
          <Plus size={20} />
          <span>تسجيل إعفاء جديد</span>
        </button>
      </div>

      {/* ─── Success/Error Alerts ─── */}
      {successMsg && (
        <div className="flex items-center justify-between p-4 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-xl">
          <div className="flex items-center gap-2">
            <CheckCircle2 size={18} />
            <span>{successMsg}</span>
          </div>
          <button onClick={() => setSuccessMsg(null)} className="text-emerald-600 hover:text-emerald-900">
            <XCircle size={18} />
          </button>
        </div>
      )}

      {error && (
        <div className="flex items-center justify-between p-4 bg-red-50 text-red-800 border border-red-200 rounded-xl">
          <div className="flex items-center gap-2">
            <AlertCircle size={18} />
            <span>{error}</span>
          </div>
          <button onClick={() => setError(null)} className="text-red-600 hover:text-red-900">
            <XCircle size={18} />
          </button>
        </div>
      )}

      {/* ─── Statistics Cards ─── */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white p-5 rounded-2xl border border-[#D8E2D2] shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-slate-500">إجمالي الإعفاءات النشطة</p>
            <p className="text-2xl font-bold text-[#1F261C] mt-1">{stats.total_exemptions}</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center">
            <ShieldCheck size={24} />
          </div>
        </div>

        <div className="bg-white p-5 rounded-2xl border border-[#D8E2D2] shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-slate-500">إعفاء تمدرس كلي</p>
            <p className="text-2xl font-bold text-emerald-700 mt-1">{stats.tuition_full_waivers}</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
            <Award size={24} />
          </div>
        </div>

        <div className="bg-white p-5 rounded-2xl border border-[#D8E2D2] shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-slate-500">إعفاء معاليم النوادي</p>
            <p className="text-2xl font-bold text-indigo-700 mt-1">{stats.club_full_waivers}</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
            <Layers size={24} />
          </div>
        </div>

        <div className="bg-white p-5 rounded-2xl border border-[#D8E2D2] shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-slate-500">تخفيضات إنسانية</p>
            <p className="text-2xl font-bold text-amber-700 mt-1">{stats.humanitarian_discounts}</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
            <HeartHandshake size={24} />
          </div>
        </div>
      </div>

      {/* ─── Filters & Search Bar ─── */}
      <div className="bg-white p-5 rounded-2xl border border-[#D8E2D2] shadow-sm space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-4">
          {/* Quick Category Tabs */}
          <div className="flex items-center gap-1 bg-[#E9EEE3] p-1 rounded-xl">
            <button
              onClick={() => setSelectedCategory('all')}
              className={`px-4 py-2 rounded-lg text-sm font-medium transition ${
                selectedCategory === 'all'
                  ? 'bg-white text-[#2E3B2A] shadow-sm'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              الكل ({exemptions.length})
            </button>
            <button
              onClick={() => setSelectedCategory('tuition')}
              className={`px-4 py-2 rounded-lg text-sm font-medium transition ${
                selectedCategory === 'tuition'
                  ? 'bg-white text-[#2E3B2A] shadow-sm'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              معلوم التمدرس
            </button>
            <button
              onClick={() => setSelectedCategory('club')}
              className={`px-4 py-2 rounded-lg text-sm font-medium transition ${
                selectedCategory === 'club'
                  ? 'bg-white text-[#2E3B2A] shadow-sm'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              معاليم النوادي
            </button>
          </div>

          {/* Search Input */}
          <form onSubmit={handleSearchSubmit} className="flex-1 max-w-md relative">
            <Search className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="ابحث باسم التلميذ أو المعرّف..."
              className="w-full pl-4 pr-10 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-[#2E3B2A]/20 focus:border-[#2E3B2A]"
            />
          </form>
        </div>

        {/* Dropdown Filters */}
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 pt-3 border-t border-slate-100">
          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">السنة الدراسية</label>
            <select
              value={selectedYearId ?? ''}
              onChange={(e) => setSelectedYearId(e.target.value ? Number(e.target.value) : null)}
              className="w-full p-2.5 rounded-xl border border-slate-200 text-sm bg-white focus:outline-none focus:border-[#2E3B2A]"
            >
              <option value="">كل السنوات</option>
              {years.map((y) => (
                <option key={y.id} value={y.id}>
                  {y.name} {y.is_active ? '(النشطة)' : ''}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">القسم</label>
            <select
              value={selectedSectionId ?? ''}
              onChange={(e) => setSelectedSectionId(e.target.value ? Number(e.target.value) : null)}
              className="w-full p-2.5 rounded-xl border border-slate-200 text-sm bg-white focus:outline-none focus:border-[#2E3B2A]"
            >
              <option value="">كل الأقسام</option>
              {sections.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.level?.name ? `${s.level.name} - ${s.name}` : s.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">نوع الإعفاء</label>
            <select
              value={selectedType}
              onChange={(e) => setSelectedType(e.target.value)}
              className="w-full p-2.5 rounded-xl border border-slate-200 text-sm bg-white focus:outline-none focus:border-[#2E3B2A]"
            >
              <option value="all">كل الأنواع</option>
              <option value="full_waiver">إعفاء كلي</option>
              <option value="humanitarian_fixed">تخفيض إنساني</option>
              <option value="normal_monthly">تخفيض شهري مخصص</option>
            </select>
          </div>

          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">الحالة</label>
            <select
              value={selectedStatus}
              onChange={(e) => setSelectedStatus(e.target.value as any)}
              className="w-full p-2.5 rounded-xl border border-slate-200 text-sm bg-white focus:outline-none focus:border-[#2E3B2A]"
            >
              <option value="active">ساري المفعول فقط</option>
              <option value="cancelled">ملغى فقط</option>
              <option value="all">الكل (ساري وملغى)</option>
            </select>
          </div>
        </div>
      </div>

      {/* ─── Exemptions Table / Cards ─── */}
      <div className="bg-white rounded-2xl border border-[#D8E2D2] shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-slate-500 space-y-3">
            <RefreshCw className="animate-spin mx-auto text-[#2E3B2A]" size={28} />
            <p className="text-sm">جاري تحميل سجل الإعفاءات...</p>
          </div>
        ) : filteredExemptions.length === 0 ? (
          <div className="p-12 text-center text-slate-500 space-y-3">
            <HeartHandshake className="mx-auto text-slate-300" size={48} />
            <p className="text-base font-semibold text-slate-700">لا توجد إعفاءات مسجلة تطابق هذا الفلتر</p>
            <p className="text-xs text-slate-400">يمكنك الضغط على "تسجيل إعفاء جديد" لإضافة إعفاء لتلميذ</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-right text-sm">
              <thead className="bg-[#F7FAF5] text-slate-700 border-b border-slate-100 font-semibold text-xs">
                <tr>
                  <th className="p-4">التلميذ</th>
                  <th className="p-4">القسم</th>
                  <th className="p-4">المجال</th>
                  <th className="p-4">نوع الإعفاء</th>
                  <th className="p-4">المبلغ</th>
                  <th className="p-4">فترة السريان</th>
                  <th className="p-4">السبب والملاحظات</th>
                  <th className="p-4">الحالة والموثّق</th>
                  <th className="p-4 text-center">الإجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredExemptions.map((item) => (
                  <tr
                    key={`${item.type}-${item.id}`}
                    className={`hover:bg-slate-50/80 transition ${!item.is_active ? 'bg-slate-50/50 opacity-75' : ''}`}
                  >
                    {/* Student */}
                    <td className="p-4">
                      {item.student ? (
                        <div>
                          <Link
                            to={`/students/search/${item.student.id}`}
                            className="font-bold text-[#2E3B2A] hover:underline"
                          >
                            {item.student.full_name}
                          </Link>
                          {item.student.student_code && (
                            <p className="text-xs text-slate-400 font-mono">{item.student.student_code}</p>
                          )}
                        </div>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>

                    {/* Classroom */}
                    <td className="p-4">
                      {item.classroom ? (
                        <span className="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 text-xs font-medium">
                          {item.classroom.full_name}
                        </span>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>

                    {/* Category */}
                    <td className="p-4">
                      {item.type === 'tuition' ? (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-800 text-xs font-semibold">
                          <Award size={13} />
                          <span>معلوم دراسي</span>
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-800 text-xs font-semibold">
                          <Layers size={13} />
                          <span>نادي: {item.club_name ?? 'مدرسي'}</span>
                        </span>
                      )}
                    </td>

                    {/* Discount Type */}
                    <td className="p-4">
                      <ExemptionBadge discountType={item.discount_type} />
                    </td>

                    {/* Amount */}
                    <td className="p-4 font-mono font-semibold text-slate-800">
                      {item.discount_type === 'full_waiver' ? (
                        <span className="text-emerald-700 font-sans font-bold text-xs bg-emerald-100/60 px-2 py-0.5 rounded">
                          معفى كلياً (0 د.ت)
                        </span>
                      ) : item.monthly_amount !== null ? (
                        `${Number(item.monthly_amount).toFixed(2)} د.ت / شهر`
                      ) : (
                        '—'
                      )}
                    </td>

                    {/* Period */}
                    <td className="p-4">
                      <div className="flex items-center gap-1 text-xs text-slate-600 font-mono">
                        <Calendar size={13} className="text-slate-400" />
                        <span>{item.start_month}</span>
                        <span className="text-slate-400">←</span>
                        <span>{item.end_month}</span>
                      </div>
                    </td>

                    {/* Reason */}
                    <td className="p-4 max-w-xs">
                      <p className="text-xs text-slate-800 font-medium truncate" title={item.reason}>
                        {item.reason}
                      </p>
                      {item.notes && (
                        <p className="text-[11px] text-slate-400 truncate mt-0.5" title={item.notes}>
                          {item.notes}
                        </p>
                      )}
                    </td>

                    {/* Status & Creator */}
                    <td className="p-4 text-xs">
                      {item.is_active ? (
                        <div className="space-y-0.5">
                          <span className="inline-block px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[11px] font-semibold">
                            ساري
                          </span>
                          {item.created_by && (
                            <p className="text-[11px] text-slate-400">بواسطة: {item.created_by}</p>
                          )}
                        </div>
                      ) : (
                        <div className="space-y-0.5">
                          <span className="inline-block px-2 py-0.5 rounded bg-red-100 text-red-800 text-[11px] font-semibold">
                            ملغى
                          </span>
                          {item.cancellation_reason && (
                            <p className="text-[11px] text-red-600 truncate max-w-[140px]" title={item.cancellation_reason}>
                              السبب: {item.cancellation_reason}
                            </p>
                          )}
                          {item.cancelled_by && (
                            <p className="text-[10px] text-slate-400">ألغاه: {item.cancelled_by}</p>
                          )}
                        </div>
                      )}
                    </td>

                    {/* Actions */}
                    <td className="p-4 text-center">
                      {item.is_active ? (
                        <button
                          onClick={() => {
                            setItemToCancel(item);
                            setCancelReasonInput('');
                          }}
                          className="px-3 py-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 text-xs font-medium transition"
                          title="إلغاء الإعفاء"
                        >
                          إلغاء
                        </button>
                      ) : (
                        <span className="text-slate-400 text-xs">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* ─── Add Exemption Modal ─── */}
      {showAddModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" dir="rtl">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            <div className="px-6 py-4 bg-[#2E3B2A] text-white flex items-center justify-between">
              <div className="flex items-center gap-3">
                <HeartHandshake size={22} />
                <h3 className="text-lg font-bold">تسجيل إعفاء أو تخفيض جديد</h3>
              </div>
              <button
                onClick={() => setShowAddModal(false)}
                className="text-white/70 hover:text-white"
              >
                <XCircle size={20} />
              </button>
            </div>

            <form onSubmit={handleSaveNewExemption} className="p-6 space-y-4 max-h-[80vh] overflow-y-auto">
              {modalError && (
                <div className="p-3 bg-red-50 text-red-700 text-xs rounded-xl border border-red-200 flex items-center gap-2">
                  <AlertCircle size={16} />
                  <span>{modalError}</span>
                </div>
              )}

              {/* Scope Selection */}
              <div className="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  onClick={() => setModalCategory('tuition')}
                  className={`p-3 rounded-xl border text-center transition flex flex-col items-center gap-1 ${
                    modalCategory === 'tuition'
                      ? 'border-[#2E3B2A] bg-[#E9EEE3] text-[#2E3B2A] font-bold'
                      : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                  }`}
                >
                  <Award size={20} />
                  <span className="text-sm">إعفاء معلوم التمدرس الشهري</span>
                </button>
                <button
                  type="button"
                  onClick={() => setModalCategory('club')}
                  className={`p-3 rounded-xl border text-center transition flex flex-col items-center gap-1 ${
                    modalCategory === 'club'
                      ? 'border-[#2E3B2A] bg-[#E9EEE3] text-[#2E3B2A] font-bold'
                      : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                  }`}
                >
                  <Layers size={20} />
                  <span className="text-sm">إعفاء معلوم نادي مدرسي</span>
                </button>
              </div>

              {/* Student Location Hierarchy */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 bg-slate-50 p-3.5 rounded-xl border border-slate-200">
                <div>
                  <label className="block text-xs font-semibold text-slate-600 mb-1">السنة الدراسية</label>
                  <select
                    value={modalYearId ?? ''}
                    onChange={(e) => {
                      const yId = Number(e.target.value);
                      setModalYearId(yId);
                      const matched = years.find((y) => y.id === yId);
                      setDefaultMonthsForYear(matched?.name);
                    }}
                    className="w-full p-2 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:border-[#2E3B2A]"
                    required
                  >
                    {years.map((y) => (
                      <option key={y.id} value={y.id}>
                        {y.name} {y.is_active ? '(النشطة)' : ''}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <div className="flex items-center justify-between mb-1">
                    <label className="block text-xs font-semibold text-slate-600">القسم</label>
                    {loadingModalSections && (
                      <span className="text-[10px] text-emerald-700 animate-pulse font-medium">جاري التحميل...</span>
                    )}
                  </div>
                  <select
                    value={modalSectionId ?? ''}
                    onChange={(e) => setModalSectionId(e.target.value ? Number(e.target.value) : null)}
                    className="w-full p-2 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:border-[#2E3B2A] disabled:bg-slate-100 disabled:text-slate-400"
                    disabled={loadingModalSections}
                    required
                  >
                    {loadingModalSections ? (
                      <option value="">جاري تحميل الأقسام...</option>
                    ) : modalSections.length === 0 ? (
                      <option value="">لا توجد أقسام</option>
                    ) : (
                      modalSections.map((s) => (
                        <option key={s.id} value={s.id}>
                          {s.level?.name ? `${s.level.name} - ${s.name}` : s.name}
                        </option>
                      ))
                    )}
                  </select>
                </div>

                <div>
                  <div className="flex items-center justify-between mb-1">
                    <label className="block text-xs font-semibold text-slate-600">التلميذ</label>
                    {loadingModalStudents && (
                      <span className="text-[10px] text-emerald-700 animate-pulse font-medium">جاري التحميل...</span>
                    )}
                  </div>
                  <select
                    value={modalEnrollmentId ?? ''}
                    onChange={(e) => setModalEnrollmentId(e.target.value ? Number(e.target.value) : null)}
                    className="w-full p-2 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:border-[#2E3B2A] disabled:bg-slate-100 disabled:text-slate-400"
                    disabled={loadingModalStudents || modalSections.length === 0}
                    required
                  >
                    {loadingModalStudents ? (
                      <option value="">جاري تحميل قائمة التلاميذ...</option>
                    ) : modalStudents.length === 0 ? (
                      <option value="">لا يوجد تلاميذ مسجلون في هذا القسم</option>
                    ) : (
                      modalStudents.map((st) => (
                        <option key={st.enrollment_id} value={st.enrollment_id}>
                          {st.student.first_name} {st.student.last_name}{' '}
                          {st.student.student_code ? `(${st.student.student_code})` : ''}
                        </option>
                      ))
                    )}
                  </select>
                </div>
              </div>

              {/* If Club category: show club subscriptions */}
              {modalCategory === 'club' && (
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">النادي المراد إعفاؤه</label>
                  {modalStudentClubSubs.length === 0 ? (
                    <p className="text-xs text-amber-700 bg-amber-50 p-2.5 rounded-lg border border-amber-200">
                      هذا التلميذ غير مشترك في أي نادٍ مسجل بهذه السنة. يرجى تسجيله في النادي من إدارة النوادي أولاً.
                    </p>
                  ) : (
                    <select
                      value={modalSelectedClubSubId ?? ''}
                      onChange={(e) => setModalSelectedClubSubId(Number(e.target.value))}
                      className="w-full p-2.5 rounded-xl border border-slate-300 text-xs bg-white focus:outline-none focus:border-[#2E3B2A]"
                      required
                    >
                      {modalStudentClubSubs.map((sub) => (
                        <option key={sub.id} value={sub.id}>
                          {sub.club?.name} — {Number(sub.monthly_fee ?? sub.club?.monthly_fee ?? 0).toFixed(2)} د.ت/شهر
                        </option>
                      ))}
                    </select>
                  )}
                </div>
              )}

              {/* Exemption Type */}
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">نوع الإعفاء / التخفيض</label>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  <label
                    className={`flex items-center gap-2 p-3 rounded-xl border cursor-pointer transition ${
                      modalDiscountType === 'full_waiver'
                        ? 'border-emerald-600 bg-emerald-50 text-emerald-900 font-semibold'
                        : 'border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    <input
                      type="radio"
                      name="modalDiscountType"
                      checked={modalDiscountType === 'full_waiver'}
                      onChange={() => setModalDiscountType('full_waiver')}
                      className="text-emerald-600"
                    />
                    <div className="text-xs">
                      <p className="font-bold">إعفاء كلي (100%)</p>
                      <p className="text-slate-500 font-normal">يصبح المطلوب 0 د.ت مع عدم المطالبة بالدفع</p>
                    </div>
                  </label>

                  <label
                    className={`flex items-center gap-2 p-3 rounded-xl border cursor-pointer transition ${
                      modalDiscountType === 'humanitarian_fixed'
                        ? 'border-amber-600 bg-amber-50 text-amber-900 font-semibold'
                        : 'border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    <input
                      type="radio"
                      name="modalDiscountType"
                      checked={modalDiscountType === 'humanitarian_fixed'}
                      onChange={() => setModalDiscountType('humanitarian_fixed')}
                      className="text-amber-600"
                    />
                    <div className="text-xs">
                      <p className="font-bold">تخفيض إنساني استثنائي</p>
                      <p className="text-slate-500 font-normal">تحديد معلوم شهري ثابت مخفّض</p>
                    </div>
                  </label>
                </div>
              </div>

              {/* Monthly Amount (if not full waiver) */}
              {modalDiscountType !== 'full_waiver' && (
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    المبلغ الشهري المخفض المطلوب دفعه (د.ت)
                  </label>
                  <input
                    type="number"
                    step="0.01"
                    min="1"
                    value={modalMonthlyAmount}
                    onChange={(e) => setModalMonthlyAmount(e.target.value)}
                    placeholder="مثال: 50.00"
                    className="w-full p-2.5 rounded-xl border border-slate-300 text-sm focus:outline-none focus:border-[#2E3B2A]"
                    required
                  />
                </div>
              )}

              {/* Months Range */}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">من شهر (YYYY-MM)</label>
                  <input
                    type="text"
                    value={modalStartMonth}
                    onChange={(e) => setModalStartMonth(e.target.value)}
                    placeholder="2025-09"
                    pattern="^\d{4}-\d{2}$"
                    className="w-full p-2.5 rounded-xl border border-slate-300 text-xs font-mono focus:outline-none focus:border-[#2E3B2A]"
                    required
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">إلى شهر (YYYY-MM)</label>
                  <input
                    type="text"
                    value={modalEndMonth}
                    onChange={(e) => setModalEndMonth(e.target.value)}
                    placeholder="2026-06"
                    pattern="^\d{4}-\d{2}$"
                    className="w-full p-2.5 rounded-xl border border-slate-300 text-xs font-mono focus:outline-none focus:border-[#2E3B2A]"
                    required
                  />
                </div>
              </div>

              {/* Reason */}
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">سبب الإعفاء (إجباري)</label>
                <input
                  type="text"
                  value={modalReason}
                  onChange={(e) => setModalReason(e.target.value)}
                  placeholder="مثال: إعفاء اجتماعي / ظرف إنساني / ابن إطار تربوي..."
                  className="w-full p-2.5 rounded-xl border border-slate-300 text-sm focus:outline-none focus:border-[#2E3B2A]"
                  required
                />
              </div>

              {/* Notes */}
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">ملاحظات إضافية (اختياري)</label>
                <textarea
                  value={modalNotes}
                  onChange={(e) => setModalNotes(e.target.value)}
                  rows={2}
                  placeholder="أي تفاصيل أو وثائق مرفقة..."
                  className="w-full p-2.5 rounded-xl border border-slate-300 text-xs focus:outline-none focus:border-[#2E3B2A]"
                />
              </div>

              <div className="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setShowAddModal(false)}
                  className="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 text-sm hover:bg-slate-50 transition font-medium"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={submittingModal || (modalCategory === 'club' && modalStudentClubSubs.length === 0)}
                  className="px-5 py-2.5 rounded-xl bg-[#2E3B2A] text-white text-sm font-medium hover:bg-[#242f21] transition shadow disabled:opacity-50"
                >
                  {submittingModal ? 'جاري الحفظ...' : 'تثبيت الإعفاء'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ─── Cancel Confirmation Modal ─── */}
      {itemToCancel && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" dir="rtl">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            <div className="px-6 py-4 bg-red-700 text-white flex items-center justify-between">
              <div className="flex items-center gap-2">
                <AlertCircle size={20} />
                <h3 className="text-base font-bold">تأكيد إلغاء الإعفاء</h3>
              </div>
              <button onClick={() => setItemToCancel(null)} className="text-white/70 hover:text-white">
                <XCircle size={18} />
              </button>
            </div>

            <div className="p-6 space-y-4">
              <p className="text-sm text-slate-700">
                أنت على وشك إلغاء الإعفاء المسجل للتلميذ{' '}
                <strong className="text-slate-900">{itemToCancel.student?.full_name}</strong> (
                {itemToCancel.type_label} — {itemToCancel.discount_type_label}).
              </p>
              <p className="text-xs text-amber-700 bg-amber-50 p-2.5 rounded-lg border border-amber-200">
                تنبيه: لن يُحذف السجل نهائياً بل سيتغير وضعه إلى "ملغى" مع توثيق السبب والمستخدم الذي قام بالإلغاء.
              </p>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  سبب الإلغاء (إجباري للتاريخ والتدقيق):
                </label>
                <textarea
                  value={cancelReasonInput}
                  onChange={(e) => setCancelReasonInput(e.target.value)}
                  placeholder="اذكر سبب إلغاء الإعفاء..."
                  rows={3}
                  className="w-full p-2.5 rounded-xl border border-slate-300 text-xs focus:outline-none focus:border-red-600"
                  required
                />
              </div>

              <div className="pt-2 flex items-center justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setItemToCancel(null)}
                  className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 text-xs hover:bg-slate-50 transition"
                >
                  تراجع
                </button>
                <button
                  type="button"
                  disabled={cancelling || !cancelReasonInput.trim()}
                  onClick={handleConfirmCancel}
                  className="px-4 py-2 rounded-xl bg-red-600 text-white text-xs font-semibold hover:bg-red-700 transition disabled:opacity-50"
                >
                  {cancelling ? 'جاري الإلغاء...' : 'تأكيد الإلغاء'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
