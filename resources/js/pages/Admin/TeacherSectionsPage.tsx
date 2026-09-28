import React, { useEffect, useMemo, useState } from 'react';
import {
  Users,
  BookOpen,
  Plus,
  Trash2,
  Undo2,
  CheckCircle2,
  AlertCircle,
  RefreshCw,
  Search,
  School,
  GraduationCap,
  Info,
  X,
  Layers,
  Phone,
  Mail,
  UserCheck,
  UserMinus,
  ArrowLeftRight,
} from 'lucide-react';
import {
  fetchTeachers,
  fetchSections,
  fetchTeacherSections,
  assignTeacherSection,
  removeTeacherSection,
  deleteTeacherSections,
  type Teacher,
  type Section,
  type AssignedSection,
} from '../../api/teacherSections';

const C = {
  forest: '#1B4332',
  forestLight: '#2D6A4F',
  sage: '#E3EBDB',
  sageLight: '#F0F5EC',
  ink: '#1F261C',
  muted: '#6B7A66',
  line: '#E2E8DC',
  cardBg: '#FFFFFF',
  pageBg: '#F7F5EF',
  amber: '#D97706',
  amberLight: '#FEF3C7',
  rose: '#BE123C',
  roseLight: '#FFE4E6',
};

interface AssignUndoAction {
  type: 'assign';
  employeeId: number;
  teacherName: string;
  sectionId: number;
  sectionName: string;
  subject?: string | null;
  timestamp: number;
}

interface RemoveUndoAction {
  type: 'remove';
  employeeId: number;
  teacherName: string;
  sectionId: number;
  sectionName: string;
  subject?: string | null;
  timestamp: number;
}

interface DeleteTeacherUndoAction {
  type: 'delete' | 'delete_teacher';
  employeeId: number;
  teacherName: string;
  teacherObj?: Teacher;
  sections: AssignedSection[];
  replacementEmployeeId?: number | null;
  replacementTeacherName?: string | null;
  timestamp: number;
}

type UndoAction = AssignUndoAction | RemoveUndoAction | DeleteTeacherUndoAction;

interface ToastState {
  message: string;
  type: 'success' | 'error' | 'warning';
  action?: {
    label: string;
    onClick: () => void;
  };
}

const COMMON_SUBJECTS = [
  'اللغة العربية',
  'اللغة الفرنسية',
  'الرياضيات',
  'اللغة الإنجليزية',
  'الإعلامية',
  'التربية البدنية',
  'إيقاظ علمي',
  'عام / كافة المواد',
];

export function TeacherSectionsPage() {
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [sections, setSections] = useState<Section[]>([]);
  const [selectedTeacherId, setSelectedTeacherId] = useState<number | null>(null);
  const [assignedSections, setAssignedSections] = useState<AssignedSection[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [assigning, setAssigning] = useState(false);

  // Form states
  const [selectedSectionId, setSelectedSectionId] = useState<number | ''>('');
  const [subject, setSubject] = useState('');
  const [teacherSearch, setTeacherSearch] = useState('');
  const [sectionFilter, setSectionFilter] = useState('');

  // Undo & Toast states
  const [undoStack, setUndoStack] = useState<UndoAction[]>([]);
  const [toast, setToast] = useState<ToastState | null>(null);

  // Confirmation Modal for single section remove
  const [confirmDelete, setConfirmDelete] = useState<{
    sectionId: number;
    sectionName: string;
  } | null>(null);

  // Modal for Total Removal (حذف كلي للمعلم ونقل أقسامه)
  const [deleteTeacherModal, setDeleteTeacherModal] = useState<{
    teacherId: number;
    teacherName: string;
    sections: AssignedSection[];
  } | null>(null);
  const [replacementTeacherId, setReplacementTeacherId] = useState<number | ''>('');
  const [executingTeacherDelete, setExecutingTeacherDelete] = useState(false);

  // 1. تحميل كافة البيانات الأساسية
  const loadInitialData = async () => {
    try {
      const [tList, sList] = await Promise.all([fetchTeachers(), fetchSections()]);
      setTeachers(tList);
      setSections(sList);

      if (!selectedTeacherId && tList.length > 0) {
        setSelectedTeacherId(tList[0].id);
      }
    } catch (err) {
      showToast('تعذّر تحميل البيانات. يرجى إعادة المحاولة.', 'error');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    void loadInitialData();
  }, []);

  // 2. تحميل أقسام المعلم عند اختياره
  const loadTeacherDetails = async (teacherId: number) => {
    try {
      const res = await fetchTeacherSections(teacherId);
      setAssignedSections(res.sections || []);
    } catch (err) {
      showToast('تعذّر تحميل أقسام المعلم المحدد', 'error');
    }
  };

  useEffect(() => {
    if (selectedTeacherId) {
      void loadTeacherDetails(selectedTeacherId);
      setSelectedSectionId('');
      setSubject('');
    } else {
      setAssignedSections([]);
    }
  }, [selectedTeacherId]);

  // إشعار Toast تفاعلي
  const showToast = (
    message: string,
    type: 'success' | 'error' | 'warning' = 'success',
    action?: { label: string; onClick: () => void } | (() => void)
  ) => {
    let actionObj: { label: string; onClick: () => void } | undefined;
    if (typeof action === 'function') {
      actionObj = { label: 'تراجع', onClick: action };
    } else if (action) {
      actionObj = action;
    }
    setToast({ message, type, action: actionObj });
  };

  useEffect(() => {
    if (!toast) return;
    const timer = window.setTimeout(() => setToast(null), 6000);
    return () => window.clearTimeout(timer);
  }, [toast]);

  // المعلم المختار حالياً
  const selectedTeacher = useMemo(() => {
    return teachers.find((t) => t.id === selectedTeacherId) || null;
  }, [teachers, selectedTeacherId]);

  // تصفية قائمة المعلمين حسب البحث
  const filteredTeachers = useMemo(() => {
    if (!teacherSearch.trim()) return teachers;
    const q = teacherSearch.toLowerCase().trim();
    return teachers.filter(
      (t) =>
        t.name.toLowerCase().includes(q) ||
        (t.phone && t.phone.includes(q)) ||
        (t.email && t.email.toLowerCase().includes(q))
    );
  }, [teachers, teacherSearch]);

  // الأقسام المتاحة للإسناد (التي لم تُسند لهذا المعلم بعد)
  const availableSections = useMemo(() => {
    const assignedIds = new Set(assignedSections.map((as) => as.section.id));
    let list = sections.filter((s) => !assignedIds.has(s.id));
    if (sectionFilter.trim()) {
      const q = sectionFilter.toLowerCase().trim();
      list = list.filter(
        (s) => s.name.toLowerCase().includes(q) || s.level.name.toLowerCase().includes(q)
      );
    }
    return list;
  }, [sections, assignedSections, sectionFilter]);

  // الأقسام مجمعة حسب المستوى
  const availableSectionsByLevel = useMemo(() => {
    const map = new Map<string, Section[]>();
    availableSections.forEach((s) => {
      const lvl = s.level.name;
      if (!map.has(lvl)) map.set(lvl, []);
      map.get(lvl)!.push(s);
    });
    return map;
  }, [availableSections]);

  // إحصائيات سريعة
  const stats = useMemo(() => {
    const totalTeachers = teachers.length;
    const totalSections = sections.length;
    const assignedCount = assignedSections.length;
    const sectionsWithTeachers = sections.filter((s) => s.teacher_count > 0).length;
    return { totalTeachers, totalSections, assignedCount, sectionsWithTeachers };
  }, [teachers, sections, assignedSections]);

  // 3. إسناد قسم
  const handleAssign = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!selectedTeacherId || !selectedSectionId) return;

    const targetSection = sections.find((s) => s.id === Number(selectedSectionId));
    if (!targetSection) return;

    setAssigning(true);
    try {
      const res = await assignTeacherSection({
        employee_id: selectedTeacherId,
        section_id: Number(selectedSectionId),
        subject: subject.trim() || null,
      });

      // إضافة القسم للقائمة المحلية
      setAssignedSections((prev) => [...prev, res.data]);

      // تحديث عداد المعلمين في قائمة الأقسام
      setSections((prev) =>
        prev.map((s) => (s.id === targetSection.id ? { ...s, teacher_count: s.teacher_count + 1 } : s))
      );

      // حفظ في سِجِلّ التراجع Undo Stack
      const undoItem: AssignUndoAction = {
        type: 'assign',
        employeeId: selectedTeacherId,
        teacherName: selectedTeacher?.name || 'المعلم',
        sectionId: targetSection.id,
        sectionName: `${targetSection.level.name} — ${targetSection.name}`,
        subject: subject.trim() || null,
        timestamp: Date.now(),
      };
      setUndoStack((prev) => [...prev, undoItem]);

      // رسالة التنبيه إن وُجد معلّمون آخرون في القسم
      if (res.other_teachers && res.other_teachers.length > 0) {
        const names = res.other_teachers
          .map((t) => `${t.name}${t.subject ? ` (${t.subject})` : ''}`)
          .join('، ');
        showToast(
          `تم إسناد قسم (${targetSection.name}) بنجاح. تنبيه: القسم مسند أيضاً لـ: ${names}`,
          'warning',
          {
            label: 'تراجع',
            onClick: () => void handleUndoAction(undoItem),
          }
        );
      } else {
        showToast(`تم إسناد قسم (${targetSection.level.name} — ${targetSection.name}) بنجاح!`, 'success', {
          label: 'تراجع',
          onClick: () => void handleUndoAction(undoItem),
        });
      }

      // تصفير النموذج
      setSelectedSectionId('');
      setSubject('');
    } catch (err: any) {
      showToast(err?.firstError || err?.message || 'تعذّر إسناد هذا القسم للمعلم', 'error');
    } finally {
      setAssigning(false);
    }
  };

  // 4. حذف إسناد قسم واحد
  const handleRemove = async (sectionId: number, sectionName: string) => {
    if (!selectedTeacherId) return;

    const assigned = assignedSections.find((a) => a.section.id === sectionId);
    const savedSubject = assigned?.subject || null;

    try {
      await removeTeacherSection({
        employee_id: selectedTeacherId,
        section_id: sectionId,
      });

      // حذف من القائمة المحلية
      setAssignedSections((prev) => prev.filter((a) => a.section.id !== sectionId));

      // تحديث عداد المعلمين في قائمة الأقسام
      setSections((prev) =>
        prev.map((s) =>
          s.id === sectionId ? { ...s, teacher_count: Math.max(0, s.teacher_count - 1) } : s
        )
      );

      // حفظ في سِجلّ التراجع
      const undoItem: RemoveUndoAction = {
        type: 'remove',
        employeeId: selectedTeacherId,
        teacherName: selectedTeacher?.name || 'المعلم',
        sectionId,
        sectionName,
        subject: savedSubject,
        timestamp: Date.now(),
      };
      setUndoStack((prev) => [...prev, undoItem]);

      showToast(`تم حذف إسناد قسم (${sectionName})`, 'success', {
        label: 'تراجع',
        onClick: () => void handleUndoAction(undoItem),
      });
    } catch (err: any) {
      showToast(err?.firstError || err?.message || 'تعذّر حذف إسناد القسم', 'error');
    } finally {
      setConfirmDelete(null);
    }
  };

  // 5. بدء عملية "الحذف الكلي" للمعلم من القائمة أو تعويضه
  const handleDeleteTeacher = async (teacherId: number, teacherName: string) => {
    if (!teacherId) {
      showToast('يرجى اختيار معلم', 'error');
      return;
    }

    try {
      let tSections = assignedSections;
      if (selectedTeacherId !== teacherId) {
        const res = await fetchTeacherSections(teacherId);
        tSections = res.sections || [];
      }

      if (tSections.length === 0) {
        // لا توجد أقسام مسندة، تأكيد الحذف المباشر من القائمة
        if (window.confirm(`هل أنت متأكد من حذف المعلم ${teacherName}؟`)) {
          await executeDeleteTeacher(teacherId, teacherName, []);
        }
      } else {
        // توجد أقسام، إظهار نافذة النقل
        setReplacementTeacherId('');
        setDeleteTeacherModal({
          teacherId,
          teacherName,
          sections: tSections,
        });
      }
    } catch (error: any) {
      console.error('Delete teacher error:', error);
      showToast(error?.firstError || error?.message || 'خطأ في جلب بيانات المعلم', 'error');
    }
  };

  const handleInitiateTeacherDelete = handleDeleteTeacher;

  // 6. تنفيذ الحذف مع إزالة المعلم من القائمة فوراً
  const executeDeleteTeacher = async (
    teacherId: number,
    teacherName: string,
    sectionsToTransfer: AssignedSection[],
    replacementTeacherIdParam?: number | null
  ) => {
    const replacementId = replacementTeacherIdParam ? Number(replacementTeacherIdParam) : null;
    const replacementTeacherObj = replacementId ? teachers.find((t) => t.id === replacementId) : null;
    const currentTeacherObj = teachers.find((t) => t.id === teacherId);

    setExecutingTeacherDelete(true);
    try {
      // 1. استدعاء API لحذف إسنادات الأقسام ونقلها إن وُجد بديل
      await deleteTeacherSections(teacherId, replacementId);

      // 2. حذف المعلم من القائمة فوراً (إزالة من state teachers)
      setTeachers((prev) => prev.filter((t) => t.id !== teacherId));

      // 3. تحديث الواجهة إن كان المعلم المحذوف هو المعلم المحدد حالياً
      if (selectedTeacherId === teacherId) {
        setSelectedTeacherId(null);
        setAssignedSections([]);
      }

      // تحديث عدادات الأقسام محلياً إذا لم تُنقل لمعلم بديل
      if (!replacementId && sectionsToTransfer.length > 0) {
        const removedSecIds = new Set(sectionsToTransfer.map((s) => s.section.id));
        setSections((prev) =>
          prev.map((s) =>
            removedSecIds.has(s.id)
              ? { ...s, teacher_count: Math.max(0, s.teacher_count - 1) }
              : s
          )
        );
      }

      // 4. حفظ في undo stack
      const undoItem: DeleteTeacherUndoAction = {
        type: 'delete',
        employeeId: teacherId,
        teacherName,
        teacherObj: currentTeacherObj,
        sections: sectionsToTransfer,
        replacementEmployeeId: replacementId,
        replacementTeacherName: replacementTeacherObj?.name || null,
        timestamp: Date.now(),
      };
      setUndoStack((prev) => [...prev, undoItem]);

      // 5. إغلاق النافذة وتصفير الحالات
      setDeleteTeacherModal(null);
      setReplacementTeacherId('');

      // 6. إشعار فوري بنجاح الحذف مع زر التراجع
      const toastMsg = replacementId
        ? `تم حذف المعلم ${teacherName} ونقل أقسامه إلى ${replacementTeacherObj?.name}`
        : `تم حذف المعلم ${teacherName} من القائمة`;

      showToast(
        toastMsg,
        'success',
        () => void handleUndoDelete(teacherId, teacherName, sectionsToTransfer, replacementId, currentTeacherObj)
      );
    } catch (error: any) {
      console.error('Execute delete error:', error);
      showToast(error?.firstError || error?.message || 'خطأ في حذف المعلم', 'error');
    } finally {
      setExecutingTeacherDelete(false);
    }
  };

  const handleExecuteTeacherDelete = async () => {
    if (!deleteTeacherModal) return;
    await executeDeleteTeacher(
      deleteTeacherModal.teacherId,
      deleteTeacherModal.teacherName,
      deleteTeacherModal.sections,
      replacementTeacherId ? Number(replacementTeacherId) : undefined
    );
  };

  // 7. تراجع عن حذف معلم (يعيد المعلم للقائمة ويسترجع أقسامه)
  const handleUndoDelete = async (
    teacherId: number,
    teacherName: string,
    sectionsToRestore: AssignedSection[],
    replacementTeacherIdParam?: number | null,
    savedTeacherObj?: Teacher
  ) => {
    try {
      // 1. إعادة المعلم للقائمة (جلب القائمة المحدثة أو إعادة الكائن السابق)
      try {
        const freshTeachers = await fetchTeachers();
        if (freshTeachers && freshTeachers.length > 0) {
          setTeachers(freshTeachers);
        } else if (savedTeacherObj) {
          setTeachers((prev) => (prev.some((t) => t.id === teacherId) ? prev : [...prev, savedTeacherObj]));
        }
      } catch {
        if (savedTeacherObj) {
          setTeachers((prev) => (prev.some((t) => t.id === teacherId) ? prev : [...prev, savedTeacherObj]));
        }
      }

      // 2. حذف الأقسام من المعلم البديل إن وجدت
      if (replacementTeacherIdParam && sectionsToRestore.length > 0) {
        for (const s of sectionsToRestore) {
          try {
            await removeTeacherSection({
              employee_id: replacementTeacherIdParam,
              section_id: s.section.id,
            });
          } catch (e) {
            console.warn('Could not remove transferred section from replacement', e);
          }
        }
      }

      // 3. إعادة الأقسام للمعلم الأصلي
      if (sectionsToRestore.length > 0) {
        for (const s of sectionsToRestore) {
          try {
            await assignTeacherSection({
              employee_id: teacherId,
              section_id: s.section.id,
              subject: s.subject || null,
            });
          } catch (e) {
            console.warn('Could not reassign section to original teacher', e);
          }
        }
      }

      // 4. إزالة من undo stack
      setUndoStack((prev) =>
        prev.filter(
          (item) =>
            !(
              (item.type === 'delete' || item.type === 'delete_teacher') &&
              item.employeeId === teacherId
            )
        )
      );

      // 5. تحديث الواجهة وتحديد المعلم المستعاد
      setSelectedTeacherId(teacherId);
      await loadTeacherDetails(teacherId);
      const freshSections = await fetchSections();
      setSections(freshSections);

      showToast(`تم التراجع بنجاح وإعادة المعلم ${teacherName} وأقسامه`, 'success');
    } catch (error: any) {
      console.error('Undo delete error:', error);
      showToast('خطأ في التراجع عن حذف المعلم', 'error');
    }
  };

  // 8. محرك التراجع العام (Undo Engine)
  const handleUndoAction = async (actionToUndo?: UndoAction) => {
    const action = actionToUndo || undoStack[undoStack.length - 1];
    if (!action) return;

    if (action.type === 'delete' || action.type === 'delete_teacher') {
      await handleUndoDelete(
        action.employeeId,
        action.teacherName,
        action.sections,
        action.replacementEmployeeId,
        action.teacherObj
      );
      return;
    }

    try {
      if (action.type === 'assign') {
        // عكس الإسناد -> حذف
        await removeTeacherSection({
          employee_id: action.employeeId,
          section_id: action.sectionId,
        });

        if (selectedTeacherId === action.employeeId) {
          setAssignedSections((prev) => prev.filter((a) => a.section.id !== action.sectionId));
        }

        setSections((prev) =>
          prev.map((s) =>
            s.id === action.sectionId ? { ...s, teacher_count: Math.max(0, s.teacher_count - 1) } : s
          )
        );

        showToast(`تم التراجع عن إسناد (${action.sectionName}) للأستاذ ${action.teacherName}`);
      } else if (action.type === 'remove') {
        // عكس الحذف -> إعادة إسناد
        const res = await assignTeacherSection({
          employee_id: action.employeeId,
          section_id: action.sectionId,
          subject: action.subject || null,
        });

        if (selectedTeacherId === action.employeeId) {
          setAssignedSections((prev) => [...prev, res.data]);
        }

        setSections((prev) =>
          prev.map((s) => (s.id === action.sectionId ? { ...s, teacher_count: s.teacher_count + 1 } : s))
        );

        showToast(`تم التراجع وإعادة إسناد (${action.sectionName}) للأستاذ ${action.teacherName}`);
      }

      // إزالة العنصر من الـ Stack
      setUndoStack((prev) => prev.filter((item) => item.timestamp !== action.timestamp));
    } catch (err) {
      showToast('تعذّر إتمام عملية التراجع', 'error');
    }
  };

  const handleUndo = handleUndoAction;

  return (
    <div className="p-4 sm:p-6 md:p-8 space-y-6 max-w-7xl mx-auto" dir="rtl">
      {/* ─── الترويسة الرئيسية ─── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white rounded-2xl p-6 border border-stone-200 shadow-sm">
        <div className="flex items-center gap-4">
          <div
            className="w-14 h-14 rounded-2xl flex items-center justify-center shadow-inner"
            style={{ backgroundColor: C.sage, color: C.forest }}
          >
            <BookOpen size={28} className="stroke-[2.2]" />
          </div>
          <div>
            <h1 className="text-xl sm:text-2xl font-black tracking-tight" style={{ color: C.ink }}>
              إدارة أقسام المعلمين
            </h1>
            <p className="text-sm mt-1" style={{ color: C.muted }}>
              توزيع الفصول الدراسية والمواد، نقل الأقسام بين المعلمين، والتحديث الفوري لبوابة الجوال
            </p>
          </div>
        </div>

        <div className="flex items-center gap-3 self-end sm:self-auto">
          {undoStack.length > 0 && (
            <button
              onClick={() => void handleUndoAction()}
              title="تراجع عن آخر عملية"
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-300 text-amber-900 hover:bg-amber-100 font-bold text-sm shadow-sm transition-all active:scale-95"
            >
              <Undo2 size={16} className="text-amber-700 animate-pulse" />
              <span>تراجع ({undoStack.length})</span>
            </button>
          )}

          <button
            onClick={() => {
              setRefreshing(true);
              void loadInitialData();
              if (selectedTeacherId) void loadTeacherDetails(selectedTeacherId);
            }}
            disabled={refreshing}
            className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-stone-100 hover:bg-stone-200 text-stone-700 font-bold text-sm transition-all active:scale-95 disabled:opacity-50"
          >
            <RefreshCw size={16} className={refreshing ? 'animate-spin' : ''} />
            <span>تحديث</span>
          </button>
        </div>
      </div>

      {/* ─── بطاقات الإحصاءات السريعة ─── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-2xl p-4 border border-stone-200 shadow-sm flex items-center gap-3.5">
          <div className="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center">
            <Users size={22} />
          </div>
          <div>
            <p className="text-xs font-bold text-stone-500">إجمالي المعلمين</p>
            <p className="text-xl font-black text-stone-900 mt-0.5">{stats.totalTeachers}</p>
          </div>
        </div>

        <div className="bg-white rounded-2xl p-4 border border-stone-200 shadow-sm flex items-center gap-3.5">
          <div className="w-11 h-11 rounded-xl bg-blue-50 text-blue-700 flex items-center justify-center">
            <School size={22} />
          </div>
          <div>
            <p className="text-xs font-bold text-stone-500">إجمالي الأقسام</p>
            <p className="text-xl font-black text-stone-900 mt-0.5">{stats.totalSections}</p>
          </div>
        </div>

        <div className="bg-white rounded-2xl p-4 border border-stone-200 shadow-sm flex items-center gap-3.5">
          <div className="w-11 h-11 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center">
            <Layers size={22} />
          </div>
          <div>
            <p className="text-xs font-bold text-stone-500">أقسام مسندة</p>
            <p className="text-xl font-black text-stone-900 mt-0.5">{stats.sectionsWithTeachers}</p>
          </div>
        </div>

        <div className="bg-white rounded-2xl p-4 border border-stone-200 shadow-sm flex items-center gap-3.5">
          <div className="w-11 h-11 rounded-xl bg-purple-50 text-purple-700 flex items-center justify-center">
            <UserCheck size={22} />
          </div>
          <div>
            <p className="text-xs font-bold text-stone-500">أقسام المعلم المحدد</p>
            <p className="text-xl font-black text-stone-900 mt-0.5">{stats.assignedCount}</p>
          </div>
        </div>
      </div>

      {/* ─── محتوى الصفحة: عمودان ─── */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* ─── العمود الأيمن: قائمة المعلمين مع أزرار الحذف الكلي (5 أعمدة) ─── */}
        <div className="lg:col-span-5 space-y-4">
          <div className="bg-white rounded-2xl p-5 border border-stone-200 shadow-sm space-y-4">
            <div className="flex items-center justify-between">
              <h2 className="font-extrabold text-stone-900 flex items-center gap-2">
                <Users size={19} className="text-emerald-800" />
                <span>قائمة المعلمين</span>
              </h2>
              <span className="text-xs bg-stone-100 text-stone-600 px-2.5 py-1 rounded-full font-bold">
                {filteredTeachers.length} معلّم
              </span>
            </div>

            {/* شريط البحث */}
            <div className="relative">
              <Search size={16} className="absolute right-3 top-3 text-stone-400" />
              <input
                type="text"
                value={teacherSearch}
                onChange={(e) => setTeacherSearch(e.target.value)}
                placeholder="بحث بالاسم أو الهاتف..."
                className="w-full pr-9 pl-4 py-2 text-sm bg-stone-50 border border-stone-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:bg-white transition-all placeholder:text-stone-400"
              />
              {teacherSearch && (
                <button
                  onClick={() => setTeacherSearch('')}
                  className="absolute left-3 top-2.5 text-stone-400 hover:text-stone-600"
                >
                  <X size={15} />
                </button>
              )}
            </div>

            {/* قائمة المعلمين مع زر الحذف الكلي */}
            <div className="max-h-[540px] overflow-y-auto space-y-2.5 pr-1">
              {loading ? (
                <div className="py-8 text-center text-stone-400 text-sm animate-pulse">
                  جاري تحميل قائمة المعلمين...
                </div>
              ) : filteredTeachers.length === 0 ? (
                <div className="py-8 text-center text-stone-500 text-sm">
                  لا يوجد معلّم يطابق البحث
                </div>
              ) : (
                filteredTeachers.map((t) => {
                  const isSelected = t.id === selectedTeacherId;
                  return (
                    <div
                      key={t.id}
                      className={`p-3 rounded-xl border transition-all flex items-center justify-between gap-2.5 ${
                        isSelected
                          ? 'bg-[#1B4332] text-white border-[#1B4332] shadow-md shadow-emerald-900/20'
                          : 'bg-white hover:bg-stone-50/80 border-stone-200 text-stone-800'
                      }`}
                    >
                      {/* منطقة النقر لاختيار المعلم */}
                      <button
                        type="button"
                        onClick={() => setSelectedTeacherId(t.id)}
                        className="flex-1 flex items-center gap-3 truncate text-right focus:outline-none"
                      >
                        <div
                          className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 font-black text-sm shadow-sm ${
                            isSelected ? 'bg-white/20 text-white' : 'bg-emerald-50 text-emerald-800'
                          }`}
                        >
                          {t.name.slice(0, 1)}
                        </div>
                        <div className="truncate">
                          <p className="font-extrabold text-sm truncate">{t.name}</p>
                          <div className="flex items-center gap-2 mt-0.5">
                            {t.phone && (
                              <span
                                className={`text-[11px] flex items-center gap-1 ${
                                  isSelected ? 'text-white/80' : 'text-stone-500'
                                }`}
                              >
                                <Phone size={10} />
                                {t.phone}
                              </span>
                            )}
                            {t.staff_type && (
                              <span
                                className={`text-[10px] px-1.5 py-0.5 rounded font-bold ${
                                  isSelected
                                    ? 'bg-white/20 text-white'
                                    : 'bg-stone-100 text-stone-600'
                                }`}
                              >
                                {t.staff_type === 'monthly_teacher'
                                  ? 'شهري'
                                  : t.staff_type === 'hourly_teacher'
                                  ? 'ساعي'
                                  : t.staff_type}
                              </span>
                            )}
                          </div>
                        </div>
                      </button>

                      {/* أزرار الإجراءات بجانب المعلم */}
                      <div className="flex items-center gap-1.5 shrink-0">
                        {/* زر اختيار المعلم */}
                        <button
                          type="button"
                          onClick={() => setSelectedTeacherId(t.id)}
                          className={`text-xs px-2.5 py-1.5 rounded-lg font-bold transition-all ${
                            isSelected
                              ? 'bg-white text-emerald-950 shadow-sm'
                              : 'bg-stone-100 hover:bg-stone-200 text-stone-700'
                          }`}
                        >
                          {isSelected ? 'محدد' : 'عرض'}
                        </button>

                        {/* زر الحذف الكلي مع إمكانية نقل الأقسام */}
                        <button
                          type="button"
                          onClick={() => void handleDeleteTeacher(t.id, t.name)}
                          title="حذف كلي للمعلم من القائمة مع إمكانية نقل أقسامه"
                          className={`p-1.5 rounded-lg transition-colors flex items-center gap-1 text-xs font-bold ${
                            isSelected
                              ? 'text-rose-200 hover:bg-white/20 hover:text-white'
                              : 'text-rose-600 hover:bg-rose-50 hover:text-rose-800 border border-transparent hover:border-rose-200'
                          }`}
                        >
                          <Trash2 size={15} />
                          <span className="hidden sm:inline">حذف كلي</span>
                        </button>
                      </div>
                    </div>
                  );
                })
              )}
            </div>

            {/* تنبيه إدارة الموارد البشرية */}
            <div className="p-3 bg-amber-50/80 border border-amber-200 rounded-xl text-xs text-amber-900 flex items-start gap-2">
              <Info size={16} className="text-amber-700 shrink-0 mt-0.5" />
              <p>
                زر «حذف كلي» يفك ارتباط كافة فصول المعلم ويتيح نقلها لمعلم بديل. الموظف نفسه يبقى محفوظاً في النظام وسجلات الرواتب بأمان.
              </p>
            </div>
          </div>
        </div>

        {/* ─── العمود الأيسر: تفاصيل المعلم، الفصول المسندة، ونموذج الإسناد (7 أعمدة) ─── */}
        <div className="lg:col-span-7 space-y-6">
          {/* بطاقة تفاصيل المعلم المختار */}
          {selectedTeacher ? (
            <div className="bg-white rounded-2xl p-5 border border-stone-200 shadow-sm space-y-4">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-stone-100">
                <div className="flex items-center gap-3">
                  <div
                    className="w-12 h-12 rounded-xl flex items-center justify-center font-black text-lg shadow-sm"
                    style={{ backgroundColor: C.sage, color: C.forest }}
                  >
                    {selectedTeacher.name.slice(0, 1)}
                  </div>
                  <div>
                    <h2 className="text-lg font-black text-stone-900">{selectedTeacher.name}</h2>
                    <div className="flex items-center gap-3 text-xs text-stone-500 mt-0.5">
                      {selectedTeacher.phone && (
                        <span className="flex items-center gap-1">
                          <Phone size={12} />
                          {selectedTeacher.phone}
                        </span>
                      )}
                      {selectedTeacher.email && (
                        <span className="flex items-center gap-1">
                          <Mail size={12} />
                          {selectedTeacher.email}
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  <span className="bg-emerald-50 text-emerald-800 border border-emerald-200 px-3 py-1 rounded-full text-xs font-black">
                    {assignedSections.length} أقسام مسندة
                  </span>

                  {assignedSections.length > 0 && (
                    <button
                      onClick={() => void handleInitiateTeacherDelete(selectedTeacher.id, selectedTeacher.name)}
                      className="flex items-center gap-1 px-3 py-1 rounded-full bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200 text-xs font-bold transition-all"
                    >
                      <UserMinus size={13} />
                      <span>حذف كلي / نقل</span>
                    </button>
                  )}
                </div>
              </div>

              {/* شبكة الأقسام المسندة */}
              <div>
                <h3 className="text-sm font-bold text-stone-700 mb-3 flex items-center gap-2">
                  <School size={16} className="text-emerald-700" />
                  <span>الأقسام المسندة حالياً للأستاذ {selectedTeacher.name}:</span>
                </h3>

                {assignedSections.length === 0 ? (
                  <div className="py-10 text-center rounded-xl bg-stone-50 border border-dashed border-stone-300 p-6 space-y-2">
                    <School size={32} className="mx-auto text-stone-300" />
                    <p className="text-stone-600 font-bold text-sm">لا توجد أقسام مسندة لهذا المعلم حتى الآن</p>
                    <p className="text-stone-400 text-xs">
                      اختر قسماً من النموذج بالأسفل لإسناده إلى هذا المعلم
                    </p>
                  </div>
                ) : (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    {assignedSections.map((assigned) => (
                      <div
                        key={assigned.id}
                        className="group relative bg-stone-50/80 hover:bg-white border border-stone-200 rounded-xl p-4 transition-all hover:shadow-md hover:border-emerald-300 flex flex-col justify-between gap-3"
                      >
                        <div className="flex items-start justify-between gap-2">
                          <div>
                            <div className="flex items-center gap-2">
                              <span className="px-2 py-0.5 bg-emerald-100/70 text-emerald-800 text-[11px] font-black rounded-md">
                                {assigned.section.level.name}
                              </span>
                              <h4 className="font-black text-stone-900 text-base">
                                قسم {assigned.section.name}
                              </h4>
                            </div>

                            {assigned.subject ? (
                              <div className="mt-2 flex items-center gap-1.5 text-xs text-stone-700 font-bold bg-white border border-stone-200 px-2.5 py-1 rounded-lg w-fit">
                                <BookOpen size={13} className="text-emerald-700" />
                                <span>{assigned.subject}</span>
                              </div>
                            ) : (
                              <p className="mt-2 text-xs text-stone-400 italic">كافة المواد / عام</p>
                            )}
                          </div>

                          <button
                            onClick={() =>
                              setConfirmDelete({
                                sectionId: assigned.section.id,
                                sectionName: `${assigned.section.level.name} — ${assigned.section.name}`,
                              })
                            }
                            title="إلغاء إسناد القسم"
                            className="p-1.5 rounded-lg text-rose-500 hover:text-rose-700 hover:bg-rose-50 transition-colors"
                          >
                            <Trash2 size={16} />
                          </button>
                        </div>

                        <div className="flex items-center justify-between text-[11px] text-stone-400 border-t border-stone-200/60 pt-2">
                          <span className="flex items-center gap-1 text-stone-600 font-medium">
                            <GraduationCap size={13} className="text-stone-500" />
                            {sections.find((s) => s.id === assigned.section.id)?.student_count ?? '—'}{' '}
                            تلميذ
                          </span>
                          <span>مُسند في النظام</span>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          ) : (
            <div className="bg-white rounded-2xl p-12 text-center border border-stone-200 shadow-sm space-y-3">
              <div className="w-16 h-16 rounded-2xl bg-stone-100 text-stone-400 flex items-center justify-center mx-auto">
                <UserCheck size={32} />
              </div>
              <h3 className="text-base font-bold text-stone-800">يرجى اختيار معلم من القائمة</h3>
              <p className="text-sm text-stone-500 max-w-md mx-auto">
                اختر معلماً لعرض فصوله الحالية وإسناد أقسام جديدة أو فك ارتباط الأقسام ونقلها
              </p>
            </div>
          )}

          {/* نموذج إسناد قسم جديد للمعلم المختار */}
          {selectedTeacher && (
            <div className="bg-white rounded-2xl p-6 border border-stone-200 shadow-sm space-y-4">
              <div className="flex items-center justify-between border-b border-stone-100 pb-3">
                <h3 className="font-extrabold text-stone-900 flex items-center gap-2 text-base">
                  <Plus size={18} className="text-emerald-700" />
                  <span>إسناد قسم جديد للأستاذ {selectedTeacher.name}</span>
                </h3>
                <span className="text-xs text-stone-500">
                  {availableSections.length} قسم متاح للإسناد
                </span>
              </div>

              <form onSubmit={handleAssign} className="space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* اختيار القسم */}
                  <div className="space-y-1.5">
                    <label className="block text-xs font-bold text-stone-700">
                      القسم المراد إسناده <span className="text-rose-500">*</span>
                    </label>
                    <select
                      value={selectedSectionId}
                      onChange={(e) => setSelectedSectionId(e.target.value ? Number(e.target.value) : '')}
                      required
                      className="w-full px-3.5 py-2.5 text-sm bg-stone-50 border border-stone-300 rounded-xl focus:ring-2 focus:ring-emerald-600 focus:bg-white focus:outline-none font-medium text-stone-800 transition-all"
                    >
                      <option value="">-- اضغط لاختيار القسم --</option>
                      {Array.from(availableSectionsByLevel.entries()).map(([levelName, levelSections]) => (
                        <optgroup key={levelName} label={`=== ${levelName} ===`}>
                          {levelSections.map((sec) => (
                            <option key={sec.id} value={sec.id}>
                              {levelName} — {sec.name} ({sec.student_count} تلميذ{sec.teacher_count > 0 ? ` • ${sec.teacher_count} معلمين` : ''})
                            </option>
                          ))}
                        </optgroup>
                      ))}
                    </select>
                  </div>

                  {/* تحديد المادة */}
                  <div className="space-y-1.5">
                    <label className="block text-xs font-bold text-stone-700">
                      المادة المدرّسة (اختياري)
                    </label>
                    <input
                      type="text"
                      value={subject}
                      onChange={(e) => setSubject(e.target.value)}
                      placeholder="مثال: الرياضيات، العربية، الفرنسية..."
                      className="w-full px-3.5 py-2.5 text-sm bg-stone-50 border border-stone-300 rounded-xl focus:ring-2 focus:ring-emerald-600 focus:bg-white focus:outline-none text-stone-800 transition-all"
                    />
                  </div>
                </div>

                {/* اقتراحات المواد الشائعة للاختيار السريع */}
                <div className="space-y-1.5 pt-1">
                  <p className="text-[11px] font-bold text-stone-500">اختيار سريع للمادة:</p>
                  <div className="flex flex-wrap gap-1.5">
                    {COMMON_SUBJECTS.map((sub) => (
                      <button
                        key={sub}
                        type="button"
                        onClick={() => setSubject(sub)}
                        className={`text-xs px-2.5 py-1 rounded-lg border transition-all ${
                          subject === sub
                            ? 'bg-emerald-800 text-white border-emerald-800 font-bold shadow-sm'
                            : 'bg-stone-100 hover:bg-stone-200 text-stone-700 border-stone-200'
                        }`}
                      >
                        {sub}
                      </button>
                    ))}
                    {subject && (
                      <button
                        type="button"
                        onClick={() => setSubject('')}
                        className="text-xs px-2 py-1 text-stone-400 hover:text-stone-600"
                      >
                        تفريغ
                      </button>
                    )}
                  </div>
                </div>

                {/* زر الإسناد */}
                <div className="pt-2 flex justify-end">
                  <button
                    type="submit"
                    disabled={!selectedSectionId || assigning}
                    className="flex items-center gap-2 px-6 py-2.5 rounded-xl font-black text-sm text-white transition-all shadow-md active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                    style={{ backgroundColor: C.forest }}
                  >
                    {assigning ? (
                      <>
                        <RefreshCw size={16} className="animate-spin" />
                        <span>جاري حفظ الإسناد...</span>
                      </>
                    ) : (
                      <>
                        <Plus size={16} />
                        <span>تأكيد إسناد القسم</span>
                      </>
                    )}
                  </button>
                </div>
              </form>
            </div>
          )}
        </div>
      </div>

      {/* ─── نافذة نقل الأقسام وتأكيد الحذف الكلي للمعلم (Multi-Step Transfer Modal) ─── */}
      {deleteTeacherModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm animate-fade-in">
          <div className="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-stone-200 space-y-5 text-right">
            <div className="flex items-center justify-between pb-3 border-b border-stone-100">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-xl bg-rose-100 text-rose-700 flex items-center justify-center">
                  <UserMinus size={22} />
                </div>
                <div>
                  <h3 className="text-base font-black text-stone-900">
                    حذف كلي لأقسام المعلم: {deleteTeacherModal.teacherName}
                  </h3>
                  <p className="text-xs text-stone-500">إلغاء ارتباط كافة الفصول مع إمكانية نقلها لمعلم آخر</p>
                </div>
              </div>
              <button
                onClick={() => setDeleteTeacherModal(null)}
                className="text-stone-400 hover:text-stone-600 p-1"
              >
                <X size={18} />
              </button>
            </div>

            {/* تفاصيل فصول المعلم الحالية */}
            <div className="space-y-2">
              <div className="flex items-center justify-between text-xs font-bold text-stone-700">
                <span>الفصول المسندة حالياً لهذا المعلم:</span>
                <span className="bg-emerald-50 text-emerald-800 px-2 py-0.5 rounded-full font-black">
                  {deleteTeacherModal.sections.length} فصول
                </span>
              </div>

              {deleteTeacherModal.sections.length === 0 ? (
                <p className="text-xs text-stone-500 italic p-3 bg-stone-50 rounded-xl border border-stone-200">
                  هذا المعلم ليس لديه أي أقسام مسندة حالياً.
                </p>
              ) : (
                <div className="max-h-36 overflow-y-auto p-2 bg-stone-50 rounded-xl border border-stone-200 space-y-1.5 text-xs">
                  {deleteTeacherModal.sections.map((st) => (
                    <div
                      key={st.id}
                      className="bg-white p-2 rounded-lg border border-stone-200 flex items-center justify-between"
                    >
                      <span className="font-bold text-stone-800">
                        {st.section.level.name} — {st.section.name}
                      </span>
                      <span className="text-stone-500 bg-stone-100 px-2 py-0.5 rounded text-[11px]">
                        {st.subject || 'عام'}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* قائمة اختيار المعلم البديل */}
            {deleteTeacherModal.sections.length > 0 && (
              <div className="space-y-2 pt-1">
                <label className="block text-xs font-bold text-stone-800 flex items-center gap-1.5">
                  <ArrowLeftRight size={14} className="text-emerald-700" />
                  <span>نقل هذه الفصول إلى معلم بديل (اختياري):</span>
                </label>
                <select
                  value={replacementTeacherId}
                  onChange={(e) => setReplacementTeacherId(e.target.value ? Number(e.target.value) : '')}
                  className="w-full px-3.5 py-2.5 text-sm bg-white border border-stone-300 rounded-xl focus:ring-2 focus:ring-emerald-600 focus:outline-none font-medium text-stone-800 transition-all"
                >
                  <option value="">-- لا تنقل الأقسام (إلغاء الإسناد فقط دون تعيين بديل) --</option>
                  {teachers
                    .filter((t) => t.id !== deleteTeacherModal.teacherId)
                    .map((t) => (
                      <option key={t.id} value={t.id}>
                        {t.name} ({t.phone || 'بدون هاتف'}{t.staff_type ? ` • ${t.staff_type}` : ''})
                      </option>
                    ))}
                </select>
                <p className="text-[11px] text-stone-500">
                  عند اختيار معلم بديل، ستنتقل إليه الفصول المذكورة تلقائياً بنفس المواد.
                </p>
              </div>
            )}

            {/* أزرار الإجراءات */}
            <div className="flex items-center justify-end gap-3 pt-3 border-t border-stone-100">
              <button
                type="button"
                onClick={() => setDeleteTeacherModal(null)}
                disabled={executingTeacherDelete}
                className="px-4 py-2.5 rounded-xl border border-stone-300 text-stone-700 hover:bg-stone-50 font-bold text-sm transition-all"
              >
                إلغاء الأمر
              </button>
              <button
                type="button"
                onClick={() => void handleExecuteTeacherDelete()}
                disabled={executingTeacherDelete}
                className="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-black text-sm shadow-md transition-all active:scale-95 disabled:opacity-50"
              >
                {executingTeacherDelete ? (
                  <>
                    <RefreshCw size={16} className="animate-spin" />
                    <span>جاري التنفيذ...</span>
                  </>
                ) : (
                  <>
                    <Trash2 size={16} />
                    <span>
                      {replacementTeacherId ? 'تأكيد النقل وإلغاء الإسناد' : 'تأكيد إلغاء كافة الإسنادات'}
                    </span>
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ─── نافذة تأكيد حذف قسم فردي (Single Section Delete Modal) ─── */}
      {confirmDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm animate-fade-in">
          <div className="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-stone-200 space-y-4 text-right">
            <div className="w-12 h-12 rounded-2xl bg-rose-100 text-rose-700 flex items-center justify-center mx-auto">
              <Trash2 size={24} />
            </div>

            <div className="text-center space-y-2">
              <h3 className="text-lg font-black text-stone-900">تأكيد إلغاء إسناد القسم</h3>
              <p className="text-sm text-stone-600">
                هل أنت متأكد من إلغاء إسناد قسم{' '}
                <strong className="text-stone-900">{confirmDelete.sectionName}</strong> من الأستاذ{' '}
                <strong className="text-stone-900">{selectedTeacher?.name}</strong>؟
              </p>
              <p className="text-xs text-stone-400">
                (لن يتم حذف أي تلميذ أو سجل دراسي، فقط فك ارتباط المعلم بالقسم).
              </p>
            </div>

            <div className="flex items-center justify-center gap-3 pt-2">
              <button
                onClick={() => setConfirmDelete(null)}
                className="px-5 py-2.5 rounded-xl border border-stone-300 text-stone-700 hover:bg-stone-50 font-bold text-sm transition-all"
              >
                إلغاء الأمر
              </button>
              <button
                onClick={() => handleRemove(confirmDelete.sectionId, confirmDelete.sectionName)}
                className="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-black text-sm shadow-md transition-all active:scale-95"
              >
                نعم، فك الارتباط
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ─── شريط التراجع العائم والـ Toast Notification ─── */}
      {toast && (
        <div className="fixed bottom-6 left-6 z-50 max-w-md w-full animate-slide-up">
          <div
            className={`p-4 rounded-2xl shadow-2xl border flex items-center justify-between gap-3 text-sm ${
              toast.type === 'error'
                ? 'bg-rose-900 text-rose-50 border-rose-700'
                : toast.type === 'warning'
                ? 'bg-amber-900 text-amber-50 border-amber-700'
                : 'bg-stone-900 text-stone-50 border-stone-700'
            }`}
          >
            <div className="flex items-center gap-3">
              {toast.type === 'error' ? (
                <AlertCircle size={20} className="text-rose-400 shrink-0" />
              ) : toast.type === 'warning' ? (
                <AlertCircle size={20} className="text-amber-400 shrink-0" />
              ) : (
                <CheckCircle2 size={20} className="text-emerald-400 shrink-0" />
              )}
              <span className="font-bold leading-relaxed">{toast.message}</span>
            </div>

            <div className="flex items-center gap-2 shrink-0">
              {toast.action && (
                <button
                  onClick={toast.action.onClick}
                  className="px-3 py-1 bg-white/20 hover:bg-white/30 text-white text-xs font-black rounded-lg transition-all active:scale-95"
                >
                  {toast.action.label}
                </button>
              )}
              <button
                onClick={() => setToast(null)}
                className="text-white/60 hover:text-white p-1"
              >
                <X size={16} />
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
