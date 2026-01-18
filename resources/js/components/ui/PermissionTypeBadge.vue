<script setup>
import { computed } from "vue";
import { LockKeyhole, Unlock, Globe, Users } from "lucide-vue-next";
import Badge from "./Badge.vue";

const props = defineProps({
    type: {
        type: String,
        required: true,
        validator: (v) => ["grant", "block"].includes(v),
    },
    scope: {
        type: String,
        default: "global",
        validator: (v) => ["global", "team"].includes(v),
    },
    size: {
        type: String,
        default: "md",
    },
    showIcon: {
        type: Boolean,
        default: true,
    },
});

const config = computed(() => {
    if (props.type === "grant") {
        return {
            variant: "success",
            label: "Grant",
            icon: Unlock,
        };
    }
    return {
        variant: "error",
        label: "Block",
        icon: LockKeyhole,
    };
});

const scopeConfig = computed(() => {
    if (props.scope === "team") {
        return {
            icon: Users,
            label: "Team",
        };
    }
    return {
        icon: Globe,
        label: "Global",
    };
});
</script>

<template>
    <div class="inline-flex items-center gap-1.5">
        <Badge :variant="config.variant" :size="size">
            <component v-if="showIcon" :is="config.icon" class="h-3 w-3" />
            {{ config.label }}
        </Badge>
        <Badge variant="outline" :size="size">
            <component v-if="showIcon" :is="scopeConfig.icon" class="h-3 w-3" />
            {{ scopeConfig.label }}
        </Badge>
    </div>
</template>
