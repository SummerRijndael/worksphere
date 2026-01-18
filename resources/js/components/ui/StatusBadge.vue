<script setup>
import { computed, markRaw } from "vue";
import { Clock, Shield, ShieldOff, Globe, Users } from "lucide-vue-next";
import Badge from "./Badge.vue";

const props = defineProps({
    status: {
        type: String,
        required: true,
        validator: (v) =>
            [
                "active",
                "inactive",
                "pending",
                "blocked",
                "suspended",
                "expired",
                "revoked",
                "grace_period",
                "approved",
                "rejected",
                // Generic statuses
                "success",
                "warning",
                "error",
                "neutral",
                "info",
                // Project statuses
                "completed",
                "archived",
                "on_hold",
                "cancelled",
                "draft",
            ].includes(v),
    },
    size: {
        type: String,
        default: "md",
    },
    showIcon: {
        type: Boolean,
        default: true,
    },
    showDot: {
        type: Boolean,
        default: false,
    },
});

const statusConfig = computed(() => {
    const configs = {
        active: { variant: "success", label: "Active", icon: markRaw(Shield) },
        inactive: { variant: "default", label: "Inactive", icon: null },
        pending: { variant: "warning", label: "Pending", icon: markRaw(Clock) },
        blocked: { variant: "error", label: "Blocked", icon: markRaw(ShieldOff) },
        suspended: { variant: "warning", label: "Suspended", icon: markRaw(Clock) },
        expired: { variant: "default", label: "Expired", icon: markRaw(Clock) },
        revoked: { variant: "error", label: "Revoked", icon: markRaw(ShieldOff) },
        grace_period: { variant: "warning", label: "Grace Period", icon: markRaw(Clock) },
        approved: { variant: "success", label: "Approved", icon: markRaw(Shield) },
        rejected: { variant: "error", label: "Rejected", icon: markRaw(ShieldOff) },
        // Generic mappings
        success: { variant: "success", label: "Success", icon: markRaw(Shield) },
        warning: { variant: "warning", label: "Warning", icon: markRaw(Clock) },
        error: { variant: "error", label: "Error", icon: markRaw(ShieldOff) },
        neutral: { variant: "default", label: "Neutral", icon: null },
        info: { variant: "default", label: "Info", icon: null },
        // Project statuses
        completed: { variant: "success", label: "Completed", icon: markRaw(Shield) },
        archived: { variant: "default", label: "Archived", icon: markRaw(ShieldOff) },
        on_hold: { variant: "warning", label: "On Hold", icon: markRaw(Clock) },
        cancelled: { variant: "error", label: "Cancelled", icon: markRaw(ShieldOff) },
        draft: { variant: "default", label: "Draft", icon: markRaw(Clock) },
    };

    return configs[props.status] || configs.inactive;
});
</script>

<template>
    <Badge :variant="statusConfig.variant" :size="size" :dot="showDot">
        <component
            v-if="showIcon && statusConfig.icon"
            :is="statusConfig.icon"
            class="h-3 w-3"
        />
        {{ statusConfig.label }}
    </Badge>
</template>
