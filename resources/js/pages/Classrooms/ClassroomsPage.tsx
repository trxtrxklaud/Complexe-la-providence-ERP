import { useEffect, useMemo, useState } from 'react';
import { Layers, Plus, Pencil, Trash2, Users, X } from 'lucide-react';
import {
  fetchLevels,
  createLevel,
  updateLevel,
  deleteLevel,
  createSection,
  updateSection,
  deleteSection,
  type Level,
  type Section,
} from '../../api/classrooms';
import { PageDataSkeleton } from '../../components/DataSkeleton';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  bg: '#F4F6F1',
};

type LevelDraft = { id: number | null; name: string; code: string; order: string; description: string };
type SectionDraft = { id: number | null; level_id: number; name: string; code: string; capacity: string };

const emptyLevel: LevelDraft = { id: null, name: '', code: '', order: '1', description: '' };

function errorMessage(err: unknown): string {
  if (err && typeof err === 'object') {
    const anyErr = err as { firstError?: string; message?: string };
    if (anyErr.firstError) return anyErr.firstError;
    if (anyErr.message) return anyErr.message;
  }
  return 'حدث خطأ غير متوقّع';
}

export function ClassroomsPage() {
  const [levels, setLevels] = useState<Level[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);

  const [levelDraft, setLevelDraft] = useState<LevelDraft | null>(null);
  const [sectionDraft, setSectionDraft] = useState<SectionDraft | null>(null);

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      setLevels(await fetchLevels());
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  const totals = useMemo(() => {
    let sections = 0;
    let seats = 0;
    let students = 0;
    levels.forEach((level) => {
      sections += level.sections.length;
      level.sections.forEach((section) => {
        seats += Number(section.capacity) || 0;
        students += Number(section.active_enrollments_count) || 0;
      });
    });
    return { levels: levels.length, sections, seats, students };
  }, [levels]);

  const flash = (message: string) => {
    setNotice(message);
    window.setTimeout(() => setNotice(''), 3000);
  };

  const saveLevel = async () => {
    if (!levelDraft) return;
    setSaving(true);
    setError('');
    try {
      const payload = {
        name: levelDraft.name.trim(),
        code: levelDraft.code.trim(),
        order: Number(levelDraft.order) || 1,
        description: levelDraft.description.trim() || null,
      };
      if (levelDraft.id) await updateLevel(levelDraft.id, payload);
      else await createLevel(payload);
      setLevelDraft(null);
      flash(levelDraft.id ? 'تم تعديل المستوى' : 'تمت إضافة المستوى');
      await load();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const saveSection = async () => {
    if (!sectionDraft) return;
    setSaving(true);
    setError('');
    try {
      const payload = {
        level_id: sectionDraft.level_id,
        name: sectionDraft.name.trim(),
        code: sectionDraft.code.trim(),
        capacity: Number(sectionDraft.capacity) || 1,
      };
      if (sectionDraft.id) await updateSection(sectionDraft.id, payload);
      else await createSection(payload);
      setSectionDraft(null);
      flash(sectionDraft.id ? 'تم تعديل القسم' : 'تمت إضافة القسم');
      await load();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const removeLevel = async (level: Level) => {
    if (!window.confirm('حذف المستوى «' + level.name + '»؟')) return;
    setError('');
    try {
      await deleteLevel(level.id);
      flash('تم حذف المستوى');
      await load();
    } catch (err) {
      setError(errorMessage(err));
    }
  };

  const removeSection = async (section: Section) => {
    if (!window.confirm('حذف القسم «' + section.name + '»؟')) return;
    setError('');
    try {
      await deleteSection(section.id);
      flash('تم حذف القسم');
      await load();
    } catch (err) {
      setError(errorMessage(err));
    }
  };

  const openNewSection = (level: Level) =>
    setSectionDraft({ id: null, level_id: level.id, name: '', code: level.code + '-', capacity: '28' });

  const openEditSection = (section: Section) =>
    setSectionDraft({
      id: section.id,
      level_id: section.level_id,
      name: section.name,
      code: section.code,
      capacity: String(section.capacity),
    });

  const inputStyle = {
    border: '1px solid ' + C.line,
    backgroundColor: '#fff',
    color: C.ink,
  };

  return (
    <div className="p-6" dir="rtl" style={{ backgroundColor: C.bg, minHeight: '100vh' }}>
      <div className="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <div className="p-3 rounded-2xl" style={{ backgroundColor: C.sage }}>
            <Layers size={22} color={C.forest} />
          </div>
          <div>
            <h1 className="text-2xl font-bold" style={{ color: C.ink }}>المستويات والأقسام</h1>
            <p className="text-sm" style={{ color: C.muted }}>بنية المدرسة التي يُبنى عليها التسجيل والاستخلاص</p>
          </div>
        </div>

        <button
          onClick={() => setLevelDraft({ ...emptyLevel, order: String(levels.length + 1) })}
          className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-white text-sm font-medium"
          style={{ backgroundColor: C.forest }}
        >
          <Plus size={18} />
          <span>مستوى جديد</span>
        </button>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        {[
          { label: 'المستويات', value: totals.levels },
          { label: 'الأقسام', value: totals.sections },
          { label: 'الطاقة الاستيعابية', value: totals.seats },
          { label: 'التلاميذ المسجّلون', value: totals.students },
        ].map((card) => (
          <div key={card.label} className="bg-white rounded-2xl p-4" style={{ border: '1px solid ' + C.line }}>
            <p className="text-xs mb-1" style={{ color: C.muted }}>{card.label}</p>
            <p className="text-2xl font-bold" style={{ color: C.deep }}>{card.value}</p>
          </div>
        ))}
      </div>

      {error && (
        <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: '#FDECEC', color: '#A03434' }}>
          {error}
        </div>
      )}
      {notice && (
        <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.sage, color: C.deep }}>
          {notice}
        </div>
      )}

      {loading ? (
        <PageDataSkeleton cards={3} rows={4} />
      ) : levels.length === 0 ? (
        <div className="bg-white rounded-2xl p-10 text-center" style={{ border: '1px solid ' + C.line }}>
          <p style={{ color: C.muted }}>لا توجد مستويات بعد. ابدأ بإضافة مستوى.</p>
        </div>
      ) : (
        <div className="space-y-4">
          {levels.map((level) => {
            const seats = level.sections.reduce((sum, s) => sum + (Number(s.capacity) || 0), 0);
            const students = level.sections.reduce((sum, s) => sum + (Number(s.active_enrollments_count) || 0), 0);

            return (
              <div key={level.id} className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
                <div className="flex items-center justify-between gap-3 px-5 py-4 flex-wrap" style={{ backgroundColor: C.sage }}>
                  <div>
                    <h2 className="font-bold text-lg" style={{ color: C.deep }}>
                      {level.name}
                      <span className="text-xs font-normal mr-2" style={{ color: C.muted }}>({level.code})</span>
                    </h2>
                    <p className="text-xs mt-0.5" style={{ color: C.muted }}>
                      {level.sections.length} أقسام · {students}/{seats} تلميذ
                    </p>
                  </div>

                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => openNewSection(level)}
                      className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-white"
                      style={{ backgroundColor: C.forest }}
                    >
                      <Plus size={15} />
                      <span>قسم</span>
                    </button>
                    <button
                      onClick={() =>
                        setLevelDraft({
                          id: level.id,
                          name: level.name,
                          code: level.code,
                          order: String(level.order),
                          description: level.description ?? '',
                        })
                      }
                      className="p-2 rounded-lg bg-white"
                      title="تعديل المستوى"
                    >
                      <Pencil size={15} color={C.forest} />
                    </button>
                    <button
                      onClick={() => void removeLevel(level)}
                      className="p-2 rounded-lg bg-white"
                      title="حذف المستوى"
                    >
                      <Trash2 size={15} color="#A03434" />
                    </button>
                  </div>
                </div>

                {level.sections.length === 0 ? (
                  <p className="px-5 py-6 text-sm" style={{ color: C.muted }}>لا أقسام في هذا المستوى.</p>
                ) : (
                  <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 p-5">
                    {level.sections.map((section) => {
                      const enrolled = Number(section.active_enrollments_count) || 0;
                      const capacity = Number(section.capacity) || 0;
                      const full = capacity > 0 && enrolled >= capacity;

                      return (
                        <div key={section.id} className="rounded-xl p-4" style={{ border: '1px solid ' + C.line }}>
                          <div className="flex items-start justify-between gap-2">
                            <div>
                              <p className="font-bold" style={{ color: C.ink }}>{section.name}</p>
                              <p className="text-xs" style={{ color: C.muted }}>{section.code}</p>
                            </div>
                            <div className="flex gap-1">
                              <button onClick={() => openEditSection(section)} className="p-1.5 rounded-lg" title="تعديل">
                                <Pencil size={14} color={C.forest} />
                              </button>
                              <button onClick={() => void removeSection(section)} className="p-1.5 rounded-lg" title="حذف">
                                <Trash2 size={14} color="#A03434" />
                              </button>
                            </div>
                          </div>

                          <div className="flex items-center gap-1.5 mt-3 text-xs" style={{ color: full ? '#A03434' : C.muted }}>
                            <Users size={14} />
                            <span>{enrolled} / {capacity}</span>
                            {full && <span className="font-medium">— مكتمل</span>}
                          </div>

                          <div className="h-1.5 rounded-full mt-2 overflow-hidden" style={{ backgroundColor: C.line }}>
                            <div
                              className="h-full rounded-full"
                              style={{
                                width: Math.min(100, capacity ? (enrolled / capacity) * 100 : 0) + '%',
                                backgroundColor: full ? '#A03434' : C.forest,
                              }}
                            />
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {levelDraft && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(31,38,28,0.45)' }}>
          <div className="bg-white rounded-2xl w-full max-w-md p-6" dir="rtl">
            <div className="flex items-center justify-between mb-5">
              <h3 className="font-bold text-lg" style={{ color: C.ink }}>
                {levelDraft.id ? 'تعديل مستوى' : 'مستوى جديد'}
              </h3>
              <button onClick={() => setLevelDraft(null)}><X size={20} color={C.muted} /></button>
            </div>

            <div className="space-y-4">
              <div>
                <label htmlFor="level-draft-name" className="block text-sm mb-1.5" style={{ color: C.ink }}>اسم المستوى</label>
                <input
                  id="level-draft-name"
                  value={levelDraft.name}
                  onChange={(e) => setLevelDraft({ ...levelDraft, name: e.target.value })}
                  className="w-full px-3 py-2.5 rounded-xl text-sm"
                  style={inputStyle}
                  placeholder="السنة الأولى"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label htmlFor="level-draft-code" className="block text-sm mb-1.5" style={{ color: C.ink }}>الرمز</label>
                  <input
                    id="level-draft-code"
                    value={levelDraft.code}
                    onChange={(e) => setLevelDraft({ ...levelDraft, code: e.target.value })}
                    className="w-full px-3 py-2.5 rounded-xl text-sm"
                    style={{ ...inputStyle, direction: 'ltr' }}
                    placeholder="L1"
                  />
                </div>
                <div>
                  <label htmlFor="level-draft-order" className="block text-sm mb-1.5" style={{ color: C.ink }}>الترتيب</label>
                  <input
                    id="level-draft-order"
                    type="number"
                    min={1}
                    value={levelDraft.order}
                    onChange={(e) => setLevelDraft({ ...levelDraft, order: e.target.value })}
                    className="w-full px-3 py-2.5 rounded-xl text-sm"
                    style={inputStyle}
                  />
                </div>
              </div>

              <div>
                <label htmlFor="level-draft-description" className="block text-sm mb-1.5" style={{ color: C.ink }}>الوصف (اختياري)</label>
                <textarea
                  id="level-draft-description"
                  value={levelDraft.description}
                  onChange={(e) => setLevelDraft({ ...levelDraft, description: e.target.value })}
                  rows={2}
                  className="w-full px-3 py-2.5 rounded-xl text-sm"
                  style={inputStyle}
                />
              </div>
            </div>

            <div className="flex gap-3 mt-6">
              <button
                onClick={() => void saveLevel()}
                disabled={saving || !levelDraft.name.trim() || !levelDraft.code.trim()}
                className="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: C.forest }}
              >
                {saving ? 'جارٍ الحفظ…' : 'حفظ'}
              </button>
              <button
                onClick={() => setLevelDraft(null)}
                className="px-5 py-2.5 rounded-xl text-sm"
                style={{ border: '1px solid ' + C.line, color: C.muted }}
              >
                إلغاء
              </button>
            </div>
          </div>
        </div>
      )}

      {sectionDraft && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(31,38,28,0.45)' }}>
          <div className="bg-white rounded-2xl w-full max-w-md p-6" dir="rtl">
            <div className="flex items-center justify-between mb-5">
              <h3 className="font-bold text-lg" style={{ color: C.ink }}>
                {sectionDraft.id ? 'تعديل قسم' : 'قسم جديد'}
              </h3>
              <button onClick={() => setSectionDraft(null)}><X size={20} color={C.muted} /></button>
            </div>

            <div className="space-y-4">
              <div>
                <label htmlFor="section-draft-level" className="block text-sm mb-1.5" style={{ color: C.ink }}>المستوى</label>
                <select
                  id="section-draft-level"
                  value={sectionDraft.level_id}
                  onChange={(e) => setSectionDraft({ ...sectionDraft, level_id: Number(e.target.value) })}
                  className="w-full px-3 py-2.5 rounded-xl text-sm"
                  style={inputStyle}
                >
                  {levels.map((level) => (
                    <option key={level.id} value={level.id}>{level.name}</option>
                  ))}
                </select>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label htmlFor="section-draft-name" className="block text-sm mb-1.5" style={{ color: C.ink }}>اسم القسم</label>
                  <input
                    id="section-draft-name"
                    value={sectionDraft.name}
                    onChange={(e) => setSectionDraft({ ...sectionDraft, name: e.target.value })}
                    className="w-full px-3 py-2.5 rounded-xl text-sm"
                    style={inputStyle}
                    placeholder="أ"
                  />
                </div>
                <div>
                  <label htmlFor="section-draft-capacity" className="block text-sm mb-1.5" style={{ color: C.ink }}>السعة</label>
                  <input
                    id="section-draft-capacity"
                    type="number"
                    min={1}
                    value={sectionDraft.capacity}
                    onChange={(e) => setSectionDraft({ ...sectionDraft, capacity: e.target.value })}
                    className="w-full px-3 py-2.5 rounded-xl text-sm"
                    style={inputStyle}
                  />
                </div>
              </div>

              <div>
                <label htmlFor="section-draft-code" className="block text-sm mb-1.5" style={{ color: C.ink }}>الرمز</label>
                <input
                  id="section-draft-code"
                  value={sectionDraft.code}
                  onChange={(e) => setSectionDraft({ ...sectionDraft, code: e.target.value })}
                  className="w-full px-3 py-2.5 rounded-xl text-sm"
                  style={{ ...inputStyle, direction: 'ltr' }}
                  placeholder="L1-أ"
                />
              </div>
            </div>

            <div className="flex gap-3 mt-6">
              <button
                onClick={() => void saveSection()}
                disabled={saving || !sectionDraft.name.trim() || !sectionDraft.code.trim()}
                className="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: C.forest }}
              >
                {saving ? 'جارٍ الحفظ…' : 'حفظ'}
              </button>
              <button
                onClick={() => setSectionDraft(null)}
                className="px-5 py-2.5 rounded-xl text-sm"
                style={{ border: '1px solid ' + C.line, color: C.muted }}
              >
                إلغاء
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default ClassroomsPage;
