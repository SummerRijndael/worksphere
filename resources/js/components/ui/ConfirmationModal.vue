<template>
    <Modal
        :open="open"
        @update:open="$emit('update:open', $event)"
        :title="title"
        size="sm"
    >
        <div class="space-y-4 pt-2 pb-1">
            <div class="flex items-start gap-4">
                <div
                    :class="[
                        'shrink-0 w-10 h-10 rounded-xl flex items-center justify-center',
                        iconBgClass,
                    ]"
                >
                    <slot name="icon">
                        <TriangleAlert
                            v-if="!icon"
                            class="w-5 h-5"
                            :class="iconColorClass"
                        />
                        <component
                            v-if="icon"
                            :is="icon"
                            class="w-5 h-5"
                            :class="iconColorClass"
                        />
                    </slot>
                </div>

                <div class="space-y-1">
                    <p
                        class="text-sm text-(--text-primary) font-semibold leading-relaxed"
                    >
                        {{ message }}
                    </p>
                    <p
                        v-if="description"
                        class="text-xs text-(--text-secondary) leading-relaxed"
                    >
                        {{ description }}
                    </p>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-3 w-full">
                <Button variant="ghost" @click="$emit('update:open', false)">
                    {{ cancelLabel }}
                </Button>
                <Button
                    :variant="confirmVariant"
                    :loading="loading"
                    @click="$emit('confirm')"
                >
                    {{ confirmLabel }}
                </Button>
            </div>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { TriangleAlert } from "lucide-vue-next";
import Modal from "./Modal.vue";
import Button from "./Button.vue";

interface Props {
    open: boolean;
    title?: string;
    message: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    confirmVariant?: "primary" | "danger" | "secondary" | "ghost";
    icon?: any;
    loading?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    title: "Confirm Action",
    message: "Are you sure you want to proceed?",
    confirmLabel: "Confirm",
    cancelLabel: "Cancel",
    confirmVariant: "primary",
    icon: null,
    loading: false,
});

defineEmits(["update:open", "confirm"]);

const iconBgClass = computed(() => {
    switch (props.confirmVariant) {
        case "danger":
            return "bg-red-500/10";
        case "primary":
            return "bg-blue-500/10";
        default:
            return "bg-gray-500/10";
    }
});

const iconColorClass = computed(() => {
    switch (props.confirmVariant) {
        case "danger":
            return "text-red-600 dark:text-red-400";
        case "primary":
            return "text-blue-600 dark:text-blue-400";
        default:
            return "text-gray-600 dark:text-gray-400";
    }
});
</script>
