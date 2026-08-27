import type { ReactNode } from 'react';
import { FolderOpen, type LucideIcon } from 'lucide-react';

/* ==========================================================================
   حالة الفراغ (EmptyState) — إليستريشن SVG inline بلون هوية المدرسة.
   آمنة دون اتصال (لا صور خارجية). النصّ يأتي من المستدعي كما هو حرفيًا.
   ========================================================================== */

type EmptyStateProps = {
  title: string;
  description?: string;
  /** أيقونة Lucide اختيارية داخل الشارة (الافتراضي: مجلّد مفتوح). */
  icon?: LucideIcon;
  /** إجراء اختياري (زر من المستدعي) يظهر أسفل الوصف. */
  action?: ReactNode;
  className?: string;
};

export function EmptyState({ title, description, icon: Icon, action, className = '' }: EmptyStateProps) {
  const Glyph = Icon ?? FolderOpen;
  return (
    <div className={`flex flex-col items-center justify-center px-6 py-16 text-center ${className}`}>
      <div className="relative mb-6 flex h-32 w-32 items-center justify-center">
        {/* هالة ناعمة + حلقات زخرفية بلون الهوية */}
        <svg viewBox="0 0 128 128" className="absolute inset-0 h-full w-full" aria-hidden="true">
          <circle cx="64" cy="64" r="60" fill="#EAF1E3" />
          <circle cx="64" cy="64" r="60" fill="none" stroke="#D6E2C9" strokeWidth="1.5" />
          <circle cx="64" cy="64" r="46" fill="none" stroke="#C9D9B8" strokeWidth="1.5" strokeDasharray="4 6" />
          <circle cx="104" cy="30" r="4" fill="#C2A24E" />
          <circle cx="24" cy="96" r="3" fill="#9CB37E" />
        </svg>
        <div
          className="relative flex h-16 w-16 items-center justify-center rounded-2xl bg-white shadow-sm ring-1 ring-[#E3EBDB]"
          style={{ color: '#3B4A36' }}
        >
          <Glyph size={30} strokeWidth={1.6} />
        </div>
      </div>
      <h3 className="font-display text-lg font-bold text-[#1F261C]">{title}</h3>
      {description && <p className="mt-2 max-w-sm text-sm leading-relaxed text-[#7C8677]">{description}</p>}
      {action && <div className="mt-6">{action}</div>}
    </div>
  );
}
