import { useCallback, useEffect, useRef, useState } from 'react';
import {
  Upload,
  CheckCircle2,
  User,
  Trash2,
  Calendar,
  BookOpen,
  FileText,
  Camera,
  ChevronDown,
  AlertCircle,
} from 'lucide-react';
import { getSectionOptions, type SectionOption } from '../../../api/students';
import { FIELD_CLASS, LABEL_CLASS, type ChangeHandler, type WizardFormData } from './types';

const GENDERS = [
  { id: 'male', label: 'ذكر' },
  { id: 'female', label: 'أنثى' },
];

type Props = {
  data: WizardFormData;
  onChange: ChangeHandler;
  setField: (name: keyof WizardFormData, value: string) => void;
  photoPreview: string | null;
  onPhotoChange: (file: File | null) => void;
  showErrors: boolean;
};

export function StudentStep({ data, onChange, setField, photoPreview, onPhotoChange, showErrors }: Props) {
  const [sections, setSections] = useState<SectionOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [open, setOpen] = useState<'gender' | 'section' | null>(null);
  const fileRef = useRef<HTMLInputElement | null>(null);

  const loadSections = useCallback(async () => {
    setLoading(true);
    setLoadError('');
    try {
      const list = await getSectionOptions();
      setSections(list);
      if (list.length === 0) {
        setLoadError('لا توجد أقسام مسجّلة في النظام. أضف الأقسام من شاشة الأقسام أوّلاً.');
      }
    } catch (err: unknown) {
      setLoadError(err instanceof Error ? err.message : 'تعذّر جلب قائمة الأقسام.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadSections();
  }, [loadSections]);

  const selected = sections.find((s) => String(s.id) === data.section_id);
  const sectionInvalid = showErrors && !data.section_id;

  return (
    <div className="space-y-10">
      <div className="flex items-center gap-3 pb-6 border-b border-slate-100">
        <div className="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center text-blue-600">
          <User size={20} />
        </div>
        <div>
          <h2 className="text-xl font-bold text-slate-800">المعلومات الشخصية للتلميذ</h2>
          <p className="text-sm text-slate-500 mt-1">الحقول المعلّمة بـ (*) إجبارية</p>
        </div>
      </div>

      <div className="flex flex-col items-center justify-center p-8 bg-slate-50/50 rounded-2xl border border-slate-100 border-dashed">
        <div className="relative mb-4">
          {/* الحقل مداخل داخل الـ label ومربوط بـ htmlFor معاً، فيرضى المتصفّح وقارئ الشاشة. */}
          <label className="cursor-pointer group block" htmlFor="photo">
            <div
              className={`w-36 h-36 rounded-full border-4 flex flex-col items-center justify-center overflow-hidden transition-all duration-300 shadow-sm ${photoPreview ? 'border-white ring-4 ring-blue-50' : 'border-dashed border-slate-300 group-hover:border-blue-400 bg-white'}`}
            >
              {photoPreview ? (
                <>
                  <img src={photoPreview} alt="صورة التلميذ" className="w-full h-full object-cover" />
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
                  <span className="text-xs font-medium text-slate-500 group-hover:text-blue-600 transition-colors">
                    رفع صورة التلميذ
                  </span>
                </>
              )}
            </div>
            <input
              ref={fileRef}
              type="file"
              id="photo"
              name="photo"
              className="hidden"
              accept="image/*"
              onChange={(e) => onPhotoChange(e.target.files?.[0] ?? null)}
            />
          </label>
        </div>
        {photoPreview && (
          <button
            type="button"
            onClick={() => {
              if (fileRef.current) fileRef.current.value = '';
              onPhotoChange(null);
            }}
            className="text-sm font-medium text-red-500 hover:text-red-700 hover:bg-red-50 px-4 py-2 rounded-xl transition-colors flex items-center gap-2"
          >
            <Trash2 size={16} />
            حذف الصورة
          </button>
        )}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-8">
        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="first_name">
            الاسم <span className="text-red-500">*</span>
          </label>
          <input
            type="text"
            id="first_name"
            name="first_name"
            autoComplete="off"
            value={data.first_name}
            onChange={onChange}
            placeholder="مثال: أحمد"
            className={FIELD_CLASS}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="last_name">
            اللقب <span className="text-red-500">*</span>
          </label>
          <input
            type="text"
            id="last_name"
            name="last_name"
            autoComplete="off"
            value={data.last_name}
            onChange={onChange}
            placeholder="مثال: بن علي"
            className={FIELD_CLASS}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="dob">
            تاريخ الولادة <span className="text-red-500">*</span>
          </label>
          <div className="relative">
            <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
              <Calendar size={18} />
            </div>
            <input
              type="date"
              id="dob"
              name="dob"
              autoComplete="off"
              value={data.dob}
              onChange={onChange}
              className={FIELD_CLASS}
            />
          </div>
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="gender_trigger">
            الجنس <span className="text-red-500">*</span>
          </label>
          <div className="relative">
            <input type="hidden" name="gender" value={data.gender} />
            <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 z-10">
              <ChevronDown size={18} className={`transition-transform duration-200 ${open === 'gender' ? 'rotate-180' : ''}`} />
            </div>
            <button
              type="button"
              id="gender_trigger"
              aria-haspopup="listbox"
              aria-expanded={open === 'gender'}
              onClick={() => setOpen(open === 'gender' ? null : 'gender')}
              className={`flex items-center w-full text-right pr-4 pl-12 py-3 bg-slate-50/50 hover:bg-white border rounded-xl cursor-pointer min-h-[50px] transition-all duration-200 outline-none ${open === 'gender' ? 'border-blue-500 ring-2 ring-blue-500/20 bg-white' : 'border-slate-200'}`}
            >
              <span className={data.gender ? 'text-slate-700' : 'text-slate-400'}>
                {GENDERS.find((g) => g.id === data.gender)?.label ?? 'اختر الجنس'}
              </span>
            </button>
            {open === 'gender' && (
              <>
                <div className="fixed inset-0 z-40" onClick={() => setOpen(null)} />
                <ul
                  role="listbox"
                  aria-label="الجنس"
                  className="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-lg z-50 overflow-hidden py-1"
                >
                  {GENDERS.map((option) => (
                    <li key={option.id} role="option" aria-selected={data.gender === option.id}>
                      <button
                        type="button"
                        onClick={() => {
                          setField('gender', option.id);
                          setOpen(null);
                        }}
                        className={`w-full px-4 py-3 cursor-pointer transition-colors flex items-center justify-between text-right ${data.gender === option.id ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-slate-50 text-slate-700'}`}
                      >
                        {option.label}
                        {data.gender === option.id && <CheckCircle2 size={16} className="text-blue-600" />}
                      </button>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        </div>

        <div className="space-y-2.5 md:col-span-2">
          <label className={LABEL_CLASS} htmlFor="section_picker">
            القسم <span className="text-red-500">*</span>
          </label>
          <div className="relative">
            <input type="hidden" name="section_id" value={data.section_id} />
            <div className="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none text-slate-400 z-10">
              <BookOpen size={18} />
            </div>
            <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 z-10">
              <ChevronDown size={18} className={`transition-transform duration-200 ${open === 'section' ? 'rotate-180' : ''}`} />
            </div>
            <button
              type="button"
              id="section_picker"
              aria-haspopup="listbox"
              aria-expanded={open === 'section'}
              aria-invalid={sectionInvalid}
              aria-describedby="section_id_hint"
              disabled={loading}
              onClick={() => setOpen(open === 'section' ? null : 'section')}
              className={`flex items-center w-full text-right pr-12 pl-12 py-3 bg-slate-50/50 hover:bg-white border rounded-xl cursor-pointer min-h-[50px] transition-all duration-200 outline-none disabled:cursor-wait ${
                sectionInvalid
                  ? 'border-red-400 ring-2 ring-red-500/10 bg-red-50/40'
                  : open === 'section'
                    ? 'border-blue-500 ring-2 ring-blue-500/20 bg-white'
                    : 'border-slate-200'
              }`}
            >
              <span className={selected ? 'text-slate-700' : 'text-slate-400'}>
                {loading ? 'جارٍ جلب الأقسام…' : (selected?.label ?? 'اختر القسم المراد ترسيم التلميذ فيه')}
              </span>
            </button>

            {open === 'section' && sections.length > 0 && (
              <>
                <div className="fixed inset-0 z-40" onClick={() => setOpen(null)} />
                <ul
                  role="listbox"
                  aria-label="الأقسام"
                  className="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-lg z-50 overflow-y-auto max-h-72 py-1"
                >
                  {sections.map((option) => (
                    <li key={option.id} role="option" aria-selected={data.section_id === String(option.id)}>
                      <button
                        type="button"
                        onClick={() => {
                          setField('section_id', String(option.id));
                          setOpen(null);
                        }}
                        className={`w-full px-4 py-3 cursor-pointer transition-colors flex items-center justify-between text-right ${data.section_id === String(option.id) ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-slate-50 text-slate-700'}`}
                      >
                        {option.label}
                        {data.section_id === String(option.id) && <CheckCircle2 size={16} className="text-blue-600" />}
                      </button>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>

          {loadError ? (
            <div id="section_id_hint" className="flex items-center gap-2 text-sm text-red-600">
              <AlertCircle size={15} />
              <span>{loadError}</span>
              <button type="button" onClick={() => void loadSections()} className="underline font-medium">
                إعادة المحاولة
              </button>
            </div>
          ) : sectionInvalid ? (
            <p id="section_id_hint" className="flex items-center gap-2 text-sm text-red-600">
              <AlertCircle size={15} />
              القسم إجباري: اختر قسم التلميذ لهذه السنة الدراسية.
            </p>
          ) : (
            <p id="section_id_hint" className="text-xs text-slate-400">المستوى يُحدَّد تلقائياً من القسم المختار.</p>
          )}
        </div>

        <div className="md:col-span-2 space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="notes">ملاحظات إضافية</label>
          <div className="relative">
            <div className="absolute top-4 right-4 flex items-start pointer-events-none text-slate-400">
              <FileText size={18} />
            </div>
            <textarea
              id="notes"
              name="notes"
              autoComplete="off"
              value={data.notes}
              onChange={onChange}
              rows={3}
              placeholder="أي معلومات إضافية أو طبية يجب معرفتها (اختياري)…"
              className={`${FIELD_CLASS} pr-12`}
            />
          </div>
        </div>
      </div>
    </div>
  );
}

export default StudentStep;
