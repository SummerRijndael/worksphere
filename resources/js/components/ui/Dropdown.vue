<script setup lang="ts">
import {
    DropdownMenuRoot,
    DropdownMenuTrigger,
    DropdownMenuPortal,
    DropdownMenuContent,
    DropdownMenuItem,
} from "reka-ui";
import { cn } from "@/lib/utils";
import type { Component } from "vue";

defineOptions({
    inheritAttrs: false,
});

export interface DropdownItem {
    label: string;
    icon?: Component;
    variant?: 'default' | 'danger';
    action: () => void;
}

defineProps({
    align: {
        type: String as () => "start" | "center" | "end",
        default: "end",
        validator: (v: string) => ["start", "center", "end"].includes(v),
    },
    side: {
        type: String as () => "top" | "right" | "bottom" | "left",
        default: "bottom",
        validator: (v: string) => ["top", "right", "bottom", "left"].includes(v),
    },
    sideOffset: {
        type: Number,
        default: 4,
    },
    items: {
        type: Array as () => DropdownItem[],
        default: () => [],
    },
});

defineSlots<{
    default(): any;
    trigger(): any;
}>();
</script>

<template>
    <DropdownMenuRoot>
        <DropdownMenuTrigger as-child>
            <slot name="trigger" />
        </DropdownMenuTrigger>

        <DropdownMenuPortal>
            <DropdownMenuContent
                :align="align"
                :side="side"
                :side-offset="sideOffset"
                :class="
                    cn(
                        'z-[1060] min-w-[180px] overflow-hidden rounded-xl',
                        'bg-[var(--surface-elevated)] border border-[var(--border-default)]',
                        'p-1 shadow-lg',
                        'data-[state=open]:animate-in data-[state=closed]:animate-out',
                        'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
                        'data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
                        'data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2',
                        'data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
                        'duration-150'
                    )
                "
            >
                <slot />

                <template v-if="items.length">
                    <DropdownMenuItem
                        v-for="(item, index) in items"
                        :key="index"
                        class="flex items-center gap-2 px-2 py-1.5 text-sm outline-none cursor-pointer hover:bg-[var(--surface-secondary)] rounded-md transition-colors select-none"
                        :class="
                            item.variant === 'danger'
                                ? 'text-[var(--color-error)] hover:bg-red-50 dark:hover:bg-red-900/20'
                                : 'text-[var(--text-primary)]'
                        "
                        @select="item.action"
                    >
                        <component
                            :is="item.icon"
                            v-if="item.icon"
                            class="w-4 h-4 opacity-70"
                        />
                        <span>{{ item.label }}</span>
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
