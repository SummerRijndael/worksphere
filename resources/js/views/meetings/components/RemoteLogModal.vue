<template>
    <TransitionRoot appear :show="open" as="template">
        <Dialog as="div" @close="$emit('update:open', false)" class="relative z-1000">
            <TransitionChild
                as="template"
                enter="duration-300 ease-out"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="duration-200 ease-in"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" />
            </TransitionChild>

            <div class="fixed inset-0 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <TransitionChild
                        as="template"
                        enter="duration-300 ease-out"
                        enter-from="opacity-0 scale-95"
                        enter-to="opacity-100 scale-100"
                        leave="duration-200 ease-in"
                        leave-from="opacity-100 scale-100"
                        leave-to="opacity-0 scale-95"
                    >
                        <DialogPanel class="w-full max-w-4xl transform overflow-hidden rounded-3xl bg-slate-900 border border-slate-800 shadow-2xl transition-all flex flex-col h-[80vh]">
                            <div class="p-6 border-b border-slate-800 flex items-center justify-between bg-slate-900/50 backdrop-blur-xl sticky top-0 z-10">
                                <div>
                                    <DialogTitle as="h3" class="text-xl font-bold text-white flex items-center gap-2">
                                        <Icon name="terminal" class="text-blue-400" />
                                        System Diagnostics
                                    </DialogTitle>
                                    <p class="text-xs text-slate-500 mt-1">Real-time application logs and connection tracking</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <button 
                                        @click="logManager.clear()" 
                                        class="p-2 text-slate-400 hover:text-white transition-colors"
                                        title="Clear logs"
                                    >
                                        <Icon name="trash-2" size="18" />
                                    </button>
                                    <button 
                                        @click="$emit('update:open', false)" 
                                        class="p-2 bg-slate-800 hover:bg-slate-700 rounded-full text-white transition-colors"
                                    >
                                        <Icon name="x" size="20" />
                                    </button>
                                </div>
                            </div>

                            <div class="flex-1 overflow-y-auto p-4 space-y-2 bg-black font-mono text-[11px] sm:text-xs custom-scrollbar">
                                <div v-if="logManager.logs.length === 0" class="h-full flex flex-col items-center justify-center text-slate-600 space-y-3 py-20">
                                    <Icon name="coffee" size="48" class="opacity-20" />
                                    <p>No system logs captured yet.</p>
                                </div>

                                <div 
                                    v-for="log in logManager.logs" 
                                    :key="log.id"
                                    class="group p-2 rounded-lg border border-transparent hover:border-slate-800 hover:bg-slate-900/50 transition-all"
                                >
                                    <div class="flex items-start gap-3">
                                        <span class="text-slate-600 whitespace-nowrap pt-0.5">{{ log.timestamp }}</span>
                                        <span 
                                            :class="{
                                                'text-blue-400': log.level === 'info',
                                                'text-amber-400': log.level === 'warn',
                                                'text-red-400 font-bold': log.level === 'error',
                                                'text-slate-400': log.level === 'debug'
                                            }"
                                            class="uppercase text-[10px] tracking-wider pt-0.5 min-w-[50px]"
                                        >
                                            [{{ log.level }}]
                                        </span>
                                        <div class="flex-1 min-w-0">
                                            <p :class="log.level === 'error' ? 'text-red-300' : 'text-slate-200'" class="whitespace-pre-wrap wrap-break-word leading-relaxed">
                                                {{ log.message }}
                                            </p>
                                            
                                            <!-- Data Inspector -->
                                            <div v-if="log.data" class="mt-2 text-[10px] bg-slate-900/80 p-2 rounded border border-slate-800 overflow-x-auto">
                                                <pre class="text-blue-300">{{ JSON.stringify(log.data, null, 2) }}</pre>
                                            </div>

                                            <!-- Stack Trace -->
                                            <div v-if="log.stack" class="mt-2 text-[10px] text-red-400/60 bg-red-400/5 p-2 rounded border border-red-400/10 overflow-x-auto whitespace-pre">
                                                {{ log.stack }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="p-4 border-t border-slate-800 bg-slate-900/50 flex items-center justify-between">
                                <span class="text-[10px] text-slate-500">
                                    Total Logs: {{ logManager.logs.length }}
                                </span>
                                <div class="flex gap-2">
                                    <button 
                                        @click="copyLogs" 
                                        class="px-3 py-1.5 bg-blue-600/10 hover:bg-blue-600 text-blue-500 hover:text-white border border-blue-500/20 rounded-lg text-[10px] font-bold transition-all flex items-center gap-2"
                                    >
                                        <Icon name="copy" size="12" />
                                        Copy All
                                    </button>
                                </div>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup lang="ts">
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { Icon } from '@/components/ui';
import { logManager } from '@/utils/LogManager';
import { toast } from 'vue-sonner';

defineProps<{
    open: boolean;
}>();

defineEmits(['update:open']);

function copyLogs() {
    const text = logManager.logs.map(l => `[${l.timestamp}] [${l.level.toUpperCase()}] ${l.message} ${l.data ? JSON.stringify(l.data) : ''}`).join('\n');
    navigator.clipboard.writeText(text);
    toast.success('Logs copied to clipboard');
}
</script>
