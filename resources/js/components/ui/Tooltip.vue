<script setup>
import {
    TooltipRoot,
    TooltipTrigger,
    TooltipPortal,
    TooltipContent,
    TooltipArrow,
    TooltipProvider,
} from "reka-ui";
import { cn } from "@/lib/utils";

defineProps({
    content: String,
    side: {
        type: String,
        default: "top",
        validator: (v) => ["top", "right", "bottom", "left"].includes(v),
    },
    align: {
        type: String,
        default: "center",
        validator: (v) => ["start", "center", "end"].includes(v),
    },
    sideOffset: {
        type: Number,
        default: 4,
    },
    delayDuration: {
        type: Number,
        default: 200,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    contentClass: {
        type: String,
        default: "",
    },
});
</script>

<template>
    <TooltipProvider :delay-duration="delayDuration">
        <TooltipRoot>
            <TooltipTrigger as-child>
                <slot />
            </TooltipTrigger>

            <TooltipPortal>
                <TooltipContent
                    v-if="!disabled && (content || $slots.content)"
                    :side="side"
                    :align="align"
                    :side-offset="sideOffset"
                    :class="
                        cn(
                            'z-[1070] overflow-hidden rounded-lg px-3 py-1.5 text-xs font-medium',
                            'bg-[var(--color-neutral-900)] text-white',
                            'dark:bg-[var(--color-neutral-100)] dark:text-[var(--color-neutral-900)]',
                            'animate-in fade-in-0 zoom-in-95',
                            'data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95',
                            'data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2',
                            'data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
                            'duration-150',
                            contentClass
                        )
                    "
                >
                    <slot name="content">
                        {{ content }}
                    </slot>
                    <TooltipArrow
                        class="fill-[var(--color-neutral-900)] dark:fill-[var(--color-neutral-100)]"
                    />
                </TooltipContent>
            </TooltipPortal>
        </TooltipRoot>
    </TooltipProvider>
</template>
