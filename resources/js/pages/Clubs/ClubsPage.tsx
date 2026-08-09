import React, { useState, useEffect } from 'react';
import {
  fetchClubs,
  createClub,
  updateClub,
  deleteClub,
  fetchClubSubscriptions,
  subscribeStudentToClub,
  cancelClubSubscription,
  ClubItem,
  ClubSubscriptionItem,
} from '../../api/clubs';
import { fetchLevels } from '../../api/classrooms';
import { fetchAcademicYears } from '../../api/years';
import { Level, AcademicYear } from '../../types';
import { Plus, Edit2, Trash2, Users, UserPlus, X } from 'lucide-react';

export default function ClubsPage() {
  const [clubs, setClubs] = useState<ClubItem[]>([]);
  const [levels, setLevels] = useState<Level[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  // Club Create/Edit Modal State
  const [showClubModal, setShowClubModal] = useState(false);
  const [editingClub, setEditingClub] = useState<ClubItem | null>(null);
  const [clubName, setClubName] = useState('');
  const [clubDesc, setClubDesc] = useState('');
  const [clubFee, setClubFee] = useState<number | ''>('');
  const [clubActive, setClubActive] = useState(true);
  const [selectedLevels, setSelectedLevels] = useState<number[]>([]);
  const [savingClub, setSavingClub] = useState(false);

  // Subscriptions Modal State
  const [selectedClubForSub, setSelectedClubForSub] = useState<ClubItem | null>(null);
  const [subscriptions, setSubscriptions] = useState<ClubSubscriptionItem[]>([]);
  const [subStudentId, setSubStudentId] = useState<string>('');
  const [subYearId, setSubYearId] = useState<number | ''>('');
  const [subFeeOverride, setSubFeeOverride] = useState<number | ''>('');
  const [submittingSub, setSubmittingSub] = useState(false);

  useEffect(() => {
    loadData();
  }, []);

  const loadData = async () => {
    setLoading(true);
    try {
      const [cList, lList, yList] = await Promise.all([
        fetchClubs(),
        fetchLevels(),
        fetchAcademicYears(),
      ]);
      setClubs(cList);
      setLevels(lList);
      setYears(yList);

      const activeY = yList.find((y) => y.is_active);
      if (activeY) setSubYearId(activeY.id);
    } catch (err: any) {
      setError(err.message || 'خطأ أثناء تحميل البيانات');
    } finally {
      setLoading(false);
    }
  };

  const openCreateModal = () => {
    setEditingClub(null);
    setClubName('');
    setClubDesc('');
    setClubFee('');
    setClubActive(true);
    setSelectedLevels([]);
    setShowClubModal(true);
  };

  const openEditModal = (club: ClubItem) => {
    setEditingClub(club);
    setClubName(club.name);
    setClubDesc(club.description || '');
    setClubFee(Number(club.monthly_fee));
    setClubActive(club.is_active);
    setSelectedLevels(club.levels ? club.levels.map((l) => l.id) : []);
    setShowClubModal(true);
  };

  const handleSaveClub = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!clubName.trim() || clubFee === '' || Number(clubFee) < 0) return;

    setSavingClub(true);
    setError(null);
    try {
      let savedRes: ClubItem;
      if (editingClub) {
        savedRes = await updateClub(editingClub.id, {
          name: clubName.trim(),
          description: clubDesc.trim() || undefined,
          monthly_fee: Number(clubFee),
          is_active: clubActive,
          level_ids: selectedLevels,
        });
        const updatedCount = (savedRes as any)?.updated_unpaid_count ?? 0;
        const feeFormatted = Number(clubFee).toFixed(2);
        setSuccessMsg(
          `تم تحديث النادي بنجاح بمبلغ ${feeFormatted} د.ت${
            updatedCount > 0 ? ` وتحديث ${updatedCount} سجلاً غير مدفوع للشهر الحالي` : ''
          }`
        );
      } else {
        savedRes = await createClub({
          name: clubName.trim(),
          description: clubDesc.trim() || undefined,
          monthly_fee: Number(clubFee),
          is_active: clubActive,
          level_ids: selectedLevels,
        });
        setSuccessMsg(`تم إنشاء النادي بنجاح بمبلغ ${Number(clubFee).toFixed(2)} د.ت`);
      }

      window.dispatchEvent(new CustomEvent('club-fee-updated', { detail: savedRes }));
      setShowClubModal(false);
      loadData();
    } catch (err: any) {
      setError(err.message || 'فشل حفظ النادي');
    } finally {
      setSavingClub(false);
    }
  };

  const handleDeleteClub = async (id: number) => {
    if (!window.confirm('هل أنت تأكد من حذف أو تعطيل هذا النادي؟')) return;
    try {
      await deleteClub(id);
      setSuccessMsg('تم الحذف/التعطيل بنجاح');
      loadData();
    } catch (err: any) {
      setError(err.message || 'فشل الحذف');
    }
  };

  const openSubscriptionsModal = async (club: ClubItem) => {
    setSelectedClubForSub(club);
    setSubStudentId('');
    setSubFeeOverride('');
    try {
      const res = await fetchClubSubscriptions({ club_id: club.id });
      setSubscriptions(res.data || []);
    } catch (err: any) {
      console.error(err);
    }
  };

  const handleSubscribeSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedClubForSub || !subStudentId || !subYearId) return;

    setSubmittingSub(true);
    setError(null);
    try {
      await subscribeStudentToClub({
        student_id: Number(subStudentId),
        club_id: selectedClubForSub.id,
        academic_year_id: Number(subYearId),
        monthly_fee_override: subFeeOverride !== '' ? Number(subFeeOverride) : undefined,
      });
      setSuccessMsg('تم تسجيل التلميذ في النادي بنجاح');
      setSubStudentId('');
      setSubFeeOverride('');
      const res = await fetchClubSubscriptions({ club_id: selectedClubForSub.id });
      setSubscriptions(res.data || []);
    } catch (err: any) {
      setError(err.message || 'فشل تسجيل التلميذ في النادي');
    } finally {
      setSubmittingSub(false);
    }
  };

  const handleCancelSub = async (subId: number) => {
    if (!window.confirm('هل تريد إلغاء اشتراك هذا التلميذ في النادي؟')) return;
    try {
      await cancelClubSubscription(subId);
      if (selectedClubForSub) {
        const res = await fetchClubSubscriptions({ club_id: selectedClubForSub.id });
        setSubscriptions(res.data || []);
      }
    } catch (err: any) {
      setError(err.message || 'فشل إلغاء الاشتراك');
    }
  };

  return (
    <div className="space-y-6 dir-rtl">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">إدارة النوادي المدرسية</h1>
          <p className="text-sm text-gray-500">تعريف النوادي، ضبط المعلوم الشهري، وتحديد المستويات والتلاميذ المسجلين</p>
        </div>
        <button
          onClick={openCreateModal}
          className="flex items-center gap-2 px-4 py-2 bg-[#3B4A36] text-white rounded-lg hover:bg-[#2E3B2A] transition"
        >
          <Plus className="w-4 h-4" />
          إضافة نادي جديد
        </button>
      </div>

      {error && (
        <div className="p-4 bg-red-50 text-red-700 rounded-lg border border-red-200 flex justify-between">
          <span>{error}</span>
          <button onClick={() => setError(null)} className="text-sm underline">إغلاق</button>
        </div>
      )}

      {successMsg && (
        <div className="p-4 bg-green-50 text-green-700 rounded-lg border border-green-200 flex justify-between">
          <span>{successMsg}</span>
          <button onClick={() => setSuccessMsg(null)} className="text-sm underline">إغلاق</button>
        </div>
      )}

      {/* Clubs Grid */}
      {loading ? (
        <div className="py-12 text-center text-gray-500">جاري التحميل...</div>
      ) : clubs.length === 0 ? (
        <div className="py-12 text-center text-gray-400 bg-white rounded-xl shadow-sm p-8">
          لا توجد نوادي مسجلة حالياً. انقر على "إضافة نادي جديد" لإنشاء أول نادي.
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {clubs.map((c) => (
            <div key={c.id} className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 space-y-4 flex flex-col justify-between">
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <h3 className="font-bold text-lg text-gray-800">{c.name}</h3>
                  <span className={`px-2.5 py-0.5 rounded-full text-xs font-semibold ${c.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'}`}>
                    {c.is_active ? 'نشط' : 'معطَّل'}
                  </span>
                </div>
                {c.description && <p className="text-xs text-gray-500 line-clamp-2">{c.description}</p>}
                
                <div className="pt-2 flex justify-between items-center text-sm">
                  <span className="text-gray-500">المعلوم الشهري:</span>
                  <span className="font-bold text-[#3B4A36]">{Number(c.monthly_fee).toFixed(2)} د.ت</span>
                </div>

                <div className="text-xs text-gray-500">
                  <span className="font-semibold block mb-1">المستويات المتاحة:</span>
                  {c.levels && c.levels.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                      {c.levels.map((l) => (
                        <span key={l.id} className="px-2 py-0.5 bg-gray-100 rounded text-gray-700">{l.name}</span>
                      ))}
                    </div>
                  ) : (
                    <span className="text-gray-400">متاح لكل المستويات</span>
                  )}
                </div>
              </div>

              <div className="pt-4 border-t border-gray-100 flex items-center justify-between">
                <button
                  onClick={() => openSubscriptionsModal(c)}
                  className="flex items-center gap-1.5 text-xs text-[#3B4A36] font-semibold hover:underline"
                >
                  <Users className="w-4 h-4" />
                  المسجلون بالنادي
                </button>
                <div className="flex items-center gap-2">
                  <button onClick={() => openEditModal(c)} className="p-1.5 text-gray-500 hover:text-blue-600 rounded">
                    <Edit2 className="w-4 h-4" />
                  </button>
                  <button onClick={() => handleDeleteClub(c.id)} className="p-1.5 text-gray-500 hover:text-red-600 rounded">
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Create/Edit Club Modal */}
      {showClubModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
          <div className="bg-white rounded-xl shadow-xl max-w-md w-full p-6 space-y-4 text-right">
            <h3 className="text-lg font-bold text-gray-800">{editingClub ? 'تعديل بيانات النادي' : 'إضافة نادي جديد'}</h3>
            <form onSubmit={handleSaveClub} className="space-y-4 text-sm">
              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">اسم النادي</label>
                <input
                  type="text"
                  value={clubName}
                  onChange={(e) => setClubName(e.target.value)}
                  className="w-full border-gray-300 rounded-lg p-2 bg-gray-50"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">الوصف</label>
                <textarea
                  value={clubDesc}
                  onChange={(e) => setClubDesc(e.target.value)}
                  className="w-full border-gray-300 rounded-lg p-2 bg-gray-50"
                  rows={2}
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">المعلوم الشهري (د.ت)</label>
                <input
                  type="number"
                  step="0.001"
                  min="0"
                  value={clubFee}
                  onChange={(e) => setClubFee(e.target.value ? Number(e.target.value) : '')}
                  className="w-full border-gray-300 rounded-lg p-2 bg-gray-50"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">المستويات المسموح لها بالدراسة</label>
                <div className="max-h-36 overflow-y-auto border border-gray-200 rounded-lg p-2 space-y-1">
                  {levels.map((l) => (
                    <label key={l.id} className="flex items-center gap-2 text-xs cursor-pointer hover:bg-gray-50 p-1 rounded">
                      <input
                        type="checkbox"
                        checked={selectedLevels.includes(l.id)}
                        onChange={(e) => {
                          if (e.target.checked) setSelectedLevels([...selectedLevels, l.id]);
                          else setSelectedLevels(selectedLevels.filter((id) => id !== l.id));
                        }}
                      />
                      <span>{l.name}</span>
                    </label>
                  ))}
                </div>
              </div>

              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="active_check"
                  checked={clubActive}
                  onChange={(e) => setClubActive(e.target.checked)}
                />
                <label htmlFor="active_check" className="text-xs text-gray-700">النادي نشط وقابل للتسجيل</label>
              </div>

              <div className="flex justify-between items-center pt-2">
                <button
                  type="button"
                  onClick={() => setShowClubModal(false)}
                  className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={savingClub}
                  className="px-4 py-2 bg-[#3B4A36] text-white rounded-lg hover:bg-[#2E3B2A] disabled:opacity-50"
                >
                  {savingClub ? 'جاري الحفظ...' : 'حفظ'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Subscriptions Modal */}
      {selectedClubForSub && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
          <div className="bg-white rounded-xl shadow-xl max-w-2xl w-full p-6 space-y-4 text-right">
            <div className="flex justify-between items-center border-b border-gray-100 pb-3">
              <h3 className="text-lg font-bold text-gray-800">التلاميذ المسجلون في: {selectedClubForSub.name}</h3>
              <button onClick={() => setSelectedClubForSub(null)} className="text-gray-400 hover:text-gray-600">
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Subscribe Form */}
            <form onSubmit={handleSubscribeSubmit} className="p-3 bg-gray-50 rounded-lg space-y-3">
              <span className="block text-xs font-semibold text-gray-700">تسجيل تلميذ جديد بالنادي:</span>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <input
                  type="number"
                  placeholder="معرّف/ID التلميذ"
                  value={subStudentId}
                  onChange={(e) => setSubStudentId(e.target.value)}
                  className="text-xs border-gray-300 rounded p-2"
                  required
                />
                <input
                  type="number"
                  step="0.01"
                  placeholder="سعر خاص (اختياري)"
                  value={subFeeOverride}
                  onChange={(e) => setSubFeeOverride(e.target.value ? Number(e.target.value) : '')}
                  className="text-xs border-gray-300 rounded p-2"
                />
                <button
                  type="submit"
                  disabled={submittingSub || !subStudentId}
                  className="flex items-center justify-center gap-1 text-xs bg-[#3B4A36] text-white rounded p-2 hover:bg-[#2E3B2A] disabled:opacity-50"
                >
                  <UserPlus className="w-3.5 h-3.5" />
                  إضافة للنادي
                </button>
              </div>
            </form>

            {/* Subscriptions Table */}
            <div className="max-h-60 overflow-y-auto">
              <table className="w-full text-right text-xs">
                <thead>
                  <tr className="bg-gray-100 text-gray-600">
                    <th className="p-2">رمز التلميذ</th>
                    <th className="p-2">اسم التلميذ</th>
                    <th className="p-2">تاريخ البدء</th>
                    <th className="p-2">الحالة</th>
                    <th className="p-2 text-center">إلغاء</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {subscriptions.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="p-4 text-center text-gray-400">لا يوجد تلاميذ مسجلون في هذا النادي بعد.</td>
                    </tr>
                  ) : (
                    subscriptions.map((s) => (
                      <tr key={s.id}>
                        <td className="p-2 text-gray-500">{s.student?.student_code}</td>
                        <td className="p-2 font-semibold">{s.student ? `${s.student.first_name} ${s.student.last_name}` : '-'}</td>
                        <td className="p-2 text-gray-500">{s.start_date}</td>
                        <td className="p-2">
                          <span className={`px-2 py-0.5 rounded text-xs ${s.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
                            {s.status === 'active' ? 'نشط' : 'ملغى'}
                          </span>
                        </td>
                        <td className="p-2 text-center">
                          {s.status === 'active' && (
                            <button onClick={() => handleCancelSub(s.id)} className="text-red-600 hover:underline">
                              إلغاء الاشتراك
                            </button>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
