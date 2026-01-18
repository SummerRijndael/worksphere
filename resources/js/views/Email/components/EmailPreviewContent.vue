<template>
    <div class="flex flex-col h-full max-h-[calc(100vh-110px)] overflow-hidden">
        <!-- Header -->
        <div
            class="p-6 border-b border-[var(--border-default)] bg-gradient-to-b from-[var(--surface-secondary)] to-transparent preview-animate-item"
        >
            <div class="flex justify-between items-start">
                <h1
                    class="text-2xl font-semibold text-[var(--text-primary)] leading-tight"
                >
                    {{ email.subject }}
                </h1>
                <div class="flex space-x-1">
                    <button
                        class="p-2 text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] hover:text-[var(--text-primary)] rounded-lg transition-colors"
                        title="Show Metadata"
                        @click="showMetadata = !showMetadata"
                        :class="{ 'bg-[var(--surface-tertiary)] text-[var(--text-primary)]': showMetadata }"
                    >
                        <InfoIcon class="w-5 h-5" />
                    </button>
                    <button
                        class="p-2 text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] hover:text-[var(--text-primary)] rounded-lg transition-colors"
                        title="Print"
                        @click="printEmail"
                    >
                        <PrinterIcon class="w-5 h-5" />
                    </button>
                    <button
                        class="p-2 text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] hover:text-[var(--text-primary)] rounded-lg transition-colors"
                        title="Expand"
                        @click="expandEmail"
                    >
                        <Maximize2Icon class="w-5 h-5" />
                    </button>
                </div>
            </div>

            <!-- Metadata Panel -->
            <div v-if="showMetadata" class="mt-4 p-4 rounded-xl bg-[var(--surface-tertiary)] text-xs font-mono text-[var(--text-secondary)] overflow-x-auto overflow-y-auto max-h-64 border border-[var(--border-default)]">
                <div class="grid grid-cols-[120px_1fr] gap-y-2">
                    <div class="font-semibold text-[var(--text-primary)]">Message-ID:</div>
                    <div class="select-all">{{ email.message_id }}</div>
                    
                    <div class="font-semibold text-[var(--text-primary)]">Date:</div>
                    <div>{{ email.date }}</div>
                    
                    <div class="font-semibold text-[var(--text-primary)]">From:</div>
                    <div class="select-all">{{ email.from_name }} &lt;{{ email.from_email }}&gt;</div>
                    
                    <div class="font-semibold text-[var(--text-primary)]">To:</div>
                    <div class="select-all">
                        <span v-for="(r, i) in email.to" :key="i">{{ r.name }} &lt;{{ r.email }}&gt;{{ i < email.to.length - 1 ? ', ' : '' }}</span>
                    </div>

                    <div v-if="email.cc && email.cc.length" class="font-semibold text-[var(--text-primary)]">Cc:</div>
                    <div v-if="email.cc && email.cc.length" class="select-all">
                        <span v-for="(r, i) in email.cc" :key="i">{{ r.name }} &lt;{{ r.email }}&gt;{{ i < email.cc.length - 1 ? ', ' : '' }}</span>
                    </div>

                    <div class="font-semibold text-[var(--text-primary)]">Folder:</div>
                    <div>{{ email.folder }}</div>
                    
                    <div class="font-semibold text-[var(--text-primary)]">Has Attachments:</div>
                    <div>{{ email.has_attachments ? 'Yes' : 'No' }}</div>

                    <template v-if="email.headers">
                        <div class="col-span-2 border-t border-[var(--border-default)] my-2"></div>
                        <div class="col-span-2 font-semibold text-[var(--text-primary)] mb-1">Full Headers</div>
                        <template v-for="(value, key) in email.headers" :key="key">
                            <div class="font-semibold text-[var(--text-primary)] break-all pr-2">{{ key }}:</div>
                            <div class="break-all whitespace-pre-wrap">{{ Array.isArray(value) ? value.join('\n') : value }}</div>
                        </template>
                    </template>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between">
                <div class="flex items-center">
                    <div class="relative">
                        <img
                            :src="
                                `https://ui-avatars.com/api/?name=${encodeURIComponent(
                                    email.from_name
                                )}&background=6366f1&color=fff`
                            "
                            class="w-11 h-11 rounded-full ring-2 ring-[var(--border-default)] ring-offset-2 ring-offset-[var(--surface-primary)]"
                            alt=""
                        />
                        <div
                            class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 bg-green-500 rounded-full border-2 border-[var(--surface-primary)]"
                        ></div>
                    </div>
                    <div class="ml-4">
                        <p
                            class="text-sm font-semibold text-[var(--text-primary)]"
                        >
                            {{ email.from_name }}
                        </p>
                        <p class="text-xs text-[var(--text-muted)]">
                            <span>&lt;{{ email.from_email }}&gt;</span>
                        </p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-sm text-[var(--text-secondary)]">
                        {{ formatDate(email.date) }}
                    </p>
                    <p class="text-xs text-[var(--text-muted)]">
                        {{ formatRelative(email.date) }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="flex-1 p-6 overflow-y-auto preview-animate-item scrollbar-thin">
            
            <!-- Security / Trust Banner -->
            <div v-if="!isTrustedSource || hasBlockedImages" class="mb-6 space-y-3">
                 <div
                    v-if="!isTrustedSource"
                    class="rounded-xl bg-amber-500/10 border border-amber-500/20 p-3 flex items-start gap-3"
                >
                    <AlertTriangleIcon
                        class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"
                    />
                    <div>
                        <p class="text-sm font-medium text-amber-500">
                            Untrusted Source
                        </p>
                        <p class="text-xs text-amber-500/80 mt-0.5">
                            Use caution with links and attachments.
                        </p>
                    </div>
                </div>

                <div v-if="hasBlockedImages" class="rounded-xl bg-[var(--surface-secondary)] border border-[var(--border-default)] p-3 flex items-center justify-between gap-3">
                     <div class="flex items-center gap-3">
                        <ImageIcon class="w-5 h-5 text-[var(--text-secondary)]" />
                        <div class="text-sm text-[var(--text-secondary)]">
                            Images are hidden for your privacy.
                        </div>
                     </div>
                     <button 
                        @click="showImages = true"
                        class="text-sm font-medium text-[var(--interactive-primary)] hover:underline"
                     >
                        Show Images
                     </button>
                </div>
            </div>

            <!-- Email Content -->
            <div
                class="prose dark:prose-invert max-w-none text-[var(--text-primary)] prose-headings:text-[var(--text-primary)] prose-p:text-[var(--text-secondary)] prose-a:text-[var(--interactive-primary)] email-content"
                v-html="sanitizedBody"
            ></div>

        </div>

        <!-- Attachments (Clamped) -->
        <div
            v-if="email.has_attachments && email.attachments && email.attachments.length"
            class="border-t border-[var(--border-muted)] bg-[var(--surface-secondary)]/50 backdrop-blur-sm"
        >
                <div class="px-6 py-3 flex items-center justify-between">
                    <button
                        @click="isAttachmentsExpanded = !isAttachmentsExpanded"
                        class="text-sm font-semibold text-[var(--text-primary)] flex items-center gap-2 hover:text-[var(--interactive-primary)] transition-colors select-none"
                    >
                        <ChevronRightIcon 
                            class="w-4 h-4 transition-transform duration-200"
                            :class="{ 'rotate-90': isAttachmentsExpanded }"
                        />
                        Attachments
                        <span class="inline-flex items-center justify-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-500 text-white">
                            {{ email.attachments?.length || 0 }}
                        </span>
                    </button>
                    
                    <div class="flex items-center gap-2" v-if="email.attachments && email.attachments.length > 0">
                        <button 
                            v-if="selectedAttachments.size > 0"
                            @click="downloadSelected"
                            class="text-xs font-medium text-[var(--interactive-primary)] hover:underline flex items-center gap-1"
                        >
                            Download Selected ({{ selectedAttachments.size }})
                        </button>
                         <button 
                            @click="downloadAll"
                            class="text-xs font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:underline flex items-center gap-1 ml-2"
                        >
                            Download All
                        </button>
                    </div>
                </div>

                <div 
                    v-show="isAttachmentsExpanded" 
                    class="px-6 pb-4 grid grid-cols-1 sm:grid-cols-2 gap-3 transition-all duration-300 ease-in-out max-h-[30vh] overflow-y-auto scrollbar-thin"
                >
                    <!-- Legacy Fallback if NO email.attachments but hasAttachments=true (shouldn't happen with new mock) -->
                    <div
                        v-if="(!email.attachments || email.attachments.length === 0) && email.has_attachments"
                        class="group relative flex items-center p-3 border border-[var(--border-default)] rounded-xl bg-[var(--surface-secondary)]"
                    >
                        <span class="text-xs text-[var(--text-muted)] italic p-2">Legacy attachment format</span>
                    </div>

                    <div
                        v-for="att in email.attachments"
                        :key="att.id"
                        class="group relative flex items-center p-3 border border-[var(--border-default)] rounded-xl bg-[var(--surface-secondary)] hover:bg-[var(--surface-tertiary)] hover:border-[var(--interactive-primary)]/30 transition-all cursor-pointer select-none"
                        :class="{'ring-1 ring-inset ring-[var(--interactive-primary)] bg-[var(--interactive-primary)]/5': selectedAttachments.has(att.id)}"
                        @click="toggleAttachment(att.id)"
                    >
                        <!-- Checkbox Overlay -->
                        <div class="absolute top-3 right-3 z-10" @click.stop>
                             <input type="checkbox" 
                                :checked="selectedAttachments.has(att.id)" 
                                @change="toggleAttachment(att.id)"
                                class="rounded border-[var(--border-default)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)]/50 h-4 w-4 bg-[var(--surface-primary)]"
                            />
                        </div>

                        <div
                            class="p-2 rounded-lg bg-[var(--interactive-primary)]/10"
                        >
                            <FileIcon
                                v-if="att.type === 'application/pdf'"
                                class="w-6 h-6 text-red-500"
                            />
                            <ImageIcon
                                v-else-if="att.type && att.type.startsWith('image/')"
                                class="w-6 h-6 text-blue-500"
                            />
                            <FileIcon
                                v-else
                                class="w-6 h-6 text-[var(--interactive-primary)]"
                            />
                        </div>
                        <div class="ml-3 flex-1 min-w-0 pr-6">
                            <p
                                class="text-sm font-medium text-[var(--text-primary)] truncate"
                                :title="att.name"
                            >
                                {{ att.name }}
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                {{ att.size }}
                            </p>
                        </div>
                    </div>
                </div>
        </div>


        <!-- Action Bar -->
        <div
            class="p-4 border-t border-[var(--border-default)] bg-[var(--surface-secondary)] preview-animate-item"
        >
            <div class="flex gap-2">
                <button
                    @click="emit('reply')"
                    class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium text-white bg-[var(--interactive-primary)] hover:bg-[var(--interactive-primary-hover)] shadow-lg shadow-[var(--interactive-primary)]/25 transition-all hover:scale-[1.02] active:scale-[0.98]"
                >
                    <ReplyIcon class="w-4 h-4" />
                    Reply
                </button>
                <button
                    @click="emit('reply-all')"
                    class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium text-[var(--text-primary)] bg-[var(--surface-elevated)] border border-[var(--border-default)] hover:bg-[var(--surface-tertiary)] transition-all hover:scale-[1.02] active:scale-[0.98]"
                >
                    <ReplyAllIcon class="w-4 h-4" />
                    Reply All
                </button>
                <button
                    @click="emit('forward')"
                    class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium text-[var(--text-primary)] bg-[var(--surface-elevated)] border border-[var(--border-default)] hover:bg-[var(--surface-tertiary)] transition-all hover:scale-[1.02] active:scale-[0.98]"
                >
                    <ForwardIcon class="w-4 h-4" />
                    Forward
                </button>
                <button
                    @click="emit('forward-as-attachment')"
                    class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium text-[var(--text-primary)] bg-[var(--surface-elevated)] border border-[var(--border-default)] hover:bg-[var(--surface-tertiary)] transition-all hover:scale-[1.02] active:scale-[0.98]"
                    title="Forward as attachment (.eml)"
                >
                    <PaperclipIcon class="w-4 h-4" />
                    Forward as Attachment
                </button>
                <button
                    @click="exportAsEml"
                    class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium text-[var(--text-primary)] bg-[var(--surface-elevated)] border border-[var(--border-default)] hover:bg-[var(--surface-tertiary)] transition-all hover:scale-[1.02] active:scale-[0.98]"
                    title="Download as .eml file"
                >
                    <DownloadIcon class="w-4 h-4" />
                    Export .eml
                </button>
                <div class="flex-1"></div>
                <button
                    class="p-2.5 rounded-xl text-[var(--text-secondary)] hover:text-[var(--color-error)] hover:bg-[var(--color-error)]/10 transition-colors"
                    title="Delete"
                >
                    <TrashIcon class="w-4 h-4" />
                </button>
                <button
                    class="p-2.5 rounded-xl text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] transition-colors"
                    title="More"
                >
                    <MoreHorizontalIcon class="w-4 h-4" />
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, nextTick, onBeforeUnmount, onMounted } from "vue";
import {
    PrinterIcon,
    Maximize2Icon,
    FileIcon,
    AlertTriangleIcon,
    ReplyIcon,
    ForwardIcon,
    TrashIcon,
    MoreHorizontalIcon,
    ReplyAllIcon,
    ImageIcon,
    ChevronRightIcon,
    InfoIcon,
    PaperclipIcon,
    DownloadIcon,
} from "lucide-vue-next";
import { format, formatDistanceToNow } from "date-fns";
import type { Email } from "@/types/models/email";
import { animate, stagger } from "animejs";
import { sanitizeHtml } from "@/utils/sanitize";

const props = defineProps<{
    email: Email;
}>();

const emit = defineEmits<{
    reply: [];
    "reply-all": [];
    forward: [];
    "forward-as-attachment": [];
}>();

// --- Security & Privacy ---
const showImages = ref(false);
const hasBlockedImages = ref(false);
const isAttachmentsExpanded = ref(false);
const showMetadata = ref(false);

// Reset image state when email changes
watch(() => props.email?.id, () => {
    showImages.value = false;
    hasBlockedImages.value = false;
    selectedAttachments.value.clear();
});

const sanitizedBody = computed(() => {
    if (!props.email?.body_html) return '';
    
    // Step 1: Replace cid: references with actual attachment URLs
    let html = props.email.body_html;
    if (props.email.attachments?.length) {
        props.email.attachments.forEach(att => {
            if (att.content_id && att.url) {
                // Match src="cid:content_id" patterns (case insensitive)
                const cidPattern = new RegExp(
                    `src=["']cid:${att.content_id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}["']`,
                    'gi'
                );
                html = html.replace(cidPattern, `src="${att.url}"`);
            }
        });
    }
    
    // Step 2: Sanitize with DOMPurify
    const clean = sanitizeHtml(html, {
        USE_PROFILES: { html: true },
        ADD_TAGS: ['img'],
        ADD_ATTR: ['src', 'alt', 'style'],
    }, {
        beforeSanitizeElements: (currentNode) => {
            if (currentNode instanceof HTMLImageElement) {
                 // Check if it's an external image (not attachment or data URI)
                 const src = currentNode.getAttribute('src') || '';
                 if (src && !src.startsWith('data:') && !src.startsWith(window.location.origin) && !src.startsWith('/storage')) {
                     if (!showImages.value) {
                         // Block external images - show placeholder instead of hiding
                         currentNode.setAttribute('data-blocked-src', src);
                         currentNode.setAttribute('src', 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 200 100"%3E%3Crect fill="%23e5e7eb" width="200" height="100"/%3E%3Ctext x="50%25" y="50%25" dominant-baseline="middle" text-anchor="middle" fill="%236b7280" font-family="sans-serif" font-size="12"%3EImage blocked%3C/text%3E%3C/svg%3E');
                         currentNode.setAttribute('data-blocked', 'true');
                         currentNode.style.maxWidth = '200px';
                         currentNode.style.border = '1px dashed #d1d5db';
                         currentNode.style.borderRadius = '4px';
                     }
                 }
            }
            return currentNode;
        }
    });
    
    return clean;
});

// We need to update `hasBlockedImages` separately to avoid computed side-effects
watch(sanitizedBody, () => {
    // Only way to know if we BLOCKED images is if we found them AND showImages is false.
    // Let's parse the original body or use a temporary div to check for img tags.
    if (props.email?.body_html && !showImages.value) {
         // Simple heuristic: does contain <img src="http... ?
         const hasImg = /<img[^>]+src=["'](http|\/\/)/i.test(props.email.body_html);
         hasBlockedImages.value = hasImg;
    } else {
        hasBlockedImages.value = false;
    }
}, { immediate: true });


const isTrustedSource = computed(() => {
    // For now, trust the user's domain and common providers, or just return true to unblock UI
    if (!props.email?.from_email) return false;
    const trustedDomains = ['link-technologies.info', 'gmail.com', 'outlook.com', 'hotmail.com'];
    const emailDomain = props.email.from_email.split('@')[1];
    return trustedDomains.includes(emailDomain);
});

function formatDate(dateStr: string) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return 'Invalid Date';
    return format(date, "MMM d, yyyy, h:mm a");
}

function formatRelative(dateStr: string) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return 'Unknown Date';
    return formatDistanceToNow(date, { addSuffix: true });
}

// --- Attachments ---
const selectedAttachments = ref<Set<string>>(new Set());

function toggleAttachment(id: string) {
    if (selectedAttachments.value.has(id)) {
        selectedAttachments.value.delete(id);
    } else {
        selectedAttachments.value.add(id);
    }
}

function downloadSelected() {
    const selected = props.email.attachments.filter(a => selectedAttachments.value.has(a.id));
    if (selected.length === 0) return;
    
    if (selected.length === 1) {
        // Single file - direct download
        window.open(`/api/emails/attachments/${selected[0].id}/download`, '_blank');
    } else {
        // Multiple files - batch download as ZIP
        const ids = selected.map(a => a.id);
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/api/emails/attachments/download-batch';
        form.target = '_blank';
        
        // Add CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (csrfToken) {
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);
        }
        
        // Add IDs
        ids.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = id;
            form.appendChild(input);
        });
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
}

function downloadAll() {
    if (!props.email.attachments?.length) return;
    
    if (props.email.attachments.length === 1) {
        window.open(`/api/emails/attachments/${props.email.attachments[0].id}/download`, '_blank');
    } else {
        const ids = props.email.attachments.map(a => a.id);
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/api/emails/attachments/download-batch';
        form.target = '_blank';
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (csrfToken) {
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);
        }
        
        ids.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = id;
            form.appendChild(input);
        });
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
}

// --- Print ---
function printEmail() {
    const printWindow = window.open('', '_blank');
    if (!printWindow) return;
    
    const email = props.email;
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>${email.subject}</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 40px; max-width: 800px; margin: 0 auto; }
                .header { border-bottom: 1px solid #ddd; padding-bottom: 20px; margin-bottom: 20px; }
                .subject { font-size: 24px; font-weight: 600; margin-bottom: 10px; }
                .meta { color: #666; font-size: 14px; }
                .meta div { margin: 4px 0; }
                .body { line-height: 1.6; }
                @media print { body { padding: 20px; } }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="subject">${email.subject}</div>
                <div class="meta">
                    <div><strong>From:</strong> ${email.from_name || ''} &lt;${email.from_email}&gt;</div>
                    <div><strong>Date:</strong> ${formatDate(email.date)}</div>
                    <div><strong>To:</strong> ${email.to?.map(t => t.email).join(', ') || ''}</div>
                </div>
            </div>
            <div class="body">${email.body_html || email.body_plain || ''}</div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// --- Export as EML ---
function exportAsEml() {
    // Open download in new tab
    window.open(`/api/emails/${props.email.id}/export`, '_blank');
}

// --- Expand to Popup ---
function expandEmail() {
    const email = props.email;
    const width = 900;
    const height = 700;
    const left = (window.screen.width - width) / 2;
    const top = (window.screen.height - height) / 2;
    
    // Process body to replace CID refs with attachment URLs (same as sanitizedBody)
    let processedBody = email.body_html || email.body_plain || '';
    if (email.attachments?.length) {
        email.attachments.forEach(att => {
            if (att.content_id && att.url) {
                const cidPattern = new RegExp(
                    `src=["']cid:${att.content_id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}["']`,
                    'gi'
                );
                processedBody = processedBody.replace(cidPattern, `src="${att.url}"`);
            }
        });
    }
    
    // Detect dark mode from main app
    const isDark = document.documentElement.classList.contains('dark') || 
                   window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    const popup = window.open('', '_blank', 
        `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
    );
    if (!popup) return;
    
    popup.document.write(`
        <!DOCTYPE html>
        <html class="${isDark ? 'dark' : ''}">
        <head>
            <title>${email.subject} - CoreSync Email</title>
            <style>
                :root {
                    --bg-primary: #ffffff;
                    --bg-secondary: #fafafa;
                    --text-primary: #1a1a1a;
                    --text-secondary: #666666;
                    --border-color: #e5e5e5;
                    --btn-primary-bg: #2563eb;
                    --btn-primary-hover: #1d4ed8;
                }
                .dark {
                    --bg-primary: #1a1a1a;
                    --bg-secondary: #262626;
                    --text-primary: #f5f5f5;
                    --text-secondary: #a3a3a3;
                    --border-color: #404040;
                    --btn-primary-bg: #3b82f6;
                    --btn-primary-hover: #2563eb;
                }
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                    margin: 0; 
                    background: var(--bg-secondary);
                    color: var(--text-primary);
                }
                .container { max-width: 100%; margin: 0; background: var(--bg-primary); min-height: 100vh; }
                .header { padding: 20px; border-bottom: 1px solid var(--border-color); background: var(--bg-secondary); }
                .subject { font-size: 20px; font-weight: 600; margin-bottom: 12px; color: var(--text-primary); }
                .meta { color: var(--text-secondary); font-size: 13px; }
                .meta-row { margin: 4px 0; }
                .body { padding: 20px; line-height: 1.6; color: var(--text-primary); }
                .body img { max-width: 100%; height: auto; }
                .actions { display: flex; gap: 8px; margin-top: 16px; }
                .btn { padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); }
                .btn:hover { background: var(--bg-secondary); }
                .btn-primary { background: var(--btn-primary-bg); color: white; border-color: var(--btn-primary-bg); }
                .btn-primary:hover { background: var(--btn-primary-hover); }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div class="subject">${email.subject}</div>
                    <div class="meta">
                        <div class="meta-row"><strong>From:</strong> ${email.from_name || ''} &lt;${email.from_email}&gt;</div>
                        <div class="meta-row"><strong>To:</strong> ${email.to?.map(t => t.email).join(', ') || ''}</div>
                        <div class="meta-row"><strong>Date:</strong> ${formatDate(email.date)}</div>
                    </div>
                    <div class="actions">
                        <button class="btn btn-primary" onclick="window.opener?.postMessage({type:'reply',id:'${email.id}'},'*');window.close();">Reply</button>
                        <button class="btn" onclick="window.opener?.postMessage({type:'reply-all',id:'${email.id}'},'*');window.close();">Reply All</button>
                        <button class="btn" onclick="window.opener?.postMessage({type:'forward',id:'${email.id}'},'*');window.close();">Forward</button>
                        <button class="btn" onclick="window.print();">Print</button>
                    </div>
                </div>
                <div class="body">${processedBody}</div>
            </div>
        </body>
        </html>
    `);
    popup.document.close();
}

// --- Animation ---
let animation: any = null;

function runEnterAnimation() {
    if (!props.email) return;
    if (animation) animation.pause();

    const targets = document.querySelectorAll(".preview-animate-item");
    if (targets.length === 0) return;

    animation = animate(targets, {
        opacity: [0, 1],
        translateY: [15, 0],
        delay: stagger(80),
        duration: 350,
        easing: "easeOutQuad",
    });
}

watch(
    () => props.email,
    () => {
        nextTick(() => runEnterAnimation());
    }
);

onMounted(() => {
    if (props.email) {
        nextTick(() => runEnterAnimation());
    }
});

onBeforeUnmount(() => {
    if (animation) animation.pause();
});
</script>
