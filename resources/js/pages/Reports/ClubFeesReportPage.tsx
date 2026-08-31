import React, { useState, useEffect, useRef, useMemo } from 'react';
import {
  fetchClubFeesReport,
  generateClubMonthFees,
  collectClubFeePayment,
  excludeStudentFromClub,
  restoreStudentToClub,
  fetchClubs,
  ClubReportData,
  ClubReportRecord,
  ClubItem,
} from '../../api/clubs';
import { fetchAcademicYears } from '../../api/years';
import { fetchLevels, fetchSections } from '../../api/classrooms';
import { AcademicYear, Level, Section } from '../../types';
import { Printer, RefreshCw, Search, CheckCircle, AlertCircle, Clock, UserX, RotateCcw } from 'lucide-react';

export default function ClubFeesReportPage() {
  const [reportData, setReportData] = useState<ClubReportData | null>(null);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  const activeRequestId = useRef(0);

  // Filter options
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [clubs, setClubs] = useState<ClubItem[]>([]);
  const [levels, setLevels] = useState<Level[]>([]);
  const [sections, setSections] = useState<Section[]>([]);

  // Selected filters
  const [selectedYearId, setSelectedYearId] = useState<number | ''>('');
  const [selectedMonth, setSelectedMonth] = useState<string>('2026-09');
  const [fromMonth, setFromMonth] = useState<string>('');
  const [toMonth, setToMonth] = useState<string>('');
  const [selectedClubId, setSelectedClubId] = useState<number | ''>('');
  const [selectedLevelId, setSelectedLevelId] = useState<number | ''>('');
  const [selectedSectionId, setSelectedSectionId] = useState<number | ''>('');
  const [selectedStatus, setSelectedStatus] = useState<string>('');
  const [search, setSearch] = useState<string>('');

  // Collect Payment Modal state
  const [collectModalRecord, setCollectModalRecord] = useState<ClubReportRecord | null>(null);
  const [payAmount, setPayAmount] = useState<number | ''>('');
  const [payDate, setPayDate] = useState<string>(new Date().toISOString().slice(0, 10));
  const [payMethod, setPayMethod] = useState<'cash' | 'bank_transfer' | 'check' | 'card'>('cash');
  const [payNotes, setPayNotes] = useState<string>('');
  const [submittingPayment, setSubmittingPayment] = useState(false);

  useEffect(() => {
    loadOptions();
  }, []);

  const academicMonths = useMemo(() => {
    const activeYear = years.find((y) => y.id === Number(selectedYearId));
    if (!activeYear || !activeYear.name) {
      return [
        { value: '2026-09', label: 'سبتمبر 2026' },
        { value: '2026-10', label: 'أكتوبر 2026' },
        { value: '2026-11', label: 'نوفمبر 2026' },
        { value: '2026-12', label: 'ديسمبر 2026' },
        { value: '2027-01', label: 'جانفي 2027' },
        { value: '2027-02', label: 'فيفري 2027' },
        { value: '2027-03', label: 'مارس 2027' },
        { value: '2027-04', label: 'أفريل 2027' },
        { value: '2027-05', label: 'ماي 2027' },
        { value: '2027-06', label: 'جوان 2027' },
      ];
    }
    const [startYearStr, endYearStr] = activeYear.name.split('-').map((s) => s.trim());
    const startYear = parseInt(startYearStr, 10) || 2026;
    const endYear = parseInt(endYearStr, 10) || startYear + 1;

    return [
      { value: `${startYear}-09`, label: `سبتمبر ${startYear}` },
      { value: `${startYear}-10`, label: `أكتوبر ${startYear}` },
      { value: `${startYear}-11`, label: `نوفمبر ${startYear}` },
      { value: `${startYear}-12`, label: `ديسمبر ${startYear}` },
      { value: `${endYear}-01`, label: `جانفي ${endYear}` },
      { value: `${endYear}-02`, label: `فيفري ${endYear}` },
      { value: `${endYear}-03`, label: `مارس ${endYear}` },
      { value: `${endYear}-04`, label: `أفريل ${endYear}` },
      { value: `${endYear}-05`, label: `ماي ${endYear}` },
      { value: `${endYear}-06`, label: `جوان ${endYear}` },
    ];
  }, [years, selectedYearId]);

  useEffect(() => {
    loadReport();
  }, [selectedYearId, selectedMonth, selectedClubId, selectedLevelId, selectedSectionId, selectedStatus, search, fromMonth, toMonth]);

  useEffect(() => {
    const handleClubFeeUpdated = async () => {
      await loadOptions();
      await loadReport();
    };
    window.addEventListener('club-fee-updated', handleClubFeeUpdated);
    return () => {
      window.removeEventListener('club-fee-updated', handleClubFeeUpdated);
    };
  }, []);

  const loadOptions = async () => {
    try {
      const [yList, cList, lList, sList] = await Promise.all([
        fetchAcademicYears(),
        fetchClubs(),
        fetchLevels(),
        fetchSections(),
      ]);
      setYears(yList);
      setClubs(cList);
      setLevels(lList);
      setSections(sList);

      const activeYear = yList.find((y) => y.is_active) || yList[0];
      if (activeYear) {
        setSelectedYearId(activeYear.id);
        const startMonth = activeYear.start_date ? activeYear.start_date.slice(0, 7) : '2026-09';
        const currentMonth = new Date().toISOString().slice(0, 7);
        if (currentMonth < startMonth) {
          setSelectedMonth(startMonth);
        } else {
          setSelectedMonth(currentMonth);
        }
      }
    } catch (err: any) {
      setError(err.message || 'تعذر تحميل خيارات التصفية');
    }
  };

  const loadReport = async () => {
    if (!selectedYearId) return;
    const requestId = ++activeRequestId.current;
    setLoading(true);
    setError(null);
    try {
      const data = await fetchClubFeesReport({
        academic_year_id: selectedYearId ? Number(selectedYearId) : undefined,
        month: selectedMonth || undefined,
        from_month: fromMonth || undefined,
        to_month: toMonth || undefined,
        club_id: selectedClubId ? Number(selectedClubId) : undefined,
        level_id: selectedLevelId ? Number(selectedLevelId) : undefined,
        section_id: selectedSectionId ? Number(selectedSectionId) : undefined,
        status: selectedStatus || undefined,
        search: search.trim() || undefined,
      });
      if (requestId === activeRequestId.current) {
        setReportData(data);
      }
    } catch (err: any) {
      if (requestId === activeRequestId.current) {
        setError(err.message || 'حدث خطأ أثناء تحميل التقرير');
      }
    } finally {
      if (requestId === activeRequestId.current) {
        setLoading(false);
      }
    }
  };

  const selectedClubObj = selectedClubId ? clubs.find((c) => c.id === Number(selectedClubId)) : null;

  const availableLevels = useMemo(() => {
    if (selectedClubId && selectedClubObj?.sections && selectedClubObj.sections.length > 0) {
      const allowedLevelIds = [...new Set(selectedClubObj.sections.map((s) => s.level_id).filter(Boolean))];
      return levels.filter((l) => allowedLevelIds.includes(l.id));
    }
    return levels;
  }, [levels, selectedClubId, selectedClubObj]);

  const availableSections = useMemo(() => {
    let list = sections;
    if (selectedClubId && selectedClubObj?.sections && selectedClubObj.sections.length > 0) {
      const allowedSectionIds = selectedClubObj.sections.map((s) => s.id);
      list = list.filter((s) => allowedSectionIds.includes(s.id));
    }
    if (selectedLevelId) {
      list = list.filter((s) => s.level_id === Number(selectedLevelId));
    }
    return list;
  }, [sections, selectedClubId, selectedClubObj, selectedLevelId]);

  const handleGenerateMonth = async () => {
    if (!selectedYearId || !selectedMonth) {
      setError('اختر السنة الدراسية والشهر أولاً');
      return;
    }
    setGenerating(true);
    setError(null);
    setSuccessMsg(null);
    try {
      const res = await generateClubMonthFees({
        academic_year_id: Number(selectedYearId),
        month: selectedMonth,
        club_id: selectedClubId ? Number(selectedClubId) : undefined,
      });
      setSuccessMsg(res.message);
      await loadReport();
    } catch (err: any) {
      setError(err.message || 'فشل توليد سجلات الشهر');
    } finally {
      setGenerating(false);
    }
  };

  const openCollectModal = (record: ClubReportRecord) => {
    setCollectModalRecord(record);
    setPayAmount(record.remaining > 0 ? record.remaining : record.amount_due);
    setPayDate(new Date().toISOString().slice(0, 10));
    setPayMethod('cash');
    setPayNotes('');
  };

  const handleCollectSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!collectModalRecord || !payAmount) return;
    setSubmittingPayment(true);
    setError(null);
    setSuccessMsg(null);
    try {
      const res = await collectClubFeePayment(collectModalRecord.id, {
        amount_paid: Number(payAmount),
        paid_at: payDate,
        method: payMethod,
        notes: payNotes || undefined,
      });
      setSuccessMsg(res.message || 'تم تسجيل خلاص معلوم النادي بنجاح');
      setCollectModalRecord(null);
      await loadReport();
    } catch (err: any) {
      setError(err.message || 'فشل تسجيل خلاص معلوم النادي');
    } finally {
      setSubmittingPayment(false);
    }
  };

  const handleExclude = async (subId: number) => {
    if (!confirm('هل أنت متأكد من استبعاد هذا التلميذ من متابعة النادي؟ لن يتم توليد رسوم جديدة له في الأشهر القادمة.')) return;
    try {
      const res = await excludeStudentFromClub(subId);
      setSuccessMsg(res.message);
      await loadReport();
    } catch (err: any) {
      setError(err.message || 'فشل استبعاد التلميذ');
    }
  };

  const handleRestore = async (subId: number) => {
    try {
      const res = await restoreStudentToClub(subId);
      setSuccessMsg(res.message);
      await loadReport();
    } catch (err: any) {
      setError(err.message || 'فشل إعادة التلميذ للمتابعة');
    }
  };

  const handlePrint = () => {
    window.print();
  };

  const summary = reportData?.summary;
  const records = reportData?.records || [];

  return (
    <div className="space-y-6 dir-rtl text-right">
      <style>{`
        @media print {
          @page { size: A4 landscape; margin: 10mm; }
          body * { visibility: hidden; }
          .printable-report, .printable-report * { visibility: visible; }
          .printable-report { position: absolute; inset: 0; width: 100%; }
          .no-print, button, input, select { display: none !important; }
        }
      `}</style>

      {/* Header */}
      <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-white p-6 rounded-2xl shadow-sm border border-gray-100 no-print">
        <div>
          <h1 className="text-2xl font-bold text-[#26352B]">كشف استخلاص النوادي</h1>
          <p className="text-gray-500 text-sm mt-1">متابعة اشتراكات واستخلاصات الأنشطة والنوادي المدرسية</p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <button
            onClick={loadReport}
            disabled={loading}
            className="flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition text-sm font-medium disabled:opacity-50"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
            تحديث
          </button>
          <button
            onClick={handleGenerateMonth}
            disabled={generating || !selectedYearId || !selectedMonth}
            className="flex items-center gap-2 px-4 py-2 bg-amber-600 text-white rounded-xl hover:bg-amber-700 transition text-sm font-medium disabled:opacity-50"
          >
            <RefreshCw className={`w-4 h-4 ${generating ? 'animate-spin' : ''}`} />
            {generating ? 'جاري التوليد...' : 'توليد سجلات الشهر'}
          </button>
          <button
            onClick={handlePrint}
            className="flex items-center gap-2 px-4 py-2 bg-[#3B4A36] text-white rounded-xl hover:bg-[#2E3B2A] transition text-sm font-medium"
          >
            <Printer className="w-4 h-4" />
            طباعة الكشف
          </button>
        </div>
      </div>

      {/* Notifications */}
      {successMsg && (
        <div className="flex items-center gap-2 p-4 bg-green-50 border border-green-200 text-green-800 rounded-xl text-sm no-print">
          <CheckCircle className="w-5 h-5 flex-shrink-0" />
          <span>{successMsg}</span>
        </div>
      )}
      {error && (
        <div className="flex items-center gap-2 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm no-print">
          <AlertCircle className="w-5 h-5 flex-shrink-0" />
          <span>{error}</span>
        </div>
      )}

      {/* Filters Bar */}
      <div className="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 no-print space-y-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 text-sm">
          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">السنة الدراسية</label>
            <select
              value={selectedYearId}
              onChange={(e) => setSelectedYearId(e.target.value ? Number(e.target.value) : '')}
              className="w-full border-gray-300 rounded-lg p-2 bg-gray-50 text-sm"
            >
              <option value="">اختر السنة</option>
              {years.map((y) => (
                <option key={y.id} value={y.id}>{y.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">الشهر</label>
            <select
              value={selectedMonth}
              onChange={(e) => setSelectedMonth(e.target.value)}
              className="w-full border-gray-300 rounded-lg p-2 bg-gray-50 text-sm font-medium"
            >
              <option value="">كل أشهر السنة</option>
              {academicMonths.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">النادي</label>
            <select
              value={selectedClubId}
              onChange={(e) => {
                setSelectedClubId(e.target.value ? Number(e.target.value) : '');
                setSelectedLevelId('');
                setSelectedSectionId('');
              }}
              className="w-full border-gray-300 rounded-lg p-2 bg-gray-50 text-sm"
            >
              <option value="">كل النوادي</option>
              {clubs.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">المستوى</label>
            <select
              value={selectedLevelId}
              onChange={(e) => {
                setSelectedLevelId(e.target.value ? Number(e.target.value) : '');
                setSelectedSectionId('');
              }}
              className="w-full border-gray-300 rounded-lg p-2 bg-gray-50 text-sm"
            >
              <option value="">كل المستويات</option>
              {availableLevels.map((l) => (
                <option key={l.id} value={l.id}>{l.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">القسم</label>
            <select
              value={selectedSectionId}
              onChange={(e) => setSelectedSectionId(e.target.value ? Number(e.target.value) : '')}
              className="w-full border-gray-300 rounded-lg p-2 bg-gray-50 text-sm"
            >
              <option value="">كل الأقسام</option>
              {availableSections.map((s) => (
                <option key={s.id} value={s.id}>{s.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">الحالة</label>
            <select
              value={selectedStatus}
              onChange={(e) => setSelectedStatus(e.target.value)}
              className="w-full border-gray-300 rounded-lg p-2 bg-gray-50 text-sm"
            >
              <option value="">جميع الحالات</option>
              <option value="paid">خلاص كامل</option>
              <option value="pending">في انتظار الدفع</option>
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">بحث عن تلميذ</label>
            <div className="relative">
              <input
                type="text"
                placeholder="اسم التلميذ أو معرفه..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full border-gray-300 rounded-lg p-2 pl-8 bg-gray-50 text-sm"
              />
              <Search className="w-4 h-4 text-gray-400 absolute left-2.5 top-2.5" />
            </div>
          </div>
        </div>
      </div>

      {/* Summary KPI Cards */}
      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 no-print">
          <div className="bg-white p-4 rounded-xl border border-gray-100 shadow-sm">
            <span className="text-xs text-gray-500 block">إجمالي المسجلين</span>
            <span className="text-xl font-bold text-[#26352B] mt-1 block">{summary.enrolled_count}</span>
          </div>
          <div className="bg-white p-4 rounded-xl border border-gray-100 shadow-sm">
            <span className="text-xs text-gray-500 block">إجمالي المطلوب</span>
            <span className="text-xl font-bold text-gray-800 mt-1 block">{summary.total_due.toFixed(2)} د.ت</span>
          </div>
          <div className="bg-white p-4 rounded-xl border border-gray-100 shadow-sm">
            <span className="text-xs text-gray-500 block">إجمالي المقبوض</span>
            <span className="text-xl font-bold text-green-700 mt-1 block">{summary.total_paid.toFixed(2)} د.ت</span>
          </div>
          <div className="bg-white p-4 rounded-xl border border-gray-100 shadow-sm">
            <span className="text-xs text-gray-500 block">إجمالي المتبقي</span>
            <span className="text-xl font-bold text-orange-600 mt-1 block">{summary.total_remaining.toFixed(2)} د.ت</span>
          </div>
        </div>
      )}

      {/* Printable Report Section */}
      <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 printable-report space-y-4">
        <div className="hidden print:block text-center border-b pb-4 mb-4">
          <h2 className="text-xl font-bold">كشف استخلاص النوادي المدرسية</h2>
          <p className="text-xs text-gray-600 mt-1">
            الشهر: {selectedMonth || 'كل الأشهر'} | السنة الدراسية: {years.find((y) => y.id === selectedYearId)?.name || '—'}
          </p>
        </div>

        {loading ? (
          <div className="py-12 text-center text-gray-500 text-sm">جاري تحميل التقرير...</div>
        ) : records.length === 0 ? (
          <div className="py-12 text-center text-gray-400 text-sm">
            لا توجد بيانات مطابقة لخيارات البحث المحددة.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-right text-sm">
              <thead className="bg-gray-50 text-gray-600 text-xs font-semibold uppercase">
                <tr>
                  <th className="p-3">معرف التلميذ</th>
                  <th className="p-3">اسم التلميذ</th>
                  <th className="p-3">القسم</th>
                  <th className="p-3">النادي</th>
                  <th className="p-3 text-left">المستحق (د.ت)</th>
                  <th className="p-3 text-left">المدفوع (د.ت)</th>
                  <th className="p-3 text-left">المتبقي (د.ت)</th>
                  <th className="p-3 text-center">الحالة</th>
                  <th className="p-3 no-print text-center">الإجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {records.map((r: any) => (
                  <tr key={`${r.academic_year_id || selectedYearId}_${r.student_id}_${r.enrollment_id || r.id}_${r.club_id || ''}_${r.month || ''}`} className="hover:bg-gray-50">
                    <td className="p-3 text-gray-500 font-mono">{r.student_code}</td>
                    <td className="p-3 font-semibold text-gray-800">
                      {r.student_name}
                      {r.is_excluded && <span className="mr-2 text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">(مستبعد)</span>}
                    </td>
                    <td className="p-3 text-gray-600">{r.level_name} - {r.section_name}</td>
                    <td className="p-3 font-medium text-gray-700">{r.club_name}</td>
                    <td className="p-3 text-left font-mono font-medium">{r.amount_due.toFixed(2)}</td>
                    <td className="p-3 text-left font-mono text-green-700">{r.amount_paid.toFixed(2)}</td>
                    <td className="p-3 text-left font-mono text-orange-600">{r.remaining.toFixed(2)}</td>
                    <td className="p-3 text-center">
                      <span
                        className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold ${
                          r.status === 'paid'
                            ? 'bg-green-100 text-green-800'
                            : 'bg-orange-100 text-orange-800'
                        }`}
                      >
                        {r.status === 'paid' ? <CheckCircle className="w-3.5 h-3.5" /> : <Clock className="w-3.5 h-3.5" />}
                        {r.status === 'paid' ? 'خلاص كامل' : 'في انتظار الدفع'}
                      </span>
                    </td>
                    <td className="p-3 no-print text-center flex items-center justify-center gap-2">
                      {r.status !== 'paid' && (
                        <button
                          onClick={() => openCollectModal(r)}
                          className="px-3 py-1 bg-[#3B4A36] text-white text-xs rounded hover:bg-[#2E3B2A] transition"
                        >
                          تسجيل الدفع
                        </button>
                      )}
                      {r.subscription_id && (
                        r.is_excluded ? (
                          <button
                            onClick={() => handleRestore(r.subscription_id)}
                            title="إعادة التلميذ للمتابعة"
                            className="p-1 text-gray-500 hover:text-green-600 rounded"
                          >
                            <RotateCcw className="w-4 h-4" />
                          </button>
                        ) : (
                          <button
                            onClick={() => handleExclude(r.subscription_id)}
                            title="استبعاد من متابعة النادي"
                            className="p-1 text-gray-400 hover:text-red-600 rounded"
                          >
                            <UserX className="w-4 h-4" />
                          </button>
                        )
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Printable Summary Footer */}
        {summary && (
          <div className="border-t border-gray-200 pt-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-xs font-medium text-gray-700">
            <div>إجمالي التلاميذ: <span className="font-bold">{summary.enrolled_count}</span></div>
            <div>إجمالي المطلوب: <span className="font-bold">{summary.total_due.toFixed(2)} د.ت</span></div>
            <div>إجمالي المقبوض: <span className="font-bold text-green-700">{summary.total_paid.toFixed(2)} د.ت</span></div>
            <div>إجمالي المتبقي: <span className="font-bold text-orange-600">{summary.total_remaining.toFixed(2)} د.ت</span></div>
          </div>
        )}
      </div>

      {/* Collect Payment Modal */}
      {collectModalRecord && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50 no-print">
          <div className="bg-white rounded-xl shadow-xl max-w-md w-full p-6 space-y-4 text-right">
            <h3 className="text-lg font-bold text-gray-800">تسجيل استخلاص معلوم نادي</h3>
            <p className="text-xs text-gray-500">
              التلميذ: <span className="font-semibold text-gray-700">{collectModalRecord.student_name}</span> ({collectModalRecord.club_name} - {collectModalRecord.month})
            </p>

            <form onSubmit={handleCollectSubmit} className="space-y-4 text-sm">
              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">المبلغ المقبوض (د.ت)</label>
                <input
                  type="number"
                  step="0.001"
                  min="0.01"
                  max={collectModalRecord.remaining > 0 ? collectModalRecord.remaining : collectModalRecord.amount_due}
                  value={payAmount}
                  onChange={(e) => setPayAmount(e.target.value ? Number(e.target.value) : '')}
                  className="w-full border-gray-300 rounded-lg p-2 bg-gray-50"
                  required
                />
                <span className="text-xs text-gray-400 mt-1 block">
                  المتبقي: {collectModalRecord.remaining.toFixed(2)} د.ت
                </span>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">تاريخ القبض</label>
                <input
                  type="date"
                  value={payDate}
                  onChange={(e) => setPayDate(e.target.value)}
                  className="w-full border-gray-300 rounded-lg p-2 bg-gray-50"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">طريقة الدفع</label>
                <select
                  value={payMethod}
                  onChange={(e) => setPayMethod(e.target.value as any)}
                  className="w-full border-gray-300 rounded-lg p-2 bg-gray-50"
                >
                  <option value="cash">نقداً (Cash)</option>
                  <option value="bank_transfer">تحويل بنكي</option>
                  <option value="check">شيك</option>
                  <option value="card">بطاقة بانكية</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">ملاحظات</label>
                <textarea
                  value={payNotes}
                  onChange={(e) => setPayNotes(e.target.value)}
                  className="w-full border-gray-300 rounded-lg p-2 bg-gray-50"
                  rows={2}
                />
              </div>

              <div className="flex justify-between items-center pt-2">
                <button
                  type="button"
                  onClick={() => setCollectModalRecord(null)}
                  className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={submittingPayment}
                  className="px-4 py-2 bg-[#3B4A36] text-white rounded-lg hover:bg-[#2E3B2A] transition disabled:opacity-50"
                >
                  {submittingPayment ? 'جاري التسجيل...' : 'حفظ الاستخلاص'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
