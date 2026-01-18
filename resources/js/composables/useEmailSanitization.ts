import { ref, computed, watch } from 'vue';
import { sanitizeHtml } from '@/utils/sanitize';
import type { Email, EmailAttachment } from '@/types/models/email';

/**
 * Composable for email content sanitization and inline image handling.
 * 
 * Features:
 * - DOMPurify-based HTML sanitization (final safety layer)
 * - Inline image (CID) to URL replacement
 * - External image blocking with toggle
 * - Filters inline attachments from visible list
 */
export function useEmailSanitization(email: () => Email | null) {
    const showImages = ref(false);
    const hasBlockedImages = ref(false);

    // Track which content_ids are used inline (to hide from attachments list)
    const inlineContentIds = ref<Set<string>>(new Set());

    // Reset state when email changes
    watch(
        () => email()?.id,
        () => {
            showImages.value = false;
            hasBlockedImages.value = false;
            inlineContentIds.value.clear();
        }
    );

    /**
     * Replace cid: references with actual attachment URLs
     */
    function replaceCidReferences(html: string, attachments: EmailAttachment[]): string {
        if (!html || !attachments?.length) return html;

        let result = html;
        const usedCids = new Set<string>();

        attachments.forEach((att) => {
            if (att.content_id && att.url) {
                // Match src="cid:content_id" patterns
                const cidPattern = new RegExp(
                    `src=["']cid:${att.content_id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}["']`,
                    'gi'
                );
                
                if (cidPattern.test(result)) {
                    result = result.replace(cidPattern, `src="${att.url}"`);
                    usedCids.add(att.content_id);
                }
            }
        });

        inlineContentIds.value = usedCids;
        return result;
    }

    /**
     * Sanitized email body with CID replacement and image blocking
     */
    const sanitizedBody = computed(() => {
        const currentEmail = email();
        if (!currentEmail?.body_html) return '';

        // Step 1: Replace CID references with actual URLs
        let html = replaceCidReferences(currentEmail.body_html, currentEmail.attachments || []);

        // Step 2: Sanitize with DOMPurify
        const clean = sanitizeHtml(html, {
            USE_PROFILES: { html: true },
            ADD_TAGS: ['img'],
            ADD_ATTR: ['src', 'alt', 'style', 'data-original-src'],
        }, {
            beforeSanitizeElements: (currentNode) => {
                if (currentNode instanceof HTMLImageElement) {
                    const src = currentNode.getAttribute('src') || '';
                    // Skip data: URIs (already inline) and local URLs
                    if (!src.startsWith('data:') && !src.startsWith(window.location.origin)) {
                        if (!showImages.value) {
                            // Block external images - store original and clear src
                            currentNode.setAttribute('data-original-src', src);
                            currentNode.setAttribute('src', '');
                            currentNode.style.display = 'none';
                        }
                    }
                }
                return currentNode;
            }
        });

        return clean;
    });

    // Detect if we blocked any images
    watch(
        () => email()?.body_html,
        (html) => {
            if (html && !showImages.value) {
                hasBlockedImages.value = /<img[^>]+src=["'](http|\/\/)/i.test(html);
            } else {
                hasBlockedImages.value = false;
            }
        },
        { immediate: true }
    );

    /**
     * Toggle image visibility
     */
    function toggleImages() {
        showImages.value = !showImages.value;
    }

    /**
     * Attachments that should be shown in the attachments list
     * (excludes inline images that were rendered in the body)
     */
    const visibleAttachments = computed(() => {
        const currentEmail = email();
        if (!currentEmail?.attachments) return [];

        return currentEmail.attachments.filter(
            (att) => !att.content_id || !inlineContentIds.value.has(att.content_id)
        );
    });

    return {
        sanitizedBody,
        showImages,
        hasBlockedImages,
        toggleImages,
        visibleAttachments,
    };
}
