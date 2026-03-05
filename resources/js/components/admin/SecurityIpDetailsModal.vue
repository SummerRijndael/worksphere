<script setup>
import { ref, watch } from "vue";
import {
    XMarkIcon,
    ShieldExclamationIcon,
    GlobeAltIcon,
    ClockIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    DocumentMagnifyingGlassIcon,
    DocumentDuplicateIcon,
} from "@heroicons/vue/24/outline";
import { toast } from "vue-sonner";
import Modal from "@/components/ui/Modal.vue";
import api from "@/lib/api";
import { useDate } from "@/composables/useDate";

const { formatRelativeTime } = useDate();

const props = defineProps({
    show: Boolean,
    ip: String,
});

const emit = defineEmits(["close"]);

const activity = ref([]);
const loading = ref(false);
const expandedRow = ref(null);

const fetchIpActivity = async () => {
    if (!props.ip) return;
    loading.value = true;
    try {
        const response = await api.get(
            `/api/admin/security/ip-activity/${props.ip}`,
        );
        activity.value = response.data.data || [];
    } catch (error) {
        console.error("Failed to fetch IP activity", error);
    } finally {
        loading.value = false;
    }
};

watch(
    () => props.ip,
    (newIp) => {
        if (newIp) {
            fetchIpActivity();
        } else {
            activity.value = [];
        }
    },
);

const toggleExpand = (id) => {
    expandedRow.value = expandedRow.value === id ? null : id;
};

const formatRequestData = (data) => {
    if (!data) return "No data recorded";
    try {
        const parsed = typeof data === "string" ? JSON.parse(data) : data;
        return JSON.stringify(parsed, null, 2);
    } catch (e) {
        return data;
    }
};

const copyToClipboard = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success("Payload copied to clipboard");
    } catch (err) {
        toast.error("Failed to copy text");
        console.error("Clipboard write error:", err);
    }
};
</script>

<template>
    <Modal :open="show" @close="emit('close')" size="4xl">
        <template #title>
            <div class="flex items-center gap-4">
                <div
                    class="p-2.5 bg-red-500/10 rounded-xl shrink-0 border border-red-500/20"
                >
                    <DocumentMagnifyingGlassIcon class="w-6 h-6 text-red-500" />
                </div>
                <div>
                    <h3 class="text-xl font-bold text-(--text-primary)">
                        Investigating IP: {{ ip }}
                    </h3>
                    <p
                        class="text-sm font-normal text-(--text-secondary) mt-0.5"
                    >
                        Detailed audit trail of all security incidents triggered
                        by this address.
                    </p>
                </div>
            </div>
        </template>

        <!-- Content -->
        <div
            v-if="loading"
            class="py-12 flex flex-col items-center justify-center gap-3"
        >
            <div
                class="w-8 h-8 border-4 border-(--interactive-primary) border-t-transparent rounded-full animate-spin"
            ></div>
            <p class="text-sm text-(--text-tertiary)">Gathering evidence...</p>
        </div>

        <div v-else-if="activity.length === 0" class="py-12 text-center">
            <ShieldExclamationIcon
                class="w-12 h-12 mx-auto mb-3 opacity-20 text-(--text-tertiary)"
            />
            <p class="text-(--text-secondary) font-medium">
                No detailed logs found for this IP.
            </p>
        </div>

        <div v-else class="space-y-4">
            <div
                class="overflow-x-auto rounded-xl border border-(--border-default)"
            >
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr
                            class="bg-(--surface-secondary) border-b border-(--border-default)"
                        >
                            <th class="w-10"></th>
                            <th
                                class="px-4 py-3 text-xs font-bold text-(--text-tertiary) uppercase"
                            >
                                Time
                            </th>
                            <th
                                class="px-4 py-3 text-xs font-bold text-(--text-tertiary) uppercase"
                            >
                                Trigger
                            </th>
                            <th
                                class="px-4 py-3 text-xs font-bold text-(--text-tertiary) uppercase"
                            >
                                URL
                            </th>
                            <th
                                class="px-4 py-3 text-xs font-bold text-(--text-tertiary) uppercase"
                            >
                                Level
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border-subtle)">
                        <template v-for="log in activity" :key="log.id">
                            <tr
                                class="hover:bg-(--surface-subtle) transition-colors cursor-pointer"
                                @click="toggleExpand(log.id)"
                            >
                                <td class="pl-4 py-3">
                                    <component
                                        :is="
                                            expandedRow === log.id
                                                ? ChevronUpIcon
                                                : ChevronDownIcon
                                        "
                                        class="w-4 h-4 text-(--text-tertiary)"
                                    />
                                </td>
                                <td class="px-4 py-3 text-sm whitespace-nowrap">
                                    {{ formatRelativeTime(log.created_at) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="px-2 py-0.5 rounded text-[10px] font-bold uppercase"
                                        :class="
                                            log.level === 'high'
                                                ? 'bg-red-500/10 text-red-500'
                                                : 'bg-orange-500/10 text-orange-500'
                                        "
                                    >
                                        {{ log.middleware || "Unknown" }}
                                    </span>
                                </td>
                                <td
                                    class="px-4 py-3 text-xs font-mono text-(--text-secondary) truncate max-w-[200px]"
                                    :title="log.url"
                                >
                                    {{ log.url }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="text-xs font-medium"
                                        :class="
                                            log.level === 'high'
                                                ? 'text-red-500'
                                                : 'text-orange-500'
                                        "
                                    >
                                        {{ log.level }}
                                    </span>
                                </td>
                            </tr>
                            <!-- Expanded Details -->
                            <tr v-if="expandedRow === log.id">
                                <td
                                    colspan="5"
                                    class="px-6 py-4 bg-(--surface-subtle)"
                                >
                                    <div class="space-y-3">
                                        <div
                                            class="flex items-center justify-between text-xs font-bold text-(--text-tertiary) border-b border-(--border-default) pb-1"
                                        >
                                            <div class="flex items-center gap-2">
                                                <GlobeAltIcon class="w-3.5 h-3.5" />
                                                FULL REQUEST PAYLOAD
                                            </div>
                                            <button
                                                @click.stop="copyToClipboard(formatRequestData(log.request))"
                                                class="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-(--text-secondary) bg-(--surface-primary) hover:bg-(--surface-tertiary) hover:text-(--interactive-primary) border border-(--border-subtle) rounded-md transition-colors shadow-xs"
                                                title="Copy to clipboard"
                                            >
                                                <DocumentDuplicateIcon class="w-3.5 h-3.5" />
                                                <span>Copy</span>
                                            </button>
                                        </div>
                                        <pre
                                            class="text-[10px] whitespace-pre-wrap break-all font-mono p-4 bg-black/90 text-green-400 rounded-lg overflow-x-hidden border border-white/5 shadow-inner leading-relaxed"
                                            >{{
                                                formatRequestData(log.request)
                                            }}
                                            </pre
                                        >
                                        <div
                                            class="flex items-center gap-4 text-xs text-(--text-secondary)"
                                        >
                                            <div
                                                v-if="log.referrer"
                                                class="flex items-center gap-1"
                                            >
                                                <span class="font-bold"
                                                    >Referrer:</span
                                                >
                                                {{ log.referrer }}
                                            </div>
                                            <div
                                                v-if="log.user_id"
                                                class="flex items-center gap-1"
                                            >
                                                <span class="font-bold"
                                                    >User ID:</span
                                                >
                                                {{ log.user_id }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <template #footer>
            <div class="flex justify-end pt-2">
                <button @click="emit('close')" class="btn btn-secondary px-8">
                    Close Trace
                </button>
            </div>
        </template>
    </Modal>
</template>
