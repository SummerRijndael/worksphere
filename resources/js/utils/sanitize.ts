import DOMPurify from 'dompurify';

/**
 * Sanitize HTML content to prevent XSS attacks
 *
 * @param dirty - The potentially unsafe HTML string
 * @param options - DOMPurify configuration options
 * @returns Sanitized HTML safe for rendering
 */
export function sanitizeHtml(dirty: string, options?: DOMPurify.Config, hooks?: { 
    beforeSanitizeElements?: (currentNode: Element, data: DOMPurify.HookEvent, config: DOMPurify.Config) => Element | void;
    afterSanitizeElements?: (currentNode: Element, data: DOMPurify.HookEvent, config: DOMPurify.Config) => Element | void;
    // Add other hooks as needed
}): string {
  if (!dirty) return '';

  // Default configuration that allows common formatting but removes dangerous elements
  const defaultConfig: DOMPurify.Config = {
    ALLOWED_TAGS: [
      'p', 'br', 'strong', 'em', 'u', 's', 'del', 'ins',
      'a', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre',
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
      'table', 'thead', 'tbody', 'tr', 'th', 'td',
      'img', 'span', 'div',
    ],
    ALLOWED_ATTR: [
      'href', 'title', 'target', 'rel',
      'class', 'id',
      'src', 'alt', 'width', 'height', 'style', 'align', 'border', 'cellpadding', 'cellspacing' // Added legacy table attributes
    ],
    ALLOW_DATA_ATTR: true,
    ADD_URI_SAFE_ATTR: ['src', 'href'],
    // Allow cid: for inline images and data: for base64
    ALLOWED_URI_REGEXP: /^(?:(?:(?:f|ht)tps?|mailto|tel|callto|cid|data|xmpp):|[^a-z]|[a-z+.\-]+(?:[^a-z+.\-:]|$))/i,
    // Add hooks to ensure links open safely
    RETURN_DOM_FRAGMENT: false,
    RETURN_DOM: false,
  };

  const config = { ...defaultConfig, ...options };

  // Register hooks if provided
  if (hooks) {
      if (hooks.beforeSanitizeElements) DOMPurify.addHook('beforeSanitizeElements', hooks.beforeSanitizeElements);
      if (hooks.afterSanitizeElements) DOMPurify.addHook('afterSanitizeElements', hooks.afterSanitizeElements);
  }

  // Sanitize and return
  const clean = DOMPurify.sanitize(dirty, config) as unknown as string;

  // Cleanup hooks to prevent side effects
  if (hooks) {
      DOMPurify.removeAllHooks(); 
  }

  return clean;
}

/**
 * Sanitize plain text by escaping HTML characters
 *
 * @param text - The text to escape
 * @returns HTML-escaped string
 */
export function escapeHtml(text: string): string {
  if (!text) return '';

  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/**
 * Strip all HTML tags from a string
 *
 * @param html - The HTML string
 * @returns Plain text without HTML tags
 */
export function stripHtml(html: string): string {
  if (!html) return '';

  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: [],
    ALLOWED_ATTR: [],
  });
}
