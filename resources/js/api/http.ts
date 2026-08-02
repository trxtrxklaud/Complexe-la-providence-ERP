/**
 * طبقة HTTP موحّدة.
 *
 * قبل هذا الملف كان كل ملف في api/ يعيد تعريف getHeaders() ويقرأ التوكن
 * من localStorage بنفسه (14 موضعاً). الآن مصدر واحد للحقيقة — وتغيير طريقة
 * تخزين التوكن لاحقاً (مثلاً إلى httpOnly cookie) يتم من هنا فقط.
 */

export const API_BASE = '/api';

export const TOKEN_KEY = 'token';

export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

export function setToken(token: string | null): void {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
  } catch {
    /* تجاهل — وضع تصفح خاص قد يمنع الكتابة */
  }
}

/** رٔوس موحّدة لكل طلبات الـ API. */
export function getHeaders(extra?: Record<string, string>): Record<string, string> {
  const token = getToken();
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(token ? { Authorization: 'Bearer ' + token } : {}),
    ...(extra ?? {}),
  };
}

/** خطأ يحمل رمز الاستجابة وأخطاء التحقق القادمة من Laravel. */
export class ApiError extends Error {
  readonly status: number;
  readonly errors?: Record<string, string[]>;

  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }

  /** أول رسالة تحقق إن وجدت، وإلا الرسالة العامة. */
  get firstError(): string {
    if (this.errors) {
      const first = Object.values(this.errors)[0];
      if (first && first.length) return first[0];
    }
    return this.message;
  }
}

export type QueryParams = Record<string, string | number | boolean | null | undefined>;

/**
 * يركّب المسار مع الاستعلام.
 *
 * القيم المنطقية تُرمّز إلى '1' و '0' وليس 'true' و 'false': قاعدة boolean
 * في لارافيل تقبل true|false|1|0|"1"|"0" حصراً، وترفض النص "true" بـ422.
 * ولأنّ كل الشاشات تمرّ من هنا، الترميز في موضع واحد يمنع تكرار العلّة.
 */
export function buildUrl(path: string, params?: QueryParams): string {
  const base = path.startsWith('/api') ? path : API_BASE + path;
  if (!params) return base;

  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return;
    search.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
  });

  const query = search.toString();
  return query ? base + '?' + query : base;
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  params?: QueryParams;
  fallbackMessage?: string;
  signal?: AbortSignal;
};

/**
 * طلب موحّد: يركّب الرٔوس، يقرأ أخطاء Laravel (422/401)، ويرمي ApiError مفهوماً.
 */
export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, params, fallbackMessage, signal } = options;

  const response = await fetch(buildUrl(path, params), {
    method,
    headers: getHeaders(),
    signal,
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    if (response.status === 401 && path !== '/api/login') {
      setToken(null);
      window.location.href = '/login';
    }

    const message =
      (payload && typeof payload === 'object' && 'message' in payload
        ? String((payload as { message?: unknown }).message ?? '')
        : '') ||
      fallbackMessage ||
      'حدث خطأ أثناء الاتصال بالخادم';

    const errors =
      payload && typeof payload === 'object' && 'errors' in payload
        ? ((payload as { errors?: Record<string, string[]> }).errors ?? undefined)
        : undefined;

    throw new ApiError(message, response.status, errors);
  }

  return payload as T;
}
