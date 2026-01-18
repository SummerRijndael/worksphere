<template>
    <div
        class="flex-1 flex flex-col h-full bg-[var(--surface-primary)] overflow-hidden min-h-0"
    >
        <!-- Email Content Area -->
        <div class="flex-1 overflow-hidden min-h-0 relative flex flex-col">
            <EmailPreviewContent
                v-if="email && activeTab === 'read'"
                :email="email"
                @reply="openTab('reply')"
                @reply-all="openTab('reply-all')"
                @forward="openTab('forward')"
                @forward-as-attachment="openTab('forward-as-attachment')"
            />

            <!-- Inline Composer -->
            <EmailInlineComposer
                v-else-if="activeTab !== 'read'"
                :mode="activeTab"
                :reply-to="email"
                @close="closeActiveTab"
                @send="handleSend"
            />

            <!-- Empty State -->
            <div
                v-if="!email && activeTab === 'read'"
                class="flex-1 flex flex-col items-center justify-center text-[var(--text-muted)] h-full"
            >
                <MailIcon class="w-16 h-16 mb-4 text-[var(--text-tertiary)]" />
                <p>Select an email to read</p>
            </div>
        </div>

        <!-- Tab Bar -->
        <div
            v-if="tabs.length > 0"
            class="border-t border-[var(--border-default)] bg-[var(--surface-secondary)]"
        >
            <div class="flex items-center gap-1 px-2 py-1.5 overflow-x-auto">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="group flex items-center gap-2 px-3 py-1.5 text-sm rounded-md transition-all duration-150"
                    :class="[
                        activeTab === tab.id
                            ? 'bg-[var(--interactive-primary)] text-white shadow-sm'
                            : 'text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] hover:text-[var(--text-primary)]',
                    ]"
                >
                    <component :is="tab.icon" class="w-3.5 h-3.5" />
                    <span class="truncate max-w-[120px]">{{ tab.label }}</span>
                    <button
                        v-if="tab.closable"
                        @click.stop="closeTab(tab.id)"
                        class="ml-1 p-0.5 rounded hover:bg-white/20 transition-colors"
                        :class="
                            activeTab === tab.id
                                ? 'text-white/70 hover:text-white'
                                : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'
                        "
                    >
                        <XIcon class="w-3 h-3" />
                    </button>
                </button>

                <!-- New Compose Button -->
                <button
                    @click="openTab('compose')"
                    class="flex items-center gap-1.5 px-3 py-1.5 text-sm text-[var(--interactive-primary)] hover:bg-[var(--surface-tertiary)] rounded-md transition-colors"
                    title="New Email"
                >
                    <PlusIcon class="w-3.5 h-3.5" />
                    <span class="hidden sm:inline">New</span>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, watch, markRaw, onMounted, onUnmounted } from "vue";
import {
    MailIcon,
    XIcon,
    PlusIcon,
    ReplyIcon,
    ReplyAllIcon,
    ForwardIcon,
    PaperclipIcon,
    PencilIcon,
    InboxIcon,
} from "lucide-vue-next";
import EmailPreviewContent from "./EmailPreviewContent.vue";
import EmailInlineComposer from "./EmailInlineComposer.vue";
import type { Email } from "@/types/models/email";

interface Tab {
    id: string;
    label: string;
    icon: any;
    closable: boolean;
}

const props = defineProps<{
    email: Email | null;
}>();

const emit = defineEmits<{
    compose: [];
}>();

const activeTab = ref<string>("read");
const tabs = ref<Tab[]>([
    { id: "read", label: "Read", icon: markRaw(InboxIcon), closable: false },
]);

// Watch for email changes to reset to read view
watch(
    () => props.email,
    (newEmail) => {
        if (newEmail) {
            activeTab.value = "read";
        }
    }
);

// Listen for postMessage from popup windows
onMounted(() => {
    window.addEventListener('message', handlePopupMessage);
});

onUnmounted(() => {
    window.removeEventListener('message', handlePopupMessage);
});

function handlePopupMessage(event: MessageEvent) {
    if (event.data?.type && ['reply', 'reply-all', 'forward', 'forward-as-attachment'].includes(event.data.type)) {
        openTab(event.data.type as any);
    }
}

function openTab(type: "reply" | "reply-all" | "forward" | "compose" | "forward-as-attachment") {
    const id = `${type}-${Date.now()}`;
    const labels = {
        reply: `Re: ${props.email?.subject || "Reply"}`,
        "reply-all": `Re All: ${props.email?.subject || "Reply All"}`,
        forward: `Fwd: ${props.email?.subject || "Forward"}`,
        "forward-as-attachment": `Fwd(Att): ${props.email?.subject || "Forward"}`,
        compose: "New Email",
    };
    const icons = {
        reply: markRaw(ReplyIcon),
        "reply-all": markRaw(ReplyAllIcon),
        forward: markRaw(ForwardIcon),
        "forward-as-attachment": markRaw(PaperclipIcon),
        compose: markRaw(PencilIcon),
    };

    tabs.value.push({
        id,
        label: labels[type],
        icon: icons[type],
        closable: true,
    });

    activeTab.value = id;
}

function closeTab(id: string) {
    const index = tabs.value.findIndex((t) => t.id === id);
    if (index > -1) {
        tabs.value.splice(index, 1);
        // If closing active tab, switch to read
        if (activeTab.value === id) {
            activeTab.value = "read";
        }
    }
}

function closeActiveTab() {
    if (activeTab.value !== "read") {
        closeTab(activeTab.value);
    }
}

function handleSend() {
    // TODO: Implement send logic
    closeActiveTab();
}

// Expose for parent component
defineExpose({
    openTab,
});
</script>
