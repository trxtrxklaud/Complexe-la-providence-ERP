import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { createPortal } from 'react-dom';
import { AnimatePresence, motion } from 'motion/react';
import { CheckCircle2, AlertCircle, Info, X, type LucideIcon } from 'lucide-react';

/* ==========================================================================
   نظام الإشعارات (Toasts) — بنية إضافية غير مالية.
   الاستعمال:
     const toast = useToast();
     toast.success('تمّت العملية بنجاح');
   يحترم prefers-reduced-motion عبر motion، وRTL، وبألوان هوية المدرسة.
   ========================================================================== */

type ToastType = 'success' | 'error' | 'info';

type ToastItem = {
  id: number;
  type: ToastType;
  message: string;
};

type PushOptions = { duration?: number };

type ToastApi = {
  success: (message: string, opts?: PushOptions) => void;
  error: (message: string, opts?: PushOptions) => void;
  info: (message: string, opts?: PushOptions) => void;
};

const ToastContext = createContext<ToastApi | null>(null);

/** ألوان مكتفية ذاتيًا (بلا اعتماد على tokens) — آمنة دون اتصال ومطابقة لهوية المدرسة. */
const STYLES: Record<ToastType, { fg: string; bg: string; border: string; Icon: LucideIcon }> = {
  success: { fg: '#1E6B3F', bg: '#EAF4EE', border: '#CDE6D7', Icon: CheckCircle2 },
  error:   { fg: '#A03434', bg: '#FDECEC', border: '#F3CFCF', Icon: AlertCircle },
  info:    { fg: '#2E6BA8', bg: '#EAF3FB', border: '#CFE5F5', Icon: Info },
};

const DEFAULT_DURATION: Record<ToastType, number> = {
  success: 4000,
  error: 6000,
  info: 4500,
};

export function useToast(): ToastApi {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast يجب أن يُستعمل داخل <ToastProvider>');
  return ctx;
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([]);
  const idRef = useRef(0);
  const timers = useRef<Map<number, ReturnType<typeof setTimeout>>>(new Map());

  const dismiss = useCallback((id: number) => {
    setToasts((list) => list.filter((t) => t.id !== id));
    const timer = timers.current.get(id);
    if (timer) {
      clearTimeout(timer);
      timers.current.delete(id);
    }
  }, []);

  const push = useCallback(
    (type: ToastType, message: string, opts?: PushOptions) => {
      const id = ++idRef.current;
      setToasts((list) => [...list, { id, type, message }]);
      const duration = opts?.duration ?? DEFAULT_DURATION[type];
      const timer = setTimeout(() => dismiss(id), duration);
      timers.current.set(id, timer);
    },
    [dismiss],
  );

  const api = useMemo<ToastApi>(
    () => ({
      success: (message, opts) => push('success', message, opts),
      error: (message, opts) => push('error', message, opts),
      info: (message, opts) => push('info', message, opts),
    }),
    [push],
  );

  return (
    <ToastContext.Provider value={api}>
      {children}
      <Toaster toasts={toasts} onDismiss={dismiss} />
    </ToastContext.Provider>
  );
}

function Toaster({ toasts, onDismiss }: { toasts: ToastItem[]; onDismiss: (id: number) => void }) {
  if (typeof document === 'undefined') return null;
  return createPortal(
    <div
      className="no-print pointer-events-none fixed inset-x-0 bottom-6 z-[9999] flex flex-col items-center gap-2 px-4"
      role="region"
      aria-live="polite"
      aria-label="الإشعارات"
    >
      <AnimatePresence initial={false}>
        {toasts.map((t) => {
          const s = STYLES[t.type];
          const Icon = s.Icon;
          return (
            <motion.div
              key={t.id}
              layout
              initial={{ opacity: 0, y: 20, scale: 0.96 }}
              animate={{ opacity: 1, y: 0, scale: 1 }}
              exit={{ opacity: 0, y: 10, scale: 0.98 }}
              transition={{ type: 'spring', stiffness: 380, damping: 30 }}
              role="status"
              dir="rtl"
              className="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-2xl bg-white px-4 py-3"
              style={{ border: `1px solid ${s.border}`, boxShadow: '0 10px 30px -8px rgba(30,36,27,0.25)' }}
            >
              <span
                className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full"
                style={{ backgroundColor: s.bg, color: s.fg }}
              >
                <Icon size={17} />
              </span>
              <p className="flex-1 pt-0.5 text-sm leading-relaxed text-[#1F261C]">{t.message}</p>
              <button
                type="button"
                onClick={() => onDismiss(t.id)}
                aria-label="إغلاق"
                className="mt-0.5 shrink-0 rounded-lg p-1 text-[#7C8677] transition-colors hover:bg-black/5 hover:text-[#1F261C]"
              >
                <X size={16} />
              </button>
            </motion.div>
          );
        })}
      </AnimatePresence>
    </div>,
    document.body,
  );
}
