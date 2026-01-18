<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { 
    XIcon, 
    PenToolIcon, 
    UsersIcon, 
    HardDriveIcon,
    PlusIcon,
    Trash2Icon,
    MailIcon,
    FileTextIcon,
    BoldIcon,
    ItalicIcon,
    ListIcon,
    AlertTriangleIcon,
} from 'lucide-vue-next';
import { Button, Card } from '@/components/ui';
import { useEmailSignatures } from './composables/useEmailSignatures';
import { useEmailTemplates } from './composables/useEmailTemplates';
import { useEditor, EditorContent } from "@tiptap/vue-3";
import StarterKit from "@tiptap/starter-kit";
import Placeholder from "@tiptap/extension-placeholder";

const tabs = [
    { id: 'signatures', label: 'Signatures', icon: PenToolIcon },
    { id: 'templates', label: 'Templates', icon: FileTextIcon },
    { id: 'accounts', label: 'Accounts', icon: UsersIcon },
    { id: 'storage', label: 'Storage', icon: HardDriveIcon },
];

const activeTab = ref('signatures');

// --- Signatures Logic ---
const { signatures, addSignature, updateSignature, deleteSignature } = useEmailSignatures();
const selectedSignatureId = ref<string | null>(null);

const activeSignature = computed(() => 
    signatures.value.find(s => s.id === selectedSignatureId.value)
);

const signatureName = ref('');
const signatureEditor = useEditor({
    content: '',
    extensions: [
        StarterKit,
        Placeholder.configure({ placeholder: 'Create your signature...' }),
    ],
    editorProps: {
        attributes: {
            class: 'prose dark:prose-invert max-w-none focus:outline-none min-h-[150px] text-[var(--text-primary)] px-4 py-3',
        },
    },
});

// Watch for selection change to update form
watch(selectedSignatureId, (newId) => {
    if (newId && activeSignature.value) {
        signatureName.value = activeSignature.value.name;
        signatureEditor.value?.commands.setContent(activeSignature.value.content);
    } else {
        signatureName.value = '';
        signatureEditor.value?.commands.setContent('');
    }
});

function handleNewSignature() {
    selectedSignatureId.value = null; // Clear selection first to ensure watchers trigger if logic specific
    // Actually, let's create a temporary "new" state or just create it immediately?
    // Let's create a draft behavior:
    const newSig = addSignature({ name: 'My New Signature', content: '' });
    selectedSignatureId.value = newSig.id;
}

function handleSaveSignature() {
    if (!selectedSignatureId.value) return;
    updateSignature(selectedSignatureId.value, {
        name: signatureName.value,
        content: signatureEditor.value?.getHTML() || ''
    });
}

function handleDeleteSignature(id: string) {
    if (confirm('Delete this signature?')) {
        deleteSignature(id);
        if (selectedSignatureId.value === id) {
            selectedSignatureId.value = signatures.value.length > 0 ? signatures.value[0].id : null;
        }
    }
}

// Select first signature on mount if available
if (signatures.value.length > 0) {
    selectedSignatureId.value = signatures.value[0].id;
}

// --- Templates Logic ---
const { templates, addTemplate, updateTemplate, deleteTemplate } = useEmailTemplates();
const selectedTemplateId = ref<string | null>(null);

const activeTemplate = computed(() => 
    templates.value.find(t => t.id === selectedTemplateId.value)
);

const templateName = ref('');
const templateSubject = ref('');
const templateEditor = useEditor({
    content: '',
    extensions: [
        StarterKit,
        Placeholder.configure({ placeholder: 'Write your template content...' }),
    ],
    editorProps: {
        attributes: {
            class: 'prose dark:prose-invert max-w-none focus:outline-none min-h-[200px] text-[var(--text-primary)] px-4 py-3',
        },
    },
});

watch(selectedTemplateId, (newId) => {
    if (newId && activeTemplate.value) {
        templateName.value = activeTemplate.value.name;
        templateSubject.value = activeTemplate.value.subject;
        templateEditor.value?.commands.setContent(activeTemplate.value.body);
    } else {
        templateName.value = '';
        templateSubject.value = '';
        templateEditor.value?.commands.setContent('');
    }
});

function handleNewTemplate() {
    const newTpl = addTemplate({ name: 'New Template', subject: '', body: '' });
    selectedTemplateId.value = newTpl.id;
}

function handleSaveTemplate() {
    if (!selectedTemplateId.value) return;
    updateTemplate(selectedTemplateId.value, {
        name: templateName.value,
        subject: templateSubject.value,
        body: templateEditor.value?.getHTML() || ''
    });
}

function handleDeleteTemplate(id: string) {
     if (confirm('Delete this template?')) {
        deleteTemplate(id);
        if (selectedTemplateId.value === id) {
            selectedTemplateId.value = templates.value.length > 0 ? templates.value[0].id : null;
        }
    }
}

if (templates.value.length > 0) {
    selectedTemplateId.value = templates.value[0].id;
}

// ... existing imports
import EmailAccountsSection from "@/components/settings/EmailAccountsSection.vue";

// --- Accounts Logic ---
// Content handled by EmailAccountsSection component

// --- Storage Logic ---
const loadingStorage = ref(false);
const storageAccounts = ref<any[]>([]);

async function fetchStorageData() {
    loadingStorage.value = true;
    try {
        const response = await emailAccountService.list(); 
        storageAccounts.value = response;
    } catch (e) {
        console.error("Failed to fetch storage data", e);
    } finally {
        loadingStorage.value = false;
    }
}

watch(activeTab, (newTab) => {
    if (newTab === 'storage') {
        fetchStorageData();
    }
});

// Format helpers
function formatDate(date: string) {
    if (!date) return '';
    return new Date(date).toLocaleDateString() + ' ' + new Date(date).toLocaleTimeString();
}

function formatBytes(bytes: number | null) {
    if (bytes === null || bytes === undefined) return '0 B';
    if (bytes === 0) return '0 B';
    
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function getUsagePercent(account: any) {
    if (!account.storage_limit || account.storage_limit === 0) return 0;
    return (account.storage_used / account.storage_limit) * 100;
}

function getUsageColorClass(percent: number) {
    if (percent >= 90) return 'text-red-500';
    if (percent >= 75) return 'text-amber-500';
    return 'text-[var(--brand-primary)]';
}

function getProgressBarColorClass(percent: number) {
    if (percent >= 90) return 'bg-red-500';
    if (percent >= 75) return 'bg-amber-500';
    return 'bg-[var(--brand-primary)]';
}

import { emailAccountService } from '@/services/email-account.service';

// Cleanup
onBeforeUnmount(() => {
    signatureEditor.value?.destroy();
    templateEditor.value?.destroy();
});
</script>

<template>
    <div class="flex flex-col h-full bg-[var(--surface-primary)] overflow-hidden">
        
        <!-- Header -->
        <div class="px-8 py-6 border-b border-[var(--border-default)] flex items-center justify-between shrink-0 bg-[var(--surface-primary)]">
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)] tracking-tight">Email Settings</h1>
                <p class="text-sm text-[var(--text-secondary)] mt-1">Manage your signatures, templates, and preferences.</p>
            </div>
            <button
                @click="$router.push('/email')"
                class="p-2 -mr-2 text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] rounded-full transition-colors"
            >
                <XIcon class="w-5 h-5" />
            </button>
        </div>

        <div class="flex flex-1 overflow-hidden">
            <!-- Sidebar Navigation -->
            <div class="w-64 p-4 flex flex-col gap-1 shrink-0 bg-[var(--surface-primary)] border-r border-[var(--border-default)]">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="flex items-center gap-3 px-4 py-3 text-sm font-medium rounded-xl transition-all text-left group"
                    :class="[
                        activeTab === tab.id
                            ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm ring-1 ring-[var(--border-default)]'
                            : 'text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] hover:text-[var(--text-primary)]'
                    ]"
                >
                    <div 
                        class="p-1 rounded-lg transition-colors"
                        :class="activeTab === tab.id ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]' : 'bg-transparent text-[var(--text-muted)] group-hover:text-[var(--text-secondary)]'"
                    >
                        <component :is="tab.icon" class="w-4 h-4" />
                    </div>
                    {{ tab.label }}
                </button>
            </div>

            <!-- Content Area -->
            <div class="flex-1 overflow-hidden bg-[var(--surface-secondary)]/30 relative">
                
                <!-- Signatures & Templates (Unified Master-Detail Layout) -->
                <div v-if="activeTab === 'signatures' || activeTab === 'templates'" class="flex h-full">
                    
                    <!-- Left Pane: List -->
                    <div class="w-80 border-r border-[var(--border-default)] bg-[var(--surface-primary)] flex flex-col">
                        <div class="p-4 border-b border-[var(--border-default)] flex items-center justify-between shrink-0 bg-[var(--surface-primary)]">
                            <h2 class="font-semibold text-[var(--text-primary)]">
                                {{ activeTab === 'signatures' ? 'All Signatures' : 'All Templates' }}
                            </h2>
                            <button 
                                @click="activeTab === 'signatures' ? handleNewSignature() : handleNewTemplate()"
                                class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium text-white bg-[var(--interactive-primary)] hover:bg-[var(--interactive-primary-hover)] transition-all shadow-sm active:scale-95"
                            >
                                <PlusIcon class="w-3.5 h-3.5" />
                                New
                            </button>
                        </div>
                        
                        <div class="flex-1 overflow-y-auto p-3 space-y-2 scrollbar-thin">
                            <template v-if="activeTab === 'signatures'">
                                <div 
                                    v-for="sig in signatures" 
                                    :key="sig.id"
                                    @click="selectedSignatureId = sig.id"
                                    class="p-3 rounded-xl border transition-all cursor-pointer group relative"
                                    :class="selectedSignatureId === sig.id 
                                        ? 'bg-[var(--surface-elevated)] border-[var(--interactive-primary)]/40 ring-1 ring-[var(--interactive-primary)]/20 shadow-sm z-10' 
                                        : 'bg-[var(--surface-secondary)]/50 border-transparent hover:bg-[var(--surface-tertiary)] hover:border-[var(--border-muted)]'"
                                >
                                    <div class="flex justify-between items-start">
                                        <span class="font-medium text-sm text-[var(--text-primary)]">{{ sig.name }}</span>
                                        <button 
                                            @click.stop="handleDeleteSignature(sig.id)"
                                            class="opacity-0 group-hover:opacity-100 p-1 text-[var(--text-muted)] hover:text-[var(--color-error)] transition-all"
                                        >
                                            <Trash2Icon class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                    <div class="mt-1.5 text-xs text-[var(--text-secondary)] line-clamp-2 opacity-80 font-normal leading-relaxed" v-html="sig.content.replace(/<[^>]*>/g, ' ').substring(0, 100) || 'Empty signature'"></div>
                                </div>
                            </template>
                            
                            <template v-else>
                                <div 
                                    v-for="tpl in templates" 
                                    :key="tpl.id"
                                    @click="selectedTemplateId = tpl.id"
                                    class="p-3 rounded-xl border transition-all cursor-pointer group relative"
                                    :class="selectedTemplateId === tpl.id 
                                        ? 'bg-[var(--surface-elevated)] border-[var(--interactive-primary)]/40 ring-1 ring-[var(--interactive-primary)]/20 shadow-sm z-10' 
                                        : 'bg-[var(--surface-secondary)]/50 border-transparent hover:bg-[var(--surface-tertiary)] hover:border-[var(--border-muted)]'"
                                >
                                    <div class="flex justify-between items-start">
                                        <span class="font-medium text-sm text-[var(--text-primary)]">{{ tpl.name }}</span>
                                        <button 
                                            @click.stop="handleDeleteTemplate(tpl.id)"
                                            class="opacity-0 group-hover:opacity-100 p-1 text-[var(--text-muted)] hover:text-[var(--color-error)] transition-all"
                                        >
                                            <Trash2Icon class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                    <div class="mt-1 text-xs text-[var(--text-primary)] truncate font-medium">{{ tpl.subject || '(No subject)' }}</div>
                                    <div class="mt-1 text-xs text-[var(--text-muted)] truncate">{{ tpl.body ? 'Has content' : 'Empty body' }}</div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Right Pane: Editor -->
                    <div class="flex-1 flex flex-col bg-[var(--surface-primary)] h-full overflow-hidden relative">
                        <!-- Background Pattern/Logo for nice feel -->
                        <div class="absolute inset-0 pointer-events-none opacity-[0.02] flex items-center justify-center overflow-hidden">
                            <MailIcon class="w-96 h-96" />
                        </div>

                        <template v-if="(activeTab === 'signatures' && selectedSignatureId) || (activeTab === 'templates' && selectedTemplateId)">
                            <!-- Editor Header -->
                            <div class="px-8 py-5 border-b border-[var(--border-default)] shrink-0 z-10 bg-[var(--surface-primary)]/90 backdrop-blur-md space-y-4">
                                <div class="flex items-center justify-between gap-4">
                                    <input 
                                        v-if="activeTab === 'signatures'"
                                        v-model="signatureName"
                                        @blur="handleSaveSignature"
                                        type="text"
                                        class="bg-transparent text-2xl font-bold text-[var(--text-primary)] focus:outline-none placeholder-[var(--text-muted)] w-full tracking-tight"
                                        placeholder="Signature Name"
                                    />
                                    <input 
                                        v-else
                                        v-model="templateName"
                                        @blur="handleSaveTemplate"
                                        type="text"
                                        class="bg-transparent text-2xl font-bold text-[var(--text-primary)] focus:outline-none placeholder-[var(--text-muted)] w-full tracking-tight"
                                        placeholder="Template Name"
                                    />
                                    
                                    <div class="flex items-center gap-2 shrink-0">
                                         <div class="flex items-center gap-1.5 px-2 py-1 rounded bg-green-500/10 text-green-500 text-xs font-medium border border-green-500/20">
                                            <div class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></div>
                                            Auto-saved
                                        </div>
                                    </div>
                                </div>
                                
                                <div v-if="activeTab === 'templates'" class="relative group">
                                     <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <span class="text-[var(--text-muted)] text-sm font-medium">Subject:</span>
                                    </div>
                                    <input 
                                        v-model="templateSubject"
                                        @blur="handleSaveTemplate"
                                        type="text"
                                        class="w-full bg-[var(--surface-secondary)] border border-[var(--border-default)] rounded-lg py-2.5 pl-20 pr-4 text-sm text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 focus:border-[var(--interactive-primary)] transition-all placeholder-[var(--text-muted)]"
                                        placeholder="Enter email subject line..."
                                    />
                                </div>
                            </div>

                            <!-- Toolbar -->
                            <div class="px-6 py-2 border-b border-[var(--border-default)] bg-[var(--surface-secondary)]/50 flex items-center gap-1 shrink-0 z-10">
                                <template v-if="activeTab === 'signatures'">
                                    <button @click="signatureEditor?.chain().focus().toggleBold().run()" class="p-1.5 rounded-md hover:bg-[var(--surface-tertiary)] transition-colors" :class="{ 'bg-[var(--surface-active)] text-[var(--interactive-primary)] shadow-sm': signatureEditor?.isActive('bold'), 'text-[var(--text-secondary)]': !signatureEditor?.isActive('bold') }"><BoldIcon class="w-4 h-4" /></button>
                                    <button @click="signatureEditor?.chain().focus().toggleItalic().run()" class="p-1.5 rounded-md hover:bg-[var(--surface-tertiary)] transition-colors" :class="{ 'bg-[var(--surface-active)] text-[var(--interactive-primary)] shadow-sm': signatureEditor?.isActive('italic'), 'text-[var(--text-secondary)]': !signatureEditor?.isActive('italic') }"><ItalicIcon class="w-4 h-4" /></button>
                                    <div class="w-px h-4 bg-[var(--border-default)] mx-1"></div>
                                    <button @click="signatureEditor?.chain().focus().toggleBulletList().run()" class="p-1.5 rounded-md hover:bg-[var(--surface-tertiary)] transition-colors" :class="{ 'bg-[var(--surface-active)] text-[var(--interactive-primary)] shadow-sm': signatureEditor?.isActive('bulletList'), 'text-[var(--text-secondary)]': !signatureEditor?.isActive('bulletList') }"><ListIcon class="w-4 h-4" /></button>
                                </template>
                                 <template v-else>
                                    <button @click="templateEditor?.chain().focus().toggleBold().run()" class="p-1.5 rounded-md hover:bg-[var(--surface-tertiary)] transition-colors" :class="{ 'bg-[var(--surface-active)] text-[var(--interactive-primary)] shadow-sm': templateEditor?.isActive('bold'), 'text-[var(--text-secondary)]': !templateEditor?.isActive('bold') }"><BoldIcon class="w-4 h-4" /></button>
                                    <button @click="templateEditor?.chain().focus().toggleItalic().run()" class="p-1.5 rounded-md hover:bg-[var(--surface-tertiary)] transition-colors" :class="{ 'bg-[var(--surface-active)] text-[var(--interactive-primary)] shadow-sm': templateEditor?.isActive('italic'), 'text-[var(--text-secondary)]': !templateEditor?.isActive('italic') }"><ItalicIcon class="w-4 h-4" /></button>
                                    <div class="w-px h-4 bg-[var(--border-default)] mx-1"></div>
                                    <button @click="templateEditor?.chain().focus().toggleBulletList().run()" class="p-1.5 rounded-md hover:bg-[var(--surface-tertiary)] transition-colors" :class="{ 'bg-[var(--surface-active)] text-[var(--interactive-primary)] shadow-sm': templateEditor?.isActive('bulletList'), 'text-[var(--text-secondary)]': !templateEditor?.isActive('bulletList') }"><ListIcon class="w-4 h-4" /></button>
                                </template>
                            </div>

                            <!-- Actual Editor -->
                            <div class="flex-1 overflow-y-auto bg-[var(--surface-primary)] z-0 px-8 py-6">
                                 <editor-content 
                                    :editor="activeTab === 'signatures' ? signatureEditor : templateEditor" 
                                    class="h-full max-w-3xl mx-auto"
                                    @update="activeTab === 'signatures' ? handleSaveSignature() : handleSaveTemplate()"
                                />
                            </div>
                        </template>

                        <div v-else class="flex-1 flex flex-col items-center justify-center text-[var(--text-muted)] z-10">
                            <div class="w-20 h-20 rounded-3xl bg-[var(--surface-secondary)] flex items-center justify-center mb-6 border border-[var(--border-default)] shadow-sm transform -rotate-3">
                                <component :is="activeTab === 'signatures' ? PenToolIcon : FileTextIcon" class="w-10 h-10 opacity-40 text-[var(--text-primary)]" />
                            </div>
                            <p class="text-xl font-semibold text-[var(--text-primary)]">No {{ activeTab === 'signatures' ? 'signature' : 'template' }} selected</p>
                            <p class="text-sm mt-2 max-w-xs text-center text-[var(--text-secondary)]">Select an item from the list on the left to edit it, or create a new one to get started.</p>
                            <Button class="mt-8" @click="activeTab === 'signatures' ? handleNewSignature() : handleNewTemplate()">
                                <PlusIcon class="w-4 h-4 mr-2" />
                                {{ activeTab === 'signatures' ? 'Create Signature' : 'Create Template' }}
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Accounts Tab (Cards) -->
                <div v-if="activeTab === 'accounts'" class="p-8 max-w-5xl mx-auto w-full h-full overflow-y-auto">
                    <EmailAccountsSection mode="personal" />
                </div>

                <!-- Storage Tab (Centered Card) -->
                <!-- Storage Tab (List of Cards) -->
                <div v-if="activeTab === 'storage'" class="flex flex-col items-center h-full p-8 overflow-y-auto">
                    <div class="max-w-3xl w-full">
                        <div class="text-center mb-10">
                            <h2 class="text-3xl font-bold text-[var(--text-primary)] tracking-tight">Storage Usage</h2>
                            <p class="text-base text-[var(--text-secondary)] mt-2">View storage usage for each connected account.</p>
                        </div>

                        <div v-if="loadingStorage" class="flex justify-center p-12">
                            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand-primary)]"></div>
                        </div>

                        <div v-else class="space-y-8">
                            <Card v-for="account in storageAccounts" :key="account.id" padding="xl" class="shadow-xl border-[var(--border-active)] w-full relative overflow-hidden transition-all hover:shadow-2xl">
                                 <!-- Header -->
                                <div class="flex items-center gap-3 mb-6">
                                    <div class="p-2 rounded-lg bg-[var(--surface-tertiary)] text-[var(--text-primary)]">
                                        <MailIcon class="w-6 h-6" />
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-[var(--text-primary)]">{{ account.email }}</h3>
                                        <p class="text-xs text-[var(--text-muted)]">{{ account.provider_name }}</p>
                                    </div>
                                    <div class="ml-auto text-xs text-[var(--text-muted)] italic" v-if="account.storage_updated_at">
                                        Updated {{ formatDate(account.storage_updated_at) }}
                                    </div>
                                </div>

                                <div class="flex items-end justify-between mb-8 relative">
                                    <div v-if="account.storage_limit">
                                        <div class="text-4xl font-bold text-[var(--text-primary)] tracking-tighter">{{ formatBytes(account.storage_used) }}</div>
                                        <div class="text-sm font-medium text-[var(--text-muted)] mt-2 uppercase tracking-wider">Used of {{ formatBytes(account.storage_limit) }}</div>
                                    </div>
                                    <div v-else>
                                        <div class="text-4xl font-bold text-[var(--text-primary)] tracking-tighter">{{ formatBytes(account.storage_used) }}</div>
                                        <div class="text-sm font-medium text-[var(--text-muted)] mt-2 uppercase tracking-wider">Storage Used</div>
                                    </div>
                                    
                                    <div v-if="account.storage_limit" class="text-2xl font-bold" :class="getUsageColorClass(getUsagePercent(account))">
                                        {{ getUsagePercent(account).toFixed(1) }}%
                                    </div>
                                    <div v-else class="text-2xl font-bold text-[var(--text-secondary)]">
                                        Unknown Limit
                                    </div>
                                </div>

                                <!-- Progress Bar -->
                                <div v-if="account.storage_limit" class="h-4 w-full bg-[var(--surface-tertiary)] rounded-full overflow-hidden flex mb-6 ring-1 ring-[var(--border-default)] relative shadow-inner">
                                    <div 
                                        class="h-full relative group transition-all duration-1000 ease-out" 
                                        :class="getProgressBarColorClass(getUsagePercent(account))"
                                        :style="{ width: `${getUsagePercent(account)}%` }"
                                    >
                                        <div class="absolute inset-0 bg-white/20 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                                    </div>
                                </div>
                                <div v-else class="h-4 w-full bg-[var(--surface-tertiary)] rounded-full overflow-hidden mb-6 relative">
                                     <div class="absolute inset-0 bg-gradient-to-r from-transparent via-[var(--surface-tertiary)]/50 to-transparent w-1/2 animate-[shimmer_2s_infinite]"></div>
                                </div>

                                <div class="flex justify-between text-sm text-[var(--text-secondary)]">
                                    <span>Emails & Attachments</span>
                                    <span class="font-mono font-bold">{{ formatBytes(account.storage_used) }}</span>
                                </div>
                            </Card>
                        </div>
                   
                        <!-- Empty State -->
                        <div v-if="!loadingStorage && storageAccounts.length === 0" class="text-center p-12 text-[var(--text-muted)]">
                            <HardDriveIcon class="w-12 h-12 mx-auto mb-4 opacity-50" />
                            <p>No verified email accounts connected.</p>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>
</template>
