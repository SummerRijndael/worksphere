<script setup>
import { computed, ref, watch } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({
    src: String,
    thumbUrl: String,
    alt: String,
    fallback: String,
    color: { // Added this to fix the missing 'color' variable error
        type: String,
        default: "gray"
    },
    size: {
        type: String,
        default: "md",
        validator: (v) => ["xs", "sm", "md", "lg", "xl", "2xl", "3xl", "4xl", "5xl"].includes(v),
    },
    status: {
        type: String,
        validator: (v) => ["online", "offline", "away", "busy"].includes(v),
    },
    ring: Boolean,
    variant: {
        type: String,
        default: "dot",
        validator: (v) => ["dot", "ring", "square"].includes(v),
    }
});

const hasError = ref(false);

watch(() => props.src, () => {
    hasError.value = false;
});

const initials = computed(() => {
    if (props.fallback) return props.fallback.slice(0, 2).toUpperCase();
    if (props.alt) {
        return props.alt
            .split(" ")
            .map((n) => n[0])
            .join("")
            .toUpperCase()
            .slice(0, 2);
    }
    return "U";
});

const sizeClasses = computed(() => ({
    "h-6 w-6 text-xs": props.size === "xs",
    "h-8 w-8 text-sm": props.size === "sm",
    "h-10 w-10 text-sm": props.size === "md",
    "h-12 w-12 text-base": props.size === "lg",
    "h-16 w-16 text-lg": props.size === "xl",
    "h-20 w-20 text-xl": props.size === "2xl",
    "h-24 w-24 text-2xl": props.size === "3xl",
    "h-32 w-32 text-3xl": props.size === "4xl",
    "h-40 w-40 text-4xl": props.size === "5xl",
}));

const containerClasses = computed(() => 
    cn(
        "relative inline-flex shrink-0",
        props.variant === "square" ? "rounded-lg" : "rounded-full",
        sizeClasses.value,
        props.variant === "ring" && props.status ? "border-2 transition-colors duration-300 flex items-center justify-center overflow-hidden" : "",
        props.variant === "ring" && props.status === "online" && "border-[var(--status-online)]",
        props.variant === "ring" && props.status === "offline" && "border-[var(--status-offline)]",
        props.variant === "ring" && props.status === "away" && "border-[var(--status-away)]",
        props.variant === "ring" && props.status === "busy" && "border-[var(--status-busy)]"
    )
);

const innerClasses = computed(() =>
    cn(
        "flex h-full w-full items-center justify-center bg-[var(--surface-tertiary)] overflow-hidden",
        props.variant === "square" ? "rounded-lg" : "rounded-full",
        "text-[var(--text-secondary)] font-medium",
        props.ring &&
            "ring-2 ring-[var(--surface-elevated)] ring-offset-2 ring-offset-[var(--surface-primary)]"
    )
);

const statusClasses = computed(() =>
    cn(
        "absolute bottom-0 right-0 rounded-full border-2 border-[var(--surface-elevated)]",
        {
            "h-2 w-2": props.size === "xs" || props.size === "sm",
            "h-2.5 w-2.5": props.size === "md",
            "h-3 w-3": props.size === "lg",
            "h-4 w-4": props.size === "xl" || props.size === "2xl",
            "h-5 w-5": props.size === "3xl",
            "h-6 w-6": props.size === "4xl" || props.size === "5xl",
        },
        {
            "bg-[var(--status-online)]": props.status === "online",
            "bg-[var(--status-offline)]": props.status === "offline",
            "bg-[var(--status-away)]": props.status === "away",
            "bg-[var(--status-busy)]": props.status === "busy",
        }
    )
);

function handleError() {
    hasError.value = true;
}

function handleLoad(e) {
    const img = e.target;
    if (img.naturalWidth <= 1 || img.naturalHeight <= 1) {
        hasError.value = true;
    }
}
</script>

<template>
    <div :class="containerClasses">
        <div :class="innerClasses" :style="{ backgroundColor: !src || hasError ? color : undefined }">
            <img
                v-if="(thumbUrl || src) && !hasError"
                :src="thumbUrl || src"
                :alt="alt"
                loading="lazy"
                class="h-full w-full object-cover"
                @error="handleError"
                @load="handleLoad"
            />
            <span v-else class="text-white">{{ initials }}</span>
        </div>

        <span v-if="status && variant === 'dot'" :class="statusClasses" />
    </div>
</template>