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
  readonly details?: Record<string, number>;

  constructor(message: string, status: number, errors?: Record<string, string[]>, details?: Record<string, number>) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
    this.details = details;
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

// ══════════════════════════════════════════════════════════════
// In-Memory Caching & Request Deduplication Layer
// ══════════════════════════════════════════════════════════════

export let USE_CACHE = true;

export function setUseCache(enabled: boolean): void {
  USE_CACHE = enabled;
  if (!enabled) {
    clearCache();
  }
}

interface CacheEntry<T> {
  data: T;
  timestamp: number;
  ttl: number;
}

const cache = new Map<string, CacheEntry<unknown>>();
const pendingRequests = new Map<string, Promise<unknown>>();

/** مدة بقاء الكاش الافتراضية للبيانات المرجعية (30 دقيقة). */
const DEFAULT_MASTER_DATA_TTL = 30 * 60 * 1000;

/** مدة بقاء الكاش التلقائي لطلبات القراءة أثناء التنقل بين الشاشات (30 ثانية). */
const DEFAULT_NAVIGATION_CACHE_TTL = 30 * 1000;

/** المسارات المرجعية التي تخزن تلقائياً في الذاكرة لتفادي تكرار طلبها. */
const MASTER_DATA_ENDPOINTS = [
  '/academic-years',
  '/levels',
  '/sections',
  '/fee-types',
  '/collection/years',
  '/roles',
];

function isMasterDataEndpoint(path: string): boolean {
  const clean = path.startsWith('/api') ? path.slice(4) : path;
  return MASTER_DATA_ENDPOINTS.some((ep) => clean === ep || clean.startsWith(ep + '/') || clean.startsWith(ep + '?') || clean.includes('/sections'));
}

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  params?: QueryParams;
  fallbackMessage?: string;
  signal?: AbortSignal;
  cacheTtl?: number;       // تخصيص مدة الكاش بالمللي ثانية
  forceRefresh?: boolean;   // تجاوز الكاش وإجبار الطلب من الشبكة
};

/**
 * طلب موحّد: يركّب الرٔوس، يقرأ أخطاء Laravel (422/401)، ويرمي ApiError مفهوماً،
 * مع دعم التخزين المؤقت ومنع تكرار الطلبات المتزامنة.
 */
export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const {
    method = 'GET',
    body,
    params,
    fallbackMessage,
    signal,
    cacheTtl,
    forceRefresh = false,
  } = options;

  const url = buildUrl(path, params);
  const cacheKey = `${method}:${url}`;

  // 1. التحقق من الكاش في طلبات GET
  if (USE_CACHE && method === 'GET' && !forceRefresh) {
    const cached = cache.get(cacheKey);
    const effectiveTtl = cacheTtl ?? (isMasterDataEndpoint(path) ? DEFAULT_MASTER_DATA_TTL : DEFAULT_NAVIGATION_CACHE_TTL);

    if (cached && effectiveTtl > 0 && Date.now() - cached.timestamp < effectiveTtl) {
      return cached.data as T;
    }
  }

  // 2. منع تكرار الطلبات المتزامنة قيد التنفيذ (In-Flight Request Deduplication)
  if (method === 'GET' && !forceRefresh) {
    const pending = pendingRequests.get(cacheKey);
    if (pending) {
      return pending as Promise<T>;
    }
  }

  // 3. تنفيذ الطلب عبر الشبكة
  const executeRequest = async (): Promise<T> => {
    const response = await fetch(url, {
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

      const details =
        payload && typeof payload === 'object' && 'details' in payload
          ? ((payload as { details?: Record<string, number> }).details ?? undefined)
          : undefined;

      throw new ApiError(message, response.status, errors, details);
    }

    // 4. تخزين النتيجة في الكاش لطلبات GET
    if (USE_CACHE && method === 'GET') {
      const effectiveTtl = cacheTtl ?? (isMasterDataEndpoint(path) ? DEFAULT_MASTER_DATA_TTL : DEFAULT_NAVIGATION_CACHE_TTL);
      if (effectiveTtl > 0) {
        cache.set(cacheKey, {
          data: payload,
          timestamp: Date.now(),
          ttl: effectiveTtl,
        });
      }
    }

    // 5. إبطال الكاش التلقائي عند عمليات التعديل (Mutations)
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      handleAutoInvalidation(path);
    }

    return payload as T;
  };

  const requestPromise = executeRequest().finally(() => {
    pendingRequests.delete(cacheKey);
  });

  if (method === 'GET') {
    pendingRequests.set(cacheKey, requestPromise);
  }

  return requestPromise;
}

/** إبطال ذكي للبيانات المؤقتة عند تنفيذ عمليات تعديل */
function handleAutoInvalidation(path: string): void {
  if (path.includes('/payments') || path.includes('/collection')) {
    invalidateCache('/students');
    invalidateCache('/reports');
    invalidateCache('/dashboard');
    invalidateCache('/enrollments');
  } else if (path.includes('/sections') || path.includes('/levels')) {
    invalidateCache('/sections');
    invalidateCache('/levels');
    invalidateCache('/collection/years');
  } else if (path.includes('/discounts') || path.includes('/exemptions')) {
    invalidateCache('/enrollments');
    invalidateCache('/students');
    invalidateCache('/exemptions');
  } else if (path.includes('/students')) {
    invalidateCache('/students');
    invalidateCache('/collection');
    invalidateCache('/reports');
  }
}

/** تفريغ الكاش كلياً أو بحسب نمط */
export function clearCache(pattern?: string): void {
  if (!pattern) {
    cache.clear();
    return;
  }
  for (const key of cache.keys()) {
    if (key.includes(pattern)) {
      cache.delete(key);
    }
  }
}

/** إبطال كاش مسار محدد أو نمط معين */
export function invalidateCache(endpointPattern: string): void {
  for (const key of cache.keys()) {
    if (key.includes(endpointPattern)) {
      cache.delete(key);
    }
  }
}

export function getCsrfTokenFromCookie(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}
