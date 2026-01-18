/**
 * URL Sanitization Utilities
 * Ported from legacy chatspace-v2 for XSS protection
 */

/**
 * Pattern for safe URLs (http/https or relative paths)
 */
const SAFE_URL_PATTERN = /^(?:https?:\/\/|\/)/i;

/**
 * Check if a URL is safe (http/https or relative path)
 */
export function isSafeUrl(url: string | null | undefined): boolean {
  if (!url || typeof url !== 'string') return false;
  return SAFE_URL_PATTERN.test(url.trim());
}

/**
 * Get a safe image URL or return fallback
 */
export function getSafeImageUrl(url: string | null | undefined, fallback = ''): string {
  if (!isSafeUrl(url)) {
    if (url) {
      console.warn('[urlSanitizer] Blocked unsafe URL:', url);
    }
    return fallback;
  }
  return encodeURI(url!.trim());
}

/**
 * Create safe CSS background-image style object
 */
export function getSafeBackgroundStyle(
  url: string | null | undefined,
  fallbackGradient = 'linear-gradient(135deg, #2563eb, #1e3a8a)'
): Record<string, string> {
  if (!isSafeUrl(url)) {
    return { background: fallbackGradient };
  }
  return {
    backgroundImage: `url(${encodeURI(url!.trim())})`,
    backgroundSize: 'cover',
    backgroundPosition: 'center',
  };
}
