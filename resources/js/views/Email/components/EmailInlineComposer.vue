<template>
    <div class="flex flex-col h-full max-h-[calc(100vh-110px)] bg-[var(--surface-primary)] overflow-hidden">
        <!-- Compact Header -->
        <div class="p-4 border-b border-[var(--border-default)] bg-gradient-to-r from-[var(--surface-secondary)] to-transparent">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <div class="p-1.5 rounded-lg" :class="modeStyles.bg">
                        <component :is="modeIcon" :class="['w-4 h-4', modeStyles.text]" />
                    </div>
                    <span class="text-sm font-semibold text-[var(--text-primary)]">{{ modeLabel }}</span>
                </div>
                
                <!-- Action Buttons (Moved to Header) -->
                <div class="flex items-center gap-3">
                    <span class="text-xs text-[var(--text-muted)] tabular-nums">{{ characterCount }} chars</span>
                    <div class="h-4 w-px bg-[var(--border-default)]"></div>
                    <button
                        @click="emit('close')"
                        class="p-2 rounded-lg text-[var(--text-muted)] hover:text-[var(--color-error)] hover:bg-[var(--color-error)]/10 transition-colors"
                        title="Discard"
                    >
                        <TrashIcon class="w-4 h-4" />
                    </button>
                    <button
                        @click="handleSend"
                        class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold text-white bg-[var(--interactive-primary)] hover:bg-[var(--interactive-primary-hover)] shadow-lg shadow-[var(--interactive-primary)]/25 transition-all hover:scale-[1.02] active:scale-[0.98]"
                    >
                        Send
                        <SendIcon class="w-3.5 h-3.5" />
                    </button>
                </div>
            </div>

            <!-- Email Fields -->
            <div class="space-y-2">
                <!-- From Field -->
                <div class="flex items-center gap-2">
                    <span class="text-xs text-[var(--text-muted)] w-12 flex-shrink-0">From</span>
                    <div class="flex-1 relative">
                        <Dropdown :items="accountItems" align="start" class="w-full">
                            <template #trigger>
                                <button
                                    class="flex items-center gap-2 w-full px-3 py-1.5 text-sm border border-[var(--border-default)] rounded-lg bg-[var(--surface-elevated)] hover:bg-[var(--surface-secondary)] transition-colors text-left"
                                >
                                    <span v-if="selectedAccount" class="flex-1 truncate">
                                        {{ selectedAccount.name }} &lt;{{ selectedAccount.email }}&gt;
                                    </span>
                                    <span v-else class="text-[var(--text-muted)]">Select account...</span>
                                    <ChevronDownIcon class="w-4 h-4 text-[var(--text-muted)]" />
                                </button>
                            </template>
                        </Dropdown>
                    </div>
                </div>

                <!-- To Field -->
                <div class="flex items-start gap-2">
                    <span class="text-xs text-[var(--text-muted)] w-12 pt-2.5 flex-shrink-0">To</span>
                    <div class="flex-1">
                        <EmailTagInput v-model="toEmails" placeholder="Recipients" />
                    </div>
                    <div class="flex items-center gap-1 pt-1.5">
                        <button
                            @click="showCc = !showCc"
                            class="px-2 py-1 text-xs rounded-md transition-colors"
                            :class="showCc ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)]'"
                        >
                            Cc
                        </button>
                        <button
                            @click="showBcc = !showBcc"
                            class="px-2 py-1 text-xs rounded-md transition-colors"
                            :class="showBcc ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)]'"
                        >
                            Bcc
                        </button>
                    </div>
                </div>

                <!-- CC Field -->
                <Transition name="slide-fade">
                    <div v-if="showCc" class="flex items-start gap-2">
                        <span class="text-xs text-[var(--text-muted)] w-12 pt-2.5 flex-shrink-0">Cc</span>
                        <EmailTagInput v-model="ccEmails" placeholder="CC recipients" class="flex-1" />
                    </div>
                </Transition>

                <!-- BCC Field -->
                <Transition name="slide-fade">
                    <div v-if="showBcc" class="flex items-start gap-2">
                        <span class="text-xs text-[var(--text-muted)] w-12 pt-2.5 flex-shrink-0">Bcc</span>
                        <EmailTagInput v-model="bccEmails" placeholder="BCC recipients" class="flex-1" />
                    </div>
                </Transition>

                <!-- Subject Field (always shown) -->
                <div class="flex items-center gap-2">
                    <span class="text-xs text-[var(--text-muted)] w-12 flex-shrink-0">Subject</span>
                    <input
                        type="text"
                        v-model="subject"
                        class="flex-1 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg px-3 py-2 text-sm text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/50 focus:border-[var(--interactive-primary)] transition-all"
                        placeholder="Subject"
                    />
                </div>
            </div>
        </div>

        <!-- Editor -->
        <div class="flex-1 p-4 overflow-y-auto min-h-0">
            <EditorContent :editor="editor" class="prose prose-sm max-w-none focus:outline-none min-h-[200px]" />
            
            <!-- Attachments List -->
            <div v-if="attachments.length > 0" class="flex flex-wrap gap-2 p-2 relative z-10">
                <div
                    v-for="(file, index) in attachments"
                    :key="index"
                    class="flex items-center gap-2 px-3 py-1.5 bg-[var(--surface-tertiary)] rounded-full text-xs border border-[var(--border-default)]"
                >
                    <span class="truncate max-w-[200px]">{{ file.name }}</span>
                    <span class="text-[var(--text-muted)]">({{ formatFileSize(file.size) }})</span>
                    <button
                        @click="removeAttachment(index)"
                        class="p-0.5 rounded hover:bg-[var(--surface-secondary)] text-[var(--text-secondary)]"
                    >
                        <XIcon class="w-3 h-3" />
                    </button>
                </div>
            </div>
            <!-- Signature Preview -->
            <div v-if="selectedSignature?.content" class="mt-4 pt-4 border-t border-dashed border-[var(--border-default)]">
                <div class="text-xs text-[var(--text-muted)] mb-2">Signature</div>
                <div class="text-sm text-[var(--text-secondary)]" v-html="selectedSignature.content"></div>
            </div>

            <!-- Quoted Content for Reply/Forward -->
            <div
                v-if="replyTo && (actualMode === 'reply' || actualMode === 'forward')"
                class="mt-4 pt-4 border-t border-dashed border-[var(--border-default)]"
            >
                <div class="text-xs text-[var(--text-muted)] mb-2">
                    {{ actualMode === "reply" ? "On" : "Forwarded message" }}
                    {{ formatDate(replyTo.date) }}, {{ replyTo.from_name || replyTo.from_email }} wrote:
                </div>
                <div
                    class="pl-3 border-l-2 border-[var(--border-default)] text-sm text-[var(--text-secondary)]"
                    v-html="replyTo.body_html || replyTo.body_plain"
                ></div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="p-3 border-t border-[var(--border-default)] bg-[var(--surface-secondary)]">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1">
                    <!-- Attach -->
                    <input
                        ref="fileInput"
                        type="file"
                        multiple
                        class="hidden"
                        @change="handleFileSelect"
                    />
                    <button
                        @click="fileInput?.click()"
                        class="p-2 rounded-lg text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] transition-colors"
                        title="Attach file"
                    >
                        <PaperclipIcon class="w-4 h-4" />
                    </button>
                    
                    <div class="w-px h-5 bg-[var(--border-default)] mx-1"></div>
                    
                    <!-- Formatting -->
                    <button
                        @click="editor?.chain().focus().toggleBold().run()"
                        :class="[editor?.isActive('bold') ? 'bg-[var(--surface-tertiary)] text-[var(--text-primary)]' : 'text-[var(--text-secondary)]']"
                        class="p-2 rounded-lg hover:bg-[var(--surface-tertiary)] transition-colors"
                    >
                        <BoldIcon class="w-4 h-4" />
                    </button>
                    <button
                        @click="editor?.chain().focus().toggleItalic().run()"
                        :class="[editor?.isActive('italic') ? 'bg-[var(--surface-tertiary)] text-[var(--text-primary)]' : 'text-[var(--text-secondary)]']"
                        class="p-2 rounded-lg hover:bg-[var(--surface-tertiary)] transition-colors"
                    >
                        <ItalicIcon class="w-4 h-4" />
                    </button>
                    <button
                        @click="editor?.chain().focus().toggleBulletList().run()"
                        :class="[editor?.isActive('bulletList') ? 'bg-[var(--surface-tertiary)] text-[var(--text-primary)]' : 'text-[var(--text-secondary)]']"
                        class="p-2 rounded-lg hover:bg-[var(--surface-tertiary)] transition-colors"
                    >
                        <ListIcon class="w-4 h-4" />
                    </button>

                    <div class="w-px h-5 bg-[var(--border-default)] mx-1"></div>

                    <!-- Template Selector -->
                    <Dropdown :items="templateItems" align="start">
                        <template #trigger>
                            <button
                                class="p-2 rounded-lg text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] transition-colors"
                                title="Insert template"
                            >
                                <FileTextIcon class="w-4 h-4" />
                            </button>
                        </template>
                    </Dropdown>

                    <!-- Signature Selector -->
                    <Dropdown :items="signatureItems" align="start">
                        <template #trigger>
                            <button
                                class="p-2 rounded-lg text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] transition-colors"
                                title="Select signature"
                            >
                                <PenToolIcon class="w-4 h-4" />
                            </button>
                        </template>
                    </Dropdown>

                    <div class="w-px h-5 bg-[var(--border-default)] mx-1"></div>

                    <!-- AI Assist (Future Feature) -->
                    <div class="flex items-center gap-1">
                        <button
                            @click="handleAiAssist"
                            class="p-1.5 rounded-lg text-[var(--accent-primary)] hover:bg-[var(--surface-active)] transition-colors flex items-center gap-1.5"
                            title="AI Assist (Coming Soon)"
                        >
                            <SparklesIcon class="w-4 h-4" />
                            <span class="text-xs font-medium">Auto-Complete</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeUnmount, markRaw, onMounted } from "vue";
import { useEditor, EditorContent } from "@tiptap/vue-3";
import StarterKit from "@tiptap/starter-kit";
import Placeholder from "@tiptap/extension-placeholder";
import {
    XIcon,
    SendIcon,
    PaperclipIcon,
    BoldIcon,
    TrashIcon,
    ItalicIcon,
    ListIcon,
    ReplyIcon,
    ForwardIcon,
    PencilIcon,
    FileTextIcon,
    PenToolIcon,
    SparklesIcon,
    ReplyAllIcon,
    ChevronDownIcon,
    CheckIcon,
} from "lucide-vue-next";
import { format } from "date-fns";
import { watch } from "vue";
import type { Email } from "@/types/models/email";
import { useEmailStore } from '@/stores/emailStore';
import EmailTagInput from "./EmailTagInput.vue";
import Dropdown from "@/components/ui/Dropdown.vue";
import { useEmailSignatures } from "../composables/useEmailSignatures";
import { useEmailTemplates } from "../composables/useEmailTemplates";
import { emailAccountService } from "@/services/email-account.service";

const props = defineProps<{
    mode: string;
    replyTo?: Email | null;
}>();

const emit = defineEmits<{
    close: [];
    send: [];
}>();

// Composables
const { signatures, selectedSignatureId, getSignatureById } = useEmailSignatures();
const { templates, getTemplateById } = useEmailTemplates();
const store = useEmailStore();

// Account Management
const accounts = ref<any[]>([]);
const selectedAccountId = ref<string | number | null>(null);

const selectedAccount = computed(() => 
    accounts.value.find(a => a.id === selectedAccountId.value)
);

const accountItems = computed(() => 
    accounts.value.map(a => ({
        label: `${a.name} <${a.email}>`,
        icon: a.id === selectedAccountId.value ? CheckIcon : undefined,
        action: () => { selectedAccountId.value = a.id; }
    }))
);

// Fetch accounts and set default
onMounted(async () => {
    try {
        const response = await emailAccountService.list();
        accounts.value = response || [];
        
        // Determine initial account
        if (accounts.value.length > 0) {
            // 1. If replying, try to match the "TO" of the original email to one of our accounts
            if (props.replyTo) {
               // Checking if any of our accounts received this email
               // This is tricky because `replyTo.to` is an array of recipients. 
               // We check if any of our account emails are in the recipient list.
               const originalRecipients = props.replyTo.to?.map((t: any) => t.email) || [];
               // Also check CC
               const originalCc = props.replyTo.cc?.map((c: any) => c.email) || [];
               const allDirectRecipients = [...originalRecipients, ...originalCc];

               const matchingAccount = accounts.value.find(a => allDirectRecipients.includes(a.email));
               if (matchingAccount) {
                   selectedAccountId.value = matchingAccount.id;
                   return;
               }
            }

            // 2. Use currently selected account from sidebar
            if (store.selectedEmailId || store.selectedAccountId) { // store.selectedAccountId is likely available via storeToRefs if we imported it
                // store property is likely called 'selectedAccountId' but let's access it safely via store instance
                // We need to verify if the store exposes 'selectedAccountId' directly or we need storeToRefs
                // Looking at previous file view, logic was: `const { selectedAccountId } = storeToRefs(store);`
                // But here we are inside setup, let's just assume store state
                if (store.selectedAccountId) {
                     const activeAccount = accounts.value.find(a => a.id == store.selectedAccountId || a.public_id == store.selectedAccountId);
                     if (activeAccount) {
                         selectedAccountId.value = activeAccount.id;
                         return;
                     }
                }
            }

            // 3. Fallback to Default
            const defaultAccount = accounts.value.find(a => a.is_default);
            if (defaultAccount) {
                selectedAccountId.value = defaultAccount.id;
                return;
            }

            // 4. Fallback to First
            selectedAccountId.value = accounts.value[0].id;
        }
    } catch (e) {
        console.error("Failed to fetch email accounts", e);
    }
});

// Parse mode from tab id
const actualMode = computed(() => {
    if (props.mode.startsWith("reply-all")) return "reply-all";
    if (props.mode.startsWith("reply")) return "reply";
    if (props.mode.startsWith("forward-as-attachment")) return "forward-as-attachment";
    if (props.mode.startsWith("forward")) return "forward";
    if (props.mode.startsWith("compose")) return "compose";
    return "compose";
});

const modeLabel = computed(() => {
    switch (actualMode.value) {
        case "reply-all": return "Reply All";
        case "reply": return "Reply";
        case "forward-as-attachment": return "Forward as Attachment";
        case "forward": return "Forward";
        default: return "New Email";
    }
});

const modeIcon = computed(() => {
    switch (actualMode.value) {
        case "reply-all": return markRaw(ReplyAllIcon);
        case "reply": return markRaw(ReplyIcon);
        case "forward-as-attachment": return markRaw(PaperclipIcon);
        case "forward": return markRaw(ForwardIcon);
        default: return markRaw(PencilIcon);
    }
});

const modeStyles = computed(() => {
    switch (actualMode.value) {
        case "reply-all": return { bg: "bg-indigo-500/10", text: "text-indigo-500" };
        case "reply": return { bg: "bg-blue-500/10", text: "text-blue-500" };
        case "forward-as-attachment": return { bg: "bg-orange-500/10", text: "text-orange-500" };
        case "forward": return { bg: "bg-purple-500/10", text: "text-purple-500" };
        default: return { bg: "bg-green-500/10", text: "text-green-500" };
    }
});

// Form state
const toEmails = ref<string[]>([]);
const ccEmails = ref<string[]>([]);
const bccEmails = ref<string[]>([]);
const showCc = ref(false);
const showBcc = ref(false);
const subject = ref("");
const attachments = ref<File[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);

function formatFileSize(bytes: number) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function handleFileSelect(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files) {
        attachments.value.push(...Array.from(input.files));
    }
    // Reset input so same file can be selected again
    if (input.value) input.value = '';
}

function removeAttachment(index: number) {
    attachments.value.splice(index, 1);
}

async function fetchEmlAttachment(emailId: number | string, subject: string) {
    try {
        const response = await fetch(`/api/emails/${emailId}/export`);
        
        if (!response.ok) throw new Error("Failed to export email");
        
        const blob = await response.blob();
        const safeName = subject.replace(/[^a-zA-Z0-9_-]/g, '_') + '.eml';
        
        const file = new File([blob], safeName, { type: 'message/rfc822' });
        
        // Add to attachments if not already present
        if (!attachments.value.some(f => f.name === safeName && f.size === file.size)) {
            attachments.value.push(file);
        }
    } catch (e) {
        console.error("Error fetching EML attachment:", e);
        // Could add toast notification here
    }
}

// Initialize based on mode
watch(actualMode, (mode) => {
    if (!props.replyTo) {
        subject.value = "";
        toEmails.value = [];
        return;
    }

    const { from_email, to = [], cc = [], subject: origSubject } = props.replyTo;

    // Subject
    if (mode === 'forward' || mode === 'forward-as-attachment') {
        subject.value = origSubject?.startsWith('Fwd:') ? origSubject : `Fwd: ${origSubject || ''}`;
    } else if (mode === 'reply' || mode === 'reply-all') {
        subject.value = origSubject?.startsWith('Re:') ? origSubject : `Re: ${origSubject || ''}`;
    } else {
        subject.value = "";
    }
    
    // Handle Forward as Attachment
    if (mode === 'forward-as-attachment') {
        fetchEmlAttachment(props.replyTo.id, origSubject || 'email');
    }
    
    // Recipients
    if (mode === 'reply') {
        toEmails.value = from_email ? [from_email] : [];
        ccEmails.value = [];
    } else if (mode === 'reply-all') {
        // TO: Sender + Original TOs
        const allTos = new Set<string>();
        if (from_email) allTos.add(from_email);
        to.forEach(t => { if (t?.email) allTos.add(t.email); });
        toEmails.value = Array.from(allTos);
        
        // CC: Original CCs
        ccEmails.value = cc.filter(c => c?.email).map(c => c.email);
        
        if (ccEmails.value.length > 0) showCc.value = true;
    } else if (mode === 'forward') {
        toEmails.value = [];
        ccEmails.value = [];
    }
}, { immediate: true });

const characterCount = computed(() => {
    return editor.value?.getText().length || 0;
});

// Dropdown items for templates
const templateItems = computed(() => 
    templates.value.map(t => ({
        label: t.name,
        icon: FileTextIcon,
        action: () => applyTemplate(t.id)
    }))
);

const selectedSignature = computed(() => getSignatureById(selectedSignatureId.value));

// Dropdown items for signatures
const signatureItems = computed(() => 
    signatures.value.map(s => ({
        label: s.name,
        icon: PenToolIcon,
        action: () => { selectedSignatureId.value = s.id; }
    }))
);

const editor = useEditor({
    content: "",
    extensions: [
        StarterKit,
        Placeholder.configure({
            placeholder: "Write your message...",
        }),
    ],
    editorProps: {
        attributes: {
            class: "prose dark:prose-invert max-w-none focus:outline-none min-h-[100px] text-[var(--text-primary)]",
        },
    },
});

function applyTemplate(templateId: string) {
    const template = getTemplateById(templateId);
    if (template) {
        subject.value = template.subject;
        editor.value?.commands.setContent(template.body);
    }
}

function formatDate(dateStr: string) {
    return format(new Date(dateStr), "MMM d, yyyy, h:mm a");
}

async function handleSend() {
    if (!selectedAccount.value) {
        alert('Please select a sending account.');
        return;
    }

    // Construct FormData
    const formData = new FormData();
    
    // Account ID
    formData.append('account_id', String(selectedAccount.value.id));
    
    // Recipients - must be objects with email (and optional name)
    toEmails.value.forEach((email, index) => {
        formData.append(`to[${index}][email]`, email);
        formData.append(`to[${index}][name]`, ''); // Name not captured separately in current UI
    });
    ccEmails.value.forEach((email, index) => {
        formData.append(`cc[${index}][email]`, email);
        formData.append(`cc[${index}][name]`, '');
    });
    bccEmails.value.forEach((email, index) => {
        formData.append(`bcc[${index}][email]`, email);
        formData.append(`bcc[${index}][name]`, '');
    });
    
    // Subject
    formData.append('subject', subject.value);
    
    // Body content (use 'body' key as expected by backend)
    let bodyContent = editor.value?.getHTML() || '';
    
    // Append signature if selected
    if (selectedSignature.value?.content) {
        bodyContent += `<br><br>${selectedSignature.value.content}`;
    }
    formData.append('body', bodyContent);
    
    // Attachments
    attachments.value.forEach((file, index) => {
        formData.append(`attachments[${index}]`, file);
    });
    
    try {
        const success = await store.sendEmail(formData);
        if (success) {
            emit("send");
        } else {
             alert("Failed to send email.");
        }
    } catch (e) {
        console.error(e);
        alert("An error occurred.");
    }
}

function handleAiAssist() {
    // TODO: Future AI-powered email assistance feature
    console.log("AI Assist clicked - Coming Soon!");
    alert("AI Assist is coming soon! 🚀✨");
}

onBeforeUnmount(() => {
    editor.value?.destroy();
});
</script>

<style scoped>
.slide-fade-enter-active,
.slide-fade-leave-active {
    transition: all 0.2s ease;
}
.slide-fade-enter-from,
.slide-fade-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}

:deep(.ProseMirror p.is-editor-empty:first-child::before) {
    content: attr(data-placeholder);
    float: left;
    color: var(--text-muted);
    pointer-events: none;
    height: 0;
}
</style>
