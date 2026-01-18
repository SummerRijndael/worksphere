<template>
    <div class="flex flex-col h-screen overflow-hidden">
        <!-- Restored Viewport Less Header: h-[calc(100dvh-4rem)] -->
        <div
            class="w-full flex flex-col md:flex-row bg-[var(--surface-primary)] flex-1 overflow-hidden"
        >
        <!-- Sidebar Backdrop -->
        <div
            v-if="isMobileSidebarOpen"
            class="fixed inset-0 bg-black/50 z-20 md:hidden backdrop-blur-sm"
            @click="isMobileSidebarOpen = false"
        ></div>

        <!-- Sidebar -->
        <EmailSidebar
            class="md:flex transition-transform duration-300"
            :class="[
                isMobileSidebarOpen
                    ? 'fixed inset-y-0 left-0 z-30 flex shadow-xl'
                    : 'hidden translate-x-[-100%] md:translate-x-0',
            ]"
            @compose="handleCompose"
        />

        <!-- List & Preview Container -->
        <div class="flex-1 flex min-w-0">
            <!-- Email List -->
            <EmailList
                class="w-full md:w-80 lg:w-96 border-r border-[var(--border-default)] flex-shrink-0"
                :class="{ 'hidden md:flex': selectedEmailId }"
                @select="handleSelectEmail"
                @toggle-sidebar="isMobileSidebarOpen = !isMobileSidebarOpen"
            />

            <!-- Preview Pane (with inline tabs) -->
            <div
                class="flex-1 bg-[var(--surface-primary)] min-w-0 min-h-0 flex flex-col"
                :class="{
                    'hidden md:flex': !selectedEmailId,
                    flex: selectedEmailId,
                }"
            >
                <div
                    v-if="selectedEmailId"
                    class="md:hidden fixed top-20 left-4 z-10"
                >
                    <button
                        @click="selectedEmailId = null"
                        class="bg-[var(--surface-elevated)] p-2 rounded-full shadow-lg border border-[var(--border-default)]"
                    >
                        &larr; Back
                    </button>
                </div>

                <EmailPreviewPane ref="previewPaneRef" :email="selectedEmail" />
            </div>
        </div>
    </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed } from "vue";
import EmailSidebar from "./components/EmailSidebar.vue";
import EmailList from "./components/EmailList.vue";
import EmailPreviewPane from "./components/EmailPreviewPane.vue";
import { useEmailStore, type Email } from "@/stores/emailStore";
import { storeToRefs } from "pinia";

const store = useEmailStore();
const { emails, selectedEmailId } = storeToRefs(store);

const isMobileSidebarOpen = ref(false);
const previewPaneRef = ref<InstanceType<typeof EmailPreviewPane> | null>(null);

const selectedEmail = computed(() => {
    return emails.value.find((e) => e.id === selectedEmailId.value) || null;
});

function handleSelectEmail(email: Email) {
    store.selectedEmailId = email.id;
    email.isRead = true;
}

function handleCompose() {
    // Trigger compose tab in preview pane
    if (previewPaneRef.value) {
        previewPaneRef.value.openTab("compose");
    }
}
</script>
