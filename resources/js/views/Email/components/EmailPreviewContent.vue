<template>
    <div
        class="flex flex-col overflow-hidden"
        :class="[
            embedded ? 'w-full flex-1 min-h-full' : 'h-full',
            !isPopup && !embedded ? 'max-h-[calc(100vh-110px)]' : '',
        ]"
    >
        <!-- Header -->
        <!-- Default Header (Inline Mode) -->
        <!-- Default Header (Inline Mode) -->
        <div
            v-if="!isPopup"
            class="border-b border-(--border-default) bg-linear-to-b from-(--surface-secondary) to-transparent preview-animate-item transition-all duration-200"
            :class="isHeaderExpanded ? 'p-4 md:p-6' : 'p-3'"
        >
            <div class="flex justify-between items-start gap-4">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <button
                        @click="isHeaderExpanded = !isHeaderExpanded"
                        class="p-1 rounded-lg hover:bg-(--surface-tertiary) text-(--text-secondary) transition-colors shrink-0"
                        :title="isHeaderExpanded ? 'Collapse' : 'Expand'"
                    >
                        <ChevronDownIcon
                            class="w-5 h-5 transition-transform duration-200"
                            :class="{ '-rotate-90': !isHeaderExpanded }"
                        />
                    </button>

                    <div
                        v-if="isHeaderExpanded"
                        class="flex items-center gap-2 overflow-hidden"
                    >
                        <div
                            v-if="email.is_important"
                            class="shrink-0 text-red-500"
                            title="High Importance"
                        >
                            <AlertCircleIcon class="w-4 h-4 fill-current/10" />
                        </div>
                        <h1
                            class="text-[24px]! font-bold text-(--text-primary) leading-tight truncate"
                            :title="email.subject"
                        >
                            {{ email.subject }}
                        </h1>
                    </div>
                    <div v-else class="flex items-center gap-3 truncate">
                        <span
                            class="font-semibold text-sm text-(--text-primary) truncate"
                            >{{ email.from_name }}</span
                        >
                        <span
                            class="text-xs text-(--text-muted) px-2 py-0.5 rounded bg-(--surface-tertiary)"
                            >Subject</span
                        >
                        <span
                            class="text-sm text-(--text-secondary) truncate"
                            :title="email.subject"
                            >{{ email.subject }}</span
                        >
                    </div>
                </div>

                <div class="flex space-x-1 shrink-0">
                    <button
                        v-if="isHeaderExpanded"
                        class="p-1.5 text-(--text-secondary) hover:bg-(--surface-tertiary) hover:text-(--text-primary) rounded-lg transition-colors"
                        title="Show Details"
                        @click="showMetadata = !showMetadata"
                        :class="{
                            'bg-(--surface-tertiary) text-(--text-primary)':
                                showMetadata,
                        }"
                    >
                        <InfoIcon class="w-4 h-4" />
                    </button>
                    <button
                        v-if="isDev"
                        class="p-1.5 text-(--text-secondary) hover:bg-(--surface-tertiary) hover:text-(--text-primary) rounded-lg transition-colors"
                        title="Preview Dev Tools"
                        @click="showDevToolsModal = true"
                    >
                        <SlidersHorizontalIcon class="w-4 h-4" />
                    </button>
                    <button
                        class="p-1.5 text-(--text-secondary) hover:bg-(--surface-tertiary) hover:text-(--text-primary) rounded-lg transition-colors"
                        title="Print"
                        @click="printEmail"
                    >
                        <PrinterIcon class="w-4 h-4" />
                    </button>
                    <button
                        v-if="isHeaderExpanded"
                        class="p-1.5 text-(--text-secondary) hover:bg-(--surface-tertiary) hover:text-(--text-primary) rounded-lg transition-colors"
                        title="Expand"
                        @click="expandEmail"
                    >
                        <Maximize2Icon class="w-4 h-4" />
                    </button>
                    <div
                        v-else
                        class="flex items-center ml-2 pl-2 border-l border-(--border-default)"
                    >
                        <span
                            class="text-xs text-(--text-muted) whitespace-nowrap"
                            >{{ formatDate(email.date) }}</span
                        >
                    </div>
                </div>
            </div>

            <!-- Redesigned Metadata Panel (Gmail Style) -->
            <div
                v-if="showMetadata && isHeaderExpanded"
                class="mt-4 p-5 rounded-2xl bg-(--surface-secondary) border border-(--border-default) shadow-sm animate-in fade-in slide-in-from-top-2 duration-200"
            >
                <div class="flex justify-between items-start mb-4">
                    <h3
                        class="text-xs font-bold text-(--text-primary) uppercase tracking-wider"
                    >
                        Original Message Details
                    </h3>
                    <div class="flex items-center gap-3">
                        <button
                            @click="copyMetadata"
                            class="text-[10px] font-bold text-(--text-secondary) hover:text-(--text-primary) hover:underline flex items-center gap-1"
                            title="Copy all details"
                        >
                            <CopyIcon class="w-3 h-3" />
                            COPY INFO
                        </button>
                        <button
                            @click="openShowOriginal"
                            class="text-[10px] font-bold text-(--interactive-primary) hover:underline flex items-center gap-1"
                        >
                            <FileCodeIcon class="w-3 h-3" />
                            SHOW ORIGINAL
                        </button>
                    </div>
                </div>

                <div
                    class="grid grid-cols-[110px_1fr] gap-y-3 text-[13px] leading-relaxed"
                >
                    <div class="text-(--text-muted) font-medium">
                        Created at
                    </div>
                    <div v-if="email.date" class="text-(--text-primary)">
                        {{ formatDateTime(email.date, "EEE, MMM d, yyyy") }}
                        at {{ formatDateTime(email.date, "h:mm a") }}
                        <span class="text-(--text-muted) ml-1"
                            >({{ formatRelativeTime(email.date) }})</span
                        >
                    </div>

                    <div class="text-(--text-muted) font-medium">
                        Message ID
                    </div>
                    <div class="flex items-center gap-2">
                        <div
                            class="text-(--text-primary) break-all font-mono text-[11px] select-all"
                        >
                            {{ email.message_id }}
                        </div>
                        <button
                            @click="copyToClipboard(email.message_id || '')"
                            class="text-(--text-secondary) hover:text-(--text-primary) p-0.5 rounded transition-colors"
                            title="Copy ID"
                        >
                            <CopyIcon class="w-3 h-3" />
                        </button>
                    </div>

                    <div class="text-(--text-muted) font-medium">From</div>
                    <div class="text-(--text-primary)">
                        <span class="font-semibold">{{ email.from_name }}</span>
                        <span class="text-(--text-muted) ml-1"
                            >&lt;{{ email.from_email }}&gt;</span
                        >
                    </div>

                    <div class="text-(--text-muted) font-medium">To</div>
                    <div class="text-(--text-primary)">
                        <div
                            v-for="recipient in email.to"
                            :key="recipient.email"
                            class="flex items-center gap-1"
                        >
                            <span v-if="recipient.name" class="font-medium">{{
                                recipient.name
                            }}</span>
                            <span class="text-(--text-muted)"
                                >&lt;{{
                                    recipient.email || recipient
                                }}&gt;</span
                            >
                        </div>
                    </div>

                    <div
                        v-if="email.cc && email.cc.length > 0"
                        class="contents"
                    >
                        <div class="text-(--text-muted) font-medium">Cc</div>
                        <div class="text-(--text-primary)">
                            <div
                                v-for="recipient in email.cc"
                                :key="recipient.email"
                                class="flex items-center gap-1"
                            >
                                <span
                                    v-if="recipient.name"
                                    class="font-medium"
                                    >{{ recipient.name }}</span
                                >
                                <span class="text-(--text-muted)"
                                    >&lt;{{ recipient.email }}&gt;</span
                                >
                            </div>
                        </div>
                    </div>

                    <div class="text-(--text-muted) font-medium">Subject</div>
                    <div class="text-(--text-primary) font-semibold">
                        {{ email.subject }}
                    </div>

                    <!-- Security Headers -->
                    <template
                        v-if="authInfo.spf || authInfo.dkim || authInfo.dmarc"
                    >
                        <div
                            class="col-span-2 my-1 border-t border-(--border-default)/50"
                        ></div>

                        <div class="text-(--text-muted) font-medium">SPF</div>
                        <div class="flex items-center gap-2">
                            <span
                                class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-tight"
                                :class="
                                    authInfo.spf === 'pass'
                                        ? 'bg-green-500/10 text-green-600'
                                        : 'bg-(--surface-tertiary) text-(--text-muted)'
                                "
                            >
                                {{ authInfo.spf || "NEUTRAL" }}
                            </span>
                            <span
                                v-if="authInfo.spfDetails"
                                class="text-xs text-(--text-secondary)"
                                >{{ authInfo.spfDetails }}</span
                            >
                        </div>

                        <div class="text-(--text-muted) font-medium">DKIM</div>
                        <div class="flex items-center gap-2">
                            <span
                                class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-tight"
                                :class="
                                    authInfo.dkim === 'pass'
                                        ? 'bg-green-500/10 text-green-600'
                                        : 'bg-(--surface-tertiary) text-(--text-muted)'
                                "
                            >
                                {{ authInfo.dkim || "NEUTRAL" }}
                            </span>
                            <span
                                v-if="authInfo.dkimDetails"
                                class="text-xs text-(--text-secondary)"
                                >{{ authInfo.dkimDetails }}</span
                            >
                        </div>

                        <div class="text-(--text-muted) font-medium">DMARC</div>
                        <div class="flex items-center gap-2">
                            <span
                                class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-tight"
                                :class="
                                    authInfo.dmarc === 'pass'
                                        ? 'bg-green-500/10 text-green-600'
                                        : 'bg-(--surface-tertiary) text-(--text-muted)'
                                "
                            >
                                {{ authInfo.dmarc || "NEUTRAL" }}
                            </span>
                        </div>
                    </template>
                </div>
            </div>

            <div
                v-if="isHeaderExpanded"
                class="mt-4 animate-in fade-in slide-in-from-top-1 duration-200"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3 min-w-0">
                        <div class="relative shrink-0 mt-0.5">
                            <img
                                :src="`https://ui-avatars.com/api/?name=${encodeURIComponent(email.from_name || '')}&background=6366f1&color=fff`"
                                class="w-10 h-10 rounded-full ring-2 ring-(--border-default) ring-offset-2 ring-offset-(--surface-primary)"
                                alt=""
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline flex-wrap gap-2">
                                <span
                                    class="text-sm font-bold text-(--text-primary)"
                                >
                                    {{ email.from_name }}
                                </span>
                                <span class="text-xs text-(--text-secondary)">
                                    &lt;{{ email.from_email }}&gt;
                                </span>
                            </div>

                            <div
                                class="mt-1 text-xs text-(--text-secondary) leading-relaxed"
                            >
                                <div
                                    class="flex flex-wrap items-baseline gap-1"
                                >
                                    <span
                                        class="text-(--text-muted) font-medium"
                                        >To:</span
                                    >
                                    <template
                                        v-for="(recipient, idx) in isToExpanded
                                            ? email.to
                                            : email.to.slice(0, 5)"
                                        :key="idx"
                                    >
                                        <span class="text-(--text-primary)">
                                            {{
                                                recipient.name ||
                                                recipient.email ||
                                                recipient
                                            }}{{
                                                idx <
                                                (isToExpanded
                                                    ? email.to.length
                                                    : Math.min(
                                                          email.to.length,
                                                          5,
                                                      )) -
                                                    1
                                                    ? ","
                                                    : ""
                                            }}
                                        </span>
                                    </template>
                                    <button
                                        v-if="
                                            !isToExpanded && email.to.length > 5
                                        "
                                        @click.stop="isToExpanded = true"
                                        class="text-(--interactive-primary) hover:underline font-medium ml-0.5"
                                    >
                                        +{{ email.to.length - 5 }} others
                                    </button>
                                </div>

                                <div
                                    v-if="email.cc && email.cc.length > 0"
                                    class="flex flex-wrap items-baseline gap-1 mt-0.5"
                                    title="Cc"
                                >
                                    <span
                                        class="text-(--text-muted) font-medium"
                                        >Cc:</span
                                    >
                                    <template
                                        v-for="(recipient, idx) in isCcExpanded
                                            ? email.cc
                                            : email.cc.slice(0, 5)"
                                        :key="idx"
                                    >
                                        <span class="text-(--text-primary)">
                                            {{
                                                recipient.name ||
                                                recipient.email ||
                                                recipient
                                            }}{{
                                                idx <
                                                (isCcExpanded
                                                    ? email.cc.length
                                                    : Math.min(
                                                          email.cc.length,
                                                          5,
                                                      )) -
                                                    1
                                                    ? ","
                                                    : ""
                                            }}
                                        </span>
                                    </template>
                                    <button
                                        v-if="
                                            !isCcExpanded && email.cc.length > 5
                                        "
                                        @click.stop="isCcExpanded = true"
                                        class="text-(--interactive-primary) hover:underline font-medium ml-0.5"
                                    >
                                        +{{ email.cc.length - 5 }} others
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-right shrink-0">
                        <p class="text-sm font-medium text-(--text-primary)">
                            {{ formatDate(email.date) }}
                        </p>
                        <p class="text-xs text-(--text-muted)">
                            {{ formatRelativeTime(email.date) }}
                        </p>
                    </div>
                </div>

                <!-- Read Receipt Prompt (Subtle) -->
                <div
                    v-if="showReadReceiptPrompt"
                    class="mt-2 flex items-center justify-between p-2 bg-blue-50/50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-800/30 rounded-lg text-xs"
                >
                    <div
                        class="flex items-center gap-2 text-blue-700 dark:text-blue-300"
                    >
                        <InfoIcon class="w-3.5 h-3.5" />
                        <span class="font-medium"
                            >Sender requested a read receipt</span
                        >
                    </div>
                    <div class="flex gap-2">
                        <button
                            @click="ignoreReadReceipt"
                            class="text-blue-600 dark:text-blue-400 hover:underline"
                        >
                            Ignore
                        </button>
                        <button
                            @click="sendReadReceipt"
                            class="text-blue-700 dark:text-blue-300 font-semibold hover:underline"
                        >
                            Send
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Popup Header -->
        <div
            v-else
            class="p-4 border-b border-(--border-default) bg-(--surface-primary) sticky top-0 z-10 shadow-sm"
        >
            <div class="flex justify-between items-start mb-4">
                <h1
                    class="text-xl font-semibold text-(--text-primary) leading-tight truncate pr-4"
                >
                    {{ email.subject }}
                </h1>
                <div class="flex items-center gap-2">
                    <button
                        v-if="isDev"
                        @click="showDevToolsModal = true"
                        class="p-2 text-(--text-secondary) hover:bg-(--surface-secondary) rounded-lg"
                        title="Preview Dev Tools"
                    >
                        <SlidersHorizontalIcon class="w-4 h-4" />
                    </button>
                    <button
                        @click="printEmail"
                        class="p-2 text-(--text-secondary) hover:bg-(--surface-secondary) rounded-lg"
                        title="Print"
                    >
                        <PrinterIcon class="w-4 h-4" />
                    </button>
                </div>
            </div>

            <!-- Meta & Actions Row -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <img
                        :src="`https://ui-avatars.com/api/?name=${encodeURIComponent(email.from_name || '')}&background=6366f1&color=fff`"
                        class="w-8 h-8 rounded-full"
                        alt=""
                    />
                    <div>
                        <div class="text-sm font-medium text-(--text-primary)">
                            {{ email.from_name }}
                        </div>
                        <div class="text-xs text-(--text-secondary)">
                            {{ formatDate(email.date) }}
                        </div>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button
                        v-if="email.is_draft"
                        @click="emit('edit')"
                        :disabled="!store.selectedAccount"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-(--interactive-primary) text-white hover:bg-(--interactive-primary)/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <PencilIcon class="w-3.5 h-3.5" /> Edit Draft
                    </button>
                    <template v-else>
                        <button
                            @click="emit('reply')"
                            :disabled="!store.selectedAccount"
                            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-(--interactive-primary) text-white hover:bg-(--interactive-primary)/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <ReplyIcon class="w-3.5 h-3.5" /> Reply
                        </button>
                        <button
                            @click="emit('reply-all')"
                            :disabled="!store.selectedAccount"
                            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-(--border-default) text-(--text-primary) hover:bg-(--surface-secondary) transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <ReplyAllIcon class="w-3.5 h-3.5" /> Reply All
                        </button>
                        <button
                            @click="emit('forward')"
                            :disabled="!store.selectedAccount"
                            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-(--border-default) text-(--text-primary) hover:bg-(--surface-secondary) transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <ForwardIcon class="w-3.5 h-3.5" /> Forward
                        </button>
                    </template>
                    <div class="h-4 w-px bg-(--border-default) mx-1"></div>
                    <button
                        @click="exportAsEml"
                        class="p-1.5 text-(--text-secondary) hover:bg-(--surface-secondary) rounded-lg transition-colors"
                        title="Export .eml"
                    >
                        <DownloadIcon class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div
            class="flex-1 flex flex-col min-h-0 preview-animate-item"
            :class="[
                embedded
                    ? 'p-0 overflow-visible'
                    : 'p-6 overflow-auto scrollbar-thin',
            ]"
        >
            <!-- Security / Trust Banner -->
            <div
                v-if="
                    (!isTrustedSource && !isUntrustedDismissed) ||
                    hasBlockedImages
                "
                class="mb-6 space-y-4 p-3 shrink-0"
            >
                <div
                    v-if="!isTrustedSource && !isUntrustedDismissed"
                    class="rounded-xl bg-amber-500/10 border border-amber-500/20 p-4 flex items-start gap-4 relative group"
                >
                    <AlertTriangleIcon
                        class="w-5 h-5 text-amber-500 shrink-0 mt-0.5"
                    />
                    <div class="flex-1">
                        <p class="text-sm font-medium text-amber-500">
                            Untrusted Source
                        </p>
                        <p class="text-xs text-amber-500/80 mt-1">
                            Use caution with links and attachments.
                        </p>
                    </div>
                    <button
                        @click="dismissUntrusted"
                        class="text-amber-500/60 hover:text-amber-500 hover:bg-amber-500/10 p-1.5 rounded-lg transition-colors"
                        title="Dismiss for this email"
                    >
                        <XIcon class="w-4 h-4" />
                    </button>
                </div>

                <div
                    v-if="hasBlockedImages && !isBlockedImagesDismissed"
                    class="rounded-xl bg-(--surface-secondary) border border-(--border-default) p-4 flex items-center justify-between gap-4 shadow-sm animate-in fade-in slide-in-from-top-2 duration-300"
                >
                    <div class="flex items-center gap-3">
                        <ImageIcon
                            class="w-5 h-5 text-(--interactive-primary)"
                        />
                        <div class="text-sm text-(--text-secondary)">
                            Tracking images are hidden for your privacy.
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            @click="showImages = true"
                            class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-(--surface-elevated) border border-(--border-default) text-(--text-primary) hover:border-(--interactive-primary)/30 transition-all shadow-sm"
                        >
                            Show Images
                        </button>
                        <button
                            @click="isBlockedImagesDismissed = true"
                            class="text-(--text-muted) hover:text-(--text-primary) hover:bg-(--surface-tertiary) p-1.5 rounded-lg transition-colors"
                            title="Dismiss"
                        >
                            <XIcon class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Loading State -->
            <div
                v-if="loadingBody"
                class="flex flex-col items-center justify-center py-20 animate-in fade-in duration-300"
            >
                <div
                    class="w-8 h-8 border-3 border-(--surface-tertiary) border-t-(--interactive-primary) rounded-full animate-spin"
                ></div>
                <p class="mt-3 text-sm text-(--text-muted)">
                    Loading content...
                </p>
            </div>

            <!-- Email Content Renderers -->
            <div
                v-else
                class="email-content-wrapper w-full flex-1 flex min-h-0 overflow-hidden gap-4"
                :class="previewRenderer === 'both' ? 'flex-col lg:flex-row' : 'flex-col'"
            >
                <!-- Iframe Renderer -->
                <div 
                    v-if="previewRenderer === 'iframe' || previewRenderer === 'both'"
                    class="flex-1 flex flex-col min-h-0 border border-(--border-default)/50 rounded-xl overflow-hidden relative"
                >
                    <div v-if="previewRenderer === 'both'" class="absolute top-2 right-2 z-10 px-2 py-0.5 rounded bg-black/50 text-white text-[10px] font-bold uppercase tracking-wider backdrop-blur-md">
                        Iframe Renderer
                    </div>
                    <iframe
                        ref="iframeHost"
                        title="Email Preview (Iframe)"
                        sandbox="allow-scripts"
                        class="h-full w-full border-0 bg-white"
                    />
                </div>

                <!-- Shadow DOM Renderer -->
                <div 
                    v-if="previewRenderer === 'shadow' || previewRenderer === 'both'"
                    class="flex-1 flex flex-col min-h-0 border border-(--border-default)/50 rounded-xl overflow-hidden relative bg-white"
                >
                    <div v-if="previewRenderer === 'both'" class="absolute top-2 right-2 z-10 px-2 py-0.5 rounded bg-black/50 text-white text-[10px] font-bold uppercase tracking-wider backdrop-blur-md">
                        Shadow DOM Renderer
                    </div>
                    <div
                        class="h-full w-full overflow-hidden"
                        ref="shadowHost"
                    ></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Preview Dev Tools -->
    <Modal
        v-if="isDev"
        v-model:open="showDevToolsModal"
        title="Email Preview Dev Tools"
        description="Toggle preview feature flags for testing."
        size="lg"
    >
        <div class="space-y-4">
            <div
                class="rounded-xl border border-(--border-default) bg-(--surface-secondary) p-4"
            >
                <p class="text-sm font-semibold text-(--text-primary) mb-3">
                    Renderer
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        :variant="
                            previewRenderer === 'shadow' ? 'primary' : 'outline'
                        "
                        size="sm"
                        @click="previewRenderer = 'shadow'"
                    >
                        Shadow DOM
                    </Button>
                    <Button
                        :variant="
                            previewRenderer === 'iframe' ? 'primary' : 'outline'
                        "
                        size="sm"
                        @click="previewRenderer = 'iframe'"
                    >
                        Iframe
                    </Button>
                    <Button
                        :variant="
                            previewRenderer === 'both' ? 'primary' : 'outline'
                        "
                        size="sm"
                        @click="previewRenderer = 'both'"
                    >
                        Comparison (Split)
                    </Button>
                </div>
                <p class="mt-2 text-xs text-(--text-muted)">
                    Current mode:
                    {{ 
                        previewRenderer === "iframe" ? "Iframe" : 
                        previewRenderer === "shadow" ? "Shadow DOM" : 
                        "Comparison Mode"
                    }}
                </p>
            </div>

            <div
                class="rounded-xl border border-(--border-default) bg-(--surface-secondary) p-4 space-y-3"
            >
                <Switch
                    v-model="disableScaleToFit"
                    label="Disable scale-to-fit"
                    description="When enabled, wide email content uses horizontal scroll instead of auto-scaling."
                />
                <Switch
                    v-model="devDefaultShowExternalImages"
                    label="Show external images by default"
                    description="Applies to every email preview while testing."
                />
            </div>
        </div>

        <template #footer>
            <div class="flex gap-3 w-full">
                <Button
                    variant="ghost"
                    class="flex-1"
                    @click="resetDevPreviewFlags"
                >
                    Reset Flags
                </Button>
                <Button
                    variant="primary"
                    class="flex-1"
                    @click="showDevToolsModal = false"
                >
                    Done
                </Button>
            </div>
        </template>
    </Modal>

    <!-- External Link Warning Modal -->
    <Modal
        v-model:open="showLinkWarning"
        title="Security Warning"
        description="You are about to leave the application and open an external link."
        size="md"
    >
        <div class="flex flex-col items-center py-4 text-center">
            <div class="p-3 rounded-full bg-amber-500/10 mb-4 animate-pulse">
                <ShieldAlertIcon class="w-12 h-12 text-amber-500" />
            </div>

            <h3 class="text-lg font-semibold text-(--text-primary) mb-2">
                Leaving WorkSphere
            </h3>
            <p class="text-sm text-(--text-secondary) mb-6 max-w-sm">
                For your security, please verify that you trust this link before
                proceeding. External links can sometimes lead to malicious
                websites.
            </p>

            <div
                class="w-full p-3 rounded-xl bg-(--surface-secondary) border border-(--border-default) mb-6 overflow-hidden"
            >
                <p
                    class="text-xs font-mono text-(--text-primary) break-all text-left line-clamp-2"
                    :title="pendingLink"
                >
                    {{ pendingLink }}
                </p>
            </div>
        </div>

        <template #footer>
            <div class="flex gap-3 w-full">
                <Button
                    variant="ghost"
                    class="flex-1"
                    @click="showLinkWarning = false"
                    >Cancel</Button
                >
                <Button variant="primary" class="flex-1" @click="proceedToLink">
                    Proceed to Link
                    <ExternalLinkIcon class="w-4 h-4 ml-2" />
                </Button>
            </div>
        </template>
    </Modal>

    <!-- Show Original Modal -->
    <Modal v-model:open="showOriginalModal" title="Original Message" size="xl">
        <div
            class="bg-(--surface-secondary) p-4 rounded-xl border border-(--border-default) overflow-hidden h-[70vh] flex flex-col"
        >
            <div class="flex items-center justify-between mb-4 shrink-0">
                <span
                    class="text-xs font-semibold text-(--text-muted) uppercase tracking-wider"
                    >Raw Message Source (RFC822)</span
                >
                <Button
                    variant="ghost"
                    size="sm"
                    @click="copyToClipboard(rawSource)"
                    :disabled="loadingSource || !rawSource"
                >
                    Copy to Clipboard
                </Button>
            </div>

            <div
                v-if="loadingSource"
                class="flex-1 flex items-center justify-center"
            >
                <div
                    class="w-8 h-8 border-3 border-(--surface-tertiary) border-t-(--interactive-primary) rounded-full animate-spin"
                ></div>
            </div>
            <pre
                v-else
                class="flex-1 overflow-auto text-[11px] font-mono text-(--text-secondary) leading-relaxed select-all whitespace-pre-wrap break-all"
            ><code>{{ rawSource }}</code></pre>
        </div>
    </Modal>
</template>

<script setup lang="ts">
import {
    computed,
    ref,
    watch,
    nextTick,
    onBeforeUnmount,
    onMounted,
} from "vue";
import { useImageCache } from "@/composables/useImageCache";
import axios from "axios";
import {
    PrinterIcon,
    Maximize2Icon,
    AlertTriangleIcon,
    ReplyIcon,
    ForwardIcon,
    PencilIcon,
    ReplyAllIcon,
    ImageIcon,
    ChevronDownIcon,
    InfoIcon,
    DownloadIcon,
    ExternalLinkIcon,
    ShieldAlertIcon,
    XIcon,
    FileCodeIcon,
    AlertCircleIcon,
    CopyIcon,
    SlidersHorizontalIcon,
} from "lucide-vue-next";
import { useDate } from "@/composables/useDate";

const { formatDate, formatDateTime, formatRelativeTime } = useDate();
import { animate, stagger } from "animejs";
import { sanitizeHtml } from "@/utils/sanitize";
import { useEmailStore } from "@/stores/emailStore";
import type { Email, EmailAttachment } from "@/stores/emailStore";
import Modal from "@/components/ui/Modal.vue";
import Button from "@/components/ui/Button.vue";
import Switch from "@/components/ui/Switch.vue";

const props = defineProps<{
    email: Email;
    isPopup?: boolean;
    embedded?: boolean;
}>();

type PreviewRenderer = "shadow" | "iframe" | "both";

const isDev = import.meta.env.DEV;
const showDevToolsModal = ref(false);

const DEV_PREVIEW_RENDERER_KEY = "email_preview_renderer_mode";
const DEV_DISABLE_SCALE_KEY = "email_preview_disable_scale_to_fit";
const DEV_SHOW_IMAGES_DEFAULT_KEY = "email_preview_show_images_by_default";

function getDevFlag(key: string): string | null {
    if (!isDev) return null;
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function setDevFlag(key: string, value: string) {
    if (!isDev) return;
    try {
        localStorage.setItem(key, value);
    } catch {
        // Ignore storage errors in private mode.
    }
}

const savedRenderer = getDevFlag(DEV_PREVIEW_RENDERER_KEY);
const previewRenderer = ref<PreviewRenderer>(
    savedRenderer === "iframe" ? "iframe" : (savedRenderer === "both" ? "both" : "shadow"),
);
const disableScaleToFit = ref(getDevFlag(DEV_DISABLE_SCALE_KEY) === "true");
const devDefaultShowExternalImages = ref(
    getDevFlag(DEV_SHOW_IMAGES_DEFAULT_KEY) === "true",
);
const useIframeRenderer = computed(
    () => isDev && (previewRenderer.value === "iframe" || previewRenderer.value === "both"),
);
const useShadowRenderer = computed(
    () => previewRenderer.value === "shadow" || previewRenderer.value === "both",
);

watch(previewRenderer, (value) => {
    setDevFlag(DEV_PREVIEW_RENDERER_KEY, value);
});

watch(disableScaleToFit, (value) => {
    setDevFlag(DEV_DISABLE_SCALE_KEY, String(value));
});

function resetDevPreviewFlags() {
    previewRenderer.value = "shadow";
    disableScaleToFit.value = false;
    devDefaultShowExternalImages.value = false;
}

// Read Receipt Logic
const showReadReceiptPrompt = ref(false);

function checkForReadReceipt() {
    const headers = props.email.headers || {};
    const dnt =
        headers["Disposition-Notification-To"] ||
        headers["disposition-notification-to"];

    if (dnt && !localStorage.getItem(`read_receipt_sent_${props.email.id}`)) {
        showReadReceiptPrompt.value = true;
    } else {
        showReadReceiptPrompt.value = false;
    }
}

watch(
    () => props.email,
    () => {
        checkForReadReceipt();
    },
    { immediate: true },
);

async function sendReadReceipt() {
    try {
        await axios.post(`/api/emails/${props.email.id}/read-receipt`);
        // alert("Read receipt sent."); // Removed alert for smoother UX
        localStorage.setItem(`read_receipt_sent_${props.email.id}`, "true");
        showReadReceiptPrompt.value = false;
    } catch (e) {
        console.error("Failed to send read receipt", e);
    }
}

function ignoreReadReceipt() {
    localStorage.setItem(`read_receipt_sent_${props.email.id}`, "ignored");
    showReadReceiptPrompt.value = false;
}

const { getCachedImage } = useImageCache();

const store = useEmailStore();

const emit = defineEmits<{
    reply: [];
    "reply-all": [];
    forward: [];
    "forward-as-attachment": [];
    edit: [];
}>();

// --- Security & Privacy ---
const showImages = ref(false);
const hasBlockedImages = ref(false);
const isBlockedImagesDismissed = ref(false);
const selectedAttachments = ref<Set<string>>(new Set());
// const isAttachmentsExpanded = ref(false);
const isToExpanded = ref(false);
const isCcExpanded = ref(false);
const isHeaderExpanded = ref(
    localStorage.getItem("email_header_expanded") !== "false",
);

watch(devDefaultShowExternalImages, (value) => {
    setDevFlag(DEV_SHOW_IMAGES_DEFAULT_KEY, String(value));
    showImages.value = value;
});

watch(isHeaderExpanded, (val) => {
    localStorage.setItem("email_header_expanded", String(val));
});
const showMetadata = ref(false);
const showOriginalModal = ref(false);
const shadowHost = ref<HTMLElement | null>(null);
const iframeHost = ref<HTMLIFrameElement | null>(null);
const shadowRoot = ref<ShadowRoot | null>(null);
const shadowResizeObserver = ref<ResizeObserver | null>(null);
let iframeDocument: Document | null = null;
let iframeClickHandler: ((event: MouseEvent) => void) | null = null;

// Authentication Header Parsing
const authInfo = computed(() => {
    const headers = props.email.headers || {};
    // authentication-results header is the standard way to check SPF/DKIM/DMARC
    const authResults =
        headers["authentication-results"] ||
        headers["Authentication-Results"] ||
        "";

    // Extract SPF
    const spfMatch = authResults.match(/spf=([a-z]+)/i) || [];
    const spfDetailsMatch =
        authResults.match(/spf=[a-z]+\s+\(([^)]+)\)/i) || [];

    // Extract DKIM
    const dkimMatch = authResults.match(/dkim=([a-z]+)/i) || [];
    const dkimDetailsMatch =
        authResults.match(/dkim=[a-z]+\s+header\.d=([^\s;]+)/i) || [];

    // Extract DMARC
    const dmarcMatch = authResults.match(/dmarc=([a-z]+)/i) || [];

    return {
        spf: spfMatch[1]?.toLowerCase(),
        spfDetails: spfDetailsMatch[1],
        dkim: dkimMatch[1]?.toLowerCase(),
        dkimDetails: dkimDetailsMatch[1],
        dmarc: dmarcMatch[1]?.toLowerCase(),
    };
});

const rawSource = ref("");
const loadingSource = ref(false);

async function openShowOriginal() {
    showOriginalModal.value = true;

    // If we already have it (maybe cache locally too? nah, allow refetch for now or rely on browser/redis)
    if (
        rawSource.value &&
        rawSource.value !== "Failed to load original message source."
    )
        return;

    loadingSource.value = true;
    rawSource.value = "";

    try {
        const response = await fetch(`/api/emails/${props.email.id}/source`, {
            headers: {
                "X-CSRF-TOKEN":
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute("content") || "",
                Accept: "application/json",
            },
        });
        if (!response.ok) throw new Error("Failed to fetch source");
        const data = await response.json();
        rawSource.value = data.source;
    } catch (e) {
        console.error("Failed to fetch source", e);
        rawSource.value = "Failed to load original message source.";
    } finally {
        loadingSource.value = false;
    }
}

function copyToClipboard(text: string) {
    if (!text) return;
    navigator.clipboard.writeText(text);
}

function copyMetadata() {
    if (!props.email) return;

    const lines = [
        `Subject: ${props.email.subject}`,
        `From: ${props.email.from_name} <${props.email.from_email}>`,
        `Date: ${formatDate(props.email.date)}`,
        `Message-ID: ${props.email.message_id}`,
    ];

    if (props.email.to && props.email.to.length) {
        const toStr = props.email.to
            .map((r) =>
                typeof r === "string" ? r : `${r.name || ""} <${r.email}>`,
            )
            .join(", ");
        lines.push(`To: ${toStr}`);
    }

    if (props.email.cc && props.email.cc.length) {
        const ccStr = props.email.cc
            .map((r) =>
                typeof r === "string" ? r : `${r.name || ""} <${r.email}>`,
            )
            .join(", ");
        lines.push(`Cc: ${ccStr}`);
    }

    // Add Auth Info
    if (authInfo.value.spf || authInfo.value.dkim || authInfo.value.dmarc) {
        lines.push("");
        lines.push("Authentication Results:");
        if (authInfo.value.spf)
            lines.push(
                `SPF: ${authInfo.value.spf.toUpperCase()} ${authInfo.value.spfDetails ? "(" + authInfo.value.spfDetails + ")" : ""}`,
            );
        if (authInfo.value.dkim)
            lines.push(
                `DKIM: ${authInfo.value.dkim.toUpperCase()} ${authInfo.value.dkimDetails ? "(" + authInfo.value.dkimDetails + ")" : ""}`,
            );
        if (authInfo.value.dmarc)
            lines.push(`DMARC: ${authInfo.value.dmarc.toUpperCase()}`);
    }

    copyToClipboard(lines.join("\n"));
}

// External Link Warning
const showLinkWarning = ref(false);
const pendingLink = ref("");

function isAllowedNavigationUrl(rawUrl: string): boolean {
    try {
        const parsed = new URL(rawUrl, window.location.origin);
        return parsed.protocol === "http:" || parsed.protocol === "https:";
    } catch {
        return false;
    }
}

function cleanupShadowRuntime() {
    if (shadowResizeObserver.value) {
        shadowResizeObserver.value.disconnect();
        shadowResizeObserver.value = null;
    }

    if (shadowRoot.value) {
        (shadowRoot.value as any).removeEventListener("click", handleLinkClick);
        (shadowRoot.value as any).removeEventListener("click", handleImageClick);
    }
}

function cleanupIframeRuntime() {
    if (iframeDocument && iframeClickHandler) {
        iframeDocument.removeEventListener("click", iframeClickHandler);
    }
    iframeDocument = null;
    iframeClickHandler = null;
}

function stripUnsafeEmailMarkup(html: string): string {
    if (!html) return "";

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, "text/html");

    doc.querySelectorAll(
        "script, noscript, link, meta, base, iframe, object, embed, form, input, button, textarea, select, option",
    ).forEach((node) => node.remove());

    doc.querySelectorAll("style").forEach((styleNode) => {
        const cleaned = (styleNode.textContent || "").replace(
            /@import\s+[^;]+;?/gi,
            "",
        );
        if (!cleaned.trim()) {
            styleNode.remove();
            return;
        }
        styleNode.textContent = cleaned;
    });

    doc.querySelectorAll<HTMLElement>("[style]").forEach((node) => {
        const styleValue = node.getAttribute("style");
        if (!styleValue) return;
        const cleaned = styleValue.replace(/@import\s+[^;]+;?/gi, "");
        if (cleaned.trim()) {
            node.setAttribute("style", cleaned);
        } else {
            node.removeAttribute("style");
        }
    });

    return doc.body.innerHTML;
}

function escapeHtmlForPrint(value: string): string {
    return value
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

// On-Demand Downloading State
const isDownloading = ref<Record<string, boolean>>({});

async function downloadOnDemand(att: EmailAttachment) {
    if (isDownloading.value[att.id]) return;

    isDownloading.value[att.id] = true;
    try {
        const response = await fetch(
            `/api/emails/${props.email.id}/attachments/${att.placeholder_index}/download`,
            {
                method: "POST",
                headers: {
                    "X-CSRF-TOKEN":
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute("content") || "",
                    Accept: "application/json",
                },
            },
        );

        if (!response.ok) throw new Error("Download failed");

        const data = await response.json();

        // Update the attachment in the local list with the real media data
        if (data.attachment) {
            Object.assign(att, data.attachment);
            // Optionally auto-open or just show success state?
            // For now, it will just update UI to show as downloaded
        }
    } catch (e) {
        console.error("Failed to download attachment", e);
        // Toast notification would be good here
    } finally {
        isDownloading.value[att.id] = false;
    }
}

// On-Demand Body Fetching
const loadingBody = ref(false);

async function checkAndFetchBody() {
    if (!props.email) return;

    if (!props.email.body_html && !props.email.body_plain) {
        loadingBody.value = true;
        try {
            const data = await store.fetchEmailBody(props.email.id);
            if (data) {
                // Update local object to trigger reactivity in this component
                // Use Object.assign to merge all new properties (attachments, flags, etc.)
                Object.assign(props.email, data);
            }
        } catch (e) {
            console.error("Failed to fetch email body", e);
        } finally {
            loadingBody.value = false;
        }
    } else {
        loadingBody.value = false;
    }
}

// --- Syncing Placeholder ---
const SYNCING_PLACEHOLDER =
    'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 200 100"%3E%3Crect fill="%23f1f5f9" width="200" height="100"/%3E%3Ctext x="50%25" y="45%25" dominant-baseline="middle" text-anchor="middle" fill="%2394a3b8" font-family="sans-serif" font-size="12" font-weight="500"%3ESyncing image...%3C/text%3E%3Ctext x="50%25" y="65%25" dominant-baseline="middle" text-anchor="middle" fill="%23cbd5e1" font-family="sans-serif" font-size="10"%3EIt will appear shortly.%3C/text%3E%3C/svg%3E';

// Reset image state when email changes
watch(
    () => props.email?.id,
    () => {
        showImages.value = isDev && devDefaultShowExternalImages.value;
        hasBlockedImages.value = false;
        isBlockedImagesDismissed.value = false;
        selectedAttachments.value.clear();
        checkAndFetchBody();
    },
    { immediate: true },
);

const visibleAttachments = computed(() => {
    if (!props.email?.attachments) return [];
    return props.email.attachments.filter((att: any) => !att.is_inline);
});

// Check if any attachments are still in cloud (placeholders)
// const hasPlaceholderAttachments = computed(() => {
//     if (!visibleAttachments.value.length) return false;
//     return visibleAttachments.value.some(
//         (att: any) => att.is_downloaded === false,
//     );
// });

// Check if all selected attachments are downloaded
// const allSelectedDownloaded = computed(() => {
//     if (selectedAttachments.value.size === 0) return true;
//     return Array.from(selectedAttachments.value).every((id) => {
//         const att = visibleAttachments.value.find((a: any) => a.id === id);
//         return (att as any)?.is_downloaded !== false;
//     });
// });

const sanitizedBody = computed(() => {
    if (!props.email?.body_html) return "";

    const sanitizedInput = stripUnsafeEmailMarkup(props.email.body_html);

    // Step 1: Replace cid: references with actual attachment URLs (Fallback for existing emails)
    let html = sanitizedInput;
    if (props.email.attachments?.length) {
        props.email.attachments.forEach((att) => {
            if (att.content_id && att.url) {
                const cleanCid = att.content_id.replace(/[<>]/g, "");
                // Escape for Regex
                const escapedCid = cleanCid.replace(
                    /[.*+?^${}()|[\]\\]/g,
                    "\\$&",
                );
                const escapedFullCid = att.content_id.replace(
                    /[.*+?^${}()|[\]\\]/g,
                    "\\$&",
                );

                // Matches src="cid:...", src='cid:...', src=\"cid:...\", or src=cid:...
                // Handles optional brackets and various quote types
                const pattern = new RegExp(
                    `src\\s*=\\s*[\\\\"'\\s]*cid\\s*:\\s*(?:&lt;|<)?(${escapedCid}|${escapedFullCid}|${encodeURIComponent(cleanCid)})(?:&gt;|>)?([\\\\"'\\s]*)`,
                    "gi",
                );
                html = html.replace(pattern, `src="${att.url}"$2`);

                // Also handle background-image: url('cid:...')
                const bgPattern = new RegExp(
                    `url\\s*\\(\\s*[\\\\"'\\s]*cid\\s*:\\s*(?:&lt;|<)?(${escapedCid}|${escapedFullCid}|${encodeURIComponent(cleanCid)})(?:&gt;|>)?([\\\\"'\\s]*)\\)`,
                    "gi",
                );
                html = html.replace(bgPattern, `url("${att.url}")`);
            }
        });
    }

    // Step 1.5: Protect against remaining 'cid:' schemes to avoid ERR_UNKNOWN_URL_SCHEME
    // This catches any CIDs that didn't match an attachment
    html = html.replace(
        /src\s*=\s*[\\"' ]*cid:[^\\"' >]+[\\"' ]*/gi,
        (match) => {
            return `src="${SYNCING_PLACEHOLDER}" data-unresolved-cid="true" `;
        },
    );

    // Step 2: Sanitize with DOMPurify
    const clean = sanitizeHtml(
        html,
        {
            USE_PROFILES: { html: true },
            ADD_TAGS: ["img"],
            ADD_ATTR: ["src", "alt", "style", "data-original-src"],
        },
        {
            beforeSanitizeElements: (currentNode) => {
                if (currentNode instanceof HTMLImageElement) {
                    // Support both 'src' and 'data-original-src' (from backend sanitization)
                    let src = currentNode.getAttribute("src") || "";
                    const originalSrc =
                        currentNode.getAttribute("data-original-src");

                    if (!src && originalSrc) {
                        src = originalSrc;
                    }

                    // Determine if source is external
                    const isExternal =
                        src &&
                        !src.startsWith("data:") &&
                        !src.startsWith("blob:") &&
                        !src.startsWith("cid:") &&
                        !src.startsWith(window.location.origin) &&
                        !src.startsWith("/") &&
                        !src.includes("/storage/") &&
                        !src.includes("/api/media/");

                    if (isExternal) {
                        if (showImages.value) {
                            // User clicked "Show Images" - restore the source
                            currentNode.setAttribute("src", src);
                            currentNode.removeAttribute("data-original-src");
                        } else {
                            // Block external images - show placeholder
                            currentNode.setAttribute("data-blocked-src", src);
                            currentNode.setAttribute(
                                "src",
                                'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 200 100"%3E%3Crect fill="%23e5e7eb" width="200" height="100"/%3E%3Ctext x="50%25" y="50%25" dominant-baseline="middle" text-anchor="middle" fill="%236b7280" font-family="sans-serif" font-size="12"%3EImage blocked%3C/text%3E%3C/svg%3E',
                            );
                            currentNode.setAttribute("data-blocked", "true");
                            currentNode.style.maxWidth = "200px";
                            currentNode.style.border = "1px dashed #d1d5db";
                            currentNode.style.borderRadius = "4px";
                        }
                    }

                    // Handling Internal CID Images not yet ready
                    if (src.includes("/api/media/")) {
                        const mediaId = src.split("/").pop();
                        const attachment = props.email.attachments?.find(
                            (a: any) => String(a.id) === String(mediaId),
                        );

                        if (attachment && attachment.is_ready === false) {
                            currentNode.setAttribute("data-original-src", src);
                            currentNode.setAttribute(
                                "src",
                                SYNCING_PLACEHOLDER,
                            );
                            currentNode.setAttribute("data-is-syncing", "true");
                        }
                    }
                }
                return currentNode;
            },
        },
    );

    return clean;
});

/**
 * Lenient Sanitization for Iframe Rendering
 * This pass preserves <style> tags and @media queries to ensure email layouts
 * are preserved, but remains safe because the Iframe is locked in a 
 * unique-origin sandbox (without allow-same-origin).
 */
const sanitizedRawBody = computed(() => {
    // Fallback to body_html if body_raw is missing
    const rawContent = props.email?.body_raw || props.email?.body_html;
    if (!rawContent) return "";

    // Base cleanup of absolutely dangerous markers before DOMPurify
    let html = stripUnsafeEmailMarkup(rawContent);

    // Replace CID images with actual URLs (reusing same logic as sanitizedBody)
    if (props.email.attachments?.length) {
        props.email.attachments.forEach((att) => {
            if (att.content_id && att.url) {
                const cleanCid = att.content_id.replace(/[<>]/g, "");
                const escapedCid = cleanCid.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
                const escapedFullCid = att.content_id.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
                
                const pattern = new RegExp(
                    `src\\s*=\\s*[\\\\"'\\s]*cid\\s*:\\s*(?:&lt;|<)?(${escapedCid}|${escapedFullCid}|${encodeURIComponent(cleanCid)})(?:&gt;|>)?([\\\\"'\\s]*)`,
                    "gi",
                );
                html = html.replace(pattern, `src="${att.url}"$2`);

                const bgPattern = new RegExp(
                    `url\\s*\\(\\s*[\\\\"'\\s]*cid\\s*:\\s*(?:&lt;|<)?(${escapedCid}|${escapedFullCid}|${encodeURIComponent(cleanCid)})(?:&gt;|>)?([\\\\"'\\s]*)\\)`,
                    "gi",
                );
                html = html.replace(bgPattern, `url("${att.url}")`);
            }
        });
    }

    // Protection against remaining CIDs
    html = html.replace(/src\s*=\s*[\\"' ]*cid:[^\\"' >]+[\\"' ]*/gi, `src="${SYNCING_PLACEHOLDER}" data-unresolved-cid="true" `);

    // Lenient DOMPurify Pass
    const clean = sanitizeHtml(
        html,
        {
            // USE_PROFILES: { html: true } usually strips <style>
            // We instead manually define what's allowed to be as lenient as possible for layout
            // using ALLOWED_TAGS directly to override the default whitelist in sanitizeHtml
            ALLOWED_TAGS: [
                "style", "img", "meta", "base", "p", "br", "strong", "em", "u", "s", "del", "ins",
                "a", "ul", "ol", "li", "blockquote", "code", "pre",
                "h1", "h2", "h3", "h4", "h5", "h6",
                "table", "thead", "tbody", "tr", "th", "td", "caption", "colgroup", "col",
                "span", "div", "hr", "body", "html", "head"
            ],
            ALLOWED_ATTR: [
                "src", "alt", "style", "data-original-src", "width", "height", "border", 
                "cellpadding", "cellspacing", "colspan", "rowspan", "bgcolor", "href", 
                "title", "target", "rel", "class", "id", "name"
            ],
            // FORCE_BODY ensures we get a payload that can be injected into iframe
            FORCE_BODY: true,
        },
        {
            beforeSanitizeElements: (currentNode) => {
                // Same image blocking logic as sanitizedBody
                if (currentNode instanceof HTMLImageElement) {
                    let src = currentNode.getAttribute("src") || "";
                    const originalSrc = currentNode.getAttribute("data-original-src");
                    if (!src && originalSrc) src = originalSrc;

                    const isExternal = src && !src.startsWith("data:") && !src.startsWith("blob:") && 
                                      !src.startsWith("cid:") && !src.startsWith(window.location.origin) && 
                                      !src.startsWith("/") && !src.includes("/storage/") && !src.includes("/api/media/");

                    if (isExternal) {
                        if (showImages.value) {
                            currentNode.setAttribute("src", src);
                            currentNode.removeAttribute("data-original-src");
                        } else {
                            currentNode.setAttribute("data-blocked-src", src);
                            currentNode.setAttribute("src", 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 200 100"%3E%3Crect fill="%23e5e7eb" width="200" height="100"/%3E%3Ctext x="50%25" y="50%25" dominant-baseline="middle" text-anchor="middle" fill="%236b7280" font-family="sans-serif" font-size="12"%3EImage blocked%3C/text%3E%3C/svg%3E');
                            currentNode.setAttribute("data-blocked", "true");
                            currentNode.style.maxWidth = "200px";
                        }
                    }
                }
                return currentNode;
            },
        },
    );

    return clean;
});

// We need to update `hasBlockedImages` separately to avoid computed side-effects
// Image Analysis
const imageAnalysis = computed(() => {
    if (!props.email?.body_html) return { hasCid: false, hasExternal: false };

    const html = props.email.body_html;
    // Check for CID images in <img> tags
    const hasCid = /<img[^>]+src=["']cid:/i.test(html);

    // Check for external images in <img> tags (http/https or proto-relative)
    // We target only img tags and ignore data: / blob: / cid: paths
    // We also exclude local origin URLs to avoid false positives
    const hasExternal =
        /<img[^>]+src=["'](https?:\/\/|\/\/)/i.test(html) &&
        !html.includes('src="' + window.location.origin);

    return { hasCid, hasExternal };
});

watch(
    [() => props.email?.id, () => imageAnalysis.value, () => showImages.value],
    () => {
        // We only block if there are EXTERNAL images and the user hasn't opted to show them
        if (imageAnalysis.value.hasExternal && !showImages.value) {
            hasBlockedImages.value = true;
        } else {
            hasBlockedImages.value = false;
        }
    },
    { immediate: true },
);

const isTrustedSource = computed(() => {
    // For now, trust the user's domain and common providers, or just return true to unblock UI
    if (!props.email?.from_email) return false;
    const trustedDomains = [
        "link-technologies.info",
        "gmail.com",
        "outlook.com",
        "hotmail.com",
    ];
    const emailDomain = props.email.from_email.split("@")[1];
    return trustedDomains.includes(emailDomain);
});

// Untrusted Source Dismissal
const isUntrustedDismissed = ref(false);

function checkDismissedState() {
    if (!props.email?.id) return;
    const key = `untrusted_dismissed_${props.email.id}`;
    isUntrustedDismissed.value = localStorage.getItem(key) === "true";
}

function dismissUntrusted() {
    if (!props.email?.id) return;
    const key = `untrusted_dismissed_${props.email.id}`;
    localStorage.setItem(key, "true");
    isUntrustedDismissed.value = true;
}

watch(() => props.email?.id, checkDismissedState, { immediate: true });

// --- Shadow DOM Injection ---
watch(
    [
        () => sanitizedBody.value,
        () => shadowHost.value,
        () => showImages.value,
        () => useIframeRenderer.value,
        () => disableScaleToFit.value,
    ],
    async ([html, host, _showImages, useIframe], _, onCleanup) => {
        cleanupShadowRuntime();

        onCleanup(() => {
            cleanupShadowRuntime();
        });

        if (!useShadowRenderer.value || !host || !html) return;

        if (shadowRoot.value && shadowRoot.value.host !== host) {
            shadowRoot.value = null;
        }

        // Initialize Shadow DOM if not already done
        if (!shadowRoot.value) {
            shadowRoot.value = host.attachShadow({ mode: "open" });
        }

        // Reset Styles & Default Styles for Email Content (Force Light Theme like Gmail)
        const style = `
            :host {
                display: block !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
                color: #1f2937 !important; /* Force dark text */
                background-color: #ffffff !important; /* Force white background */
                line-height: 1.5 !important;
                text-align: left !important;

                /* Layout Isolation */
                width: 100% !important;
                height: 100% !important;
                overflow: hidden !important; /* Hide overflow - we scale to fit */
                position: relative !important;

                box-sizing: border-box !important;
            }
            #email-wrapper {
                width: 100%;
                height: 100%;
                overflow: hidden; /* Scroll handled by scaling or not needed */
                position: relative;
            }
            #email-body {
                box-sizing: border-box !important;
                padding: 24px !important;

                /* Natural width to allow measuring */
                width: fit-content !important;
                min-width: 100% !important;
                transform-origin: 0 0; /* Scale from top-left */

                color: #1f2937 !important;
                background-color: #ffffff !important;
                position: relative !important;
            }
            /* Reset all colors to inherit from our host unless explicitly set in the style tag */
            * {
                color: inherit;
                overflow-wrap: break-word;
                word-wrap: break-word;
            }
            #email-body * {
                box-sizing: border-box !important;
            }

            /* Images */
            img, video, iframe, svg { max-width: 100% !important; height: auto !important; }

            /* Tables: Allow natural width */
            table {
                /* No forced width */
            }

            /* Long URLs - Keep break-all */
            a {
                color: #2563eb !important;
                text-decoration: underline !important;
                word-break: break-all !important;
            }

            blockquote { margin: 1em 0; border-left: 4px solid #e5e7eb; padding-left: 1em; color: #6b7280 !important; }
            pre { background: #f3f4f6; padding: 1em; overflow-x: auto; border-radius: 0.5em; color: #1f2937 !important; }
            p { margin-bottom: 1em; }
        `;

        // Content is already CID-resolved in sanitizedBody computed property
        const htmlWithCids = html;

        // Inject content
        if (shadowRoot.value) {
            // Wrapper needed for scrolling/clipping reference
            shadowRoot.value.innerHTML = `<style>${style}</style><div id="email-wrapper"><div id="email-body">${htmlWithCids}</div></div>`;

            // Adjust height to fit content
            nextTick(() => {
                const wrapper =
                    shadowRoot.value?.getElementById("email-wrapper");
                const body = shadowRoot.value?.getElementById("email-body");

                if (!wrapper || !body) return;

                const resizeObserver = new ResizeObserver(() => {
                    if (!wrapper || !body) return;

                    // 1. Reset to measure natural dimensions
                    body.style.transform = "none";
                    body.style.width = "fit-content";
                    body.style.marginBottom = "0";
                    body.style.marginRight = "0";

                    const containerWidth = wrapper.clientWidth;
                    const contentWidth = body.scrollWidth;
                    const contentHeight = body.scrollHeight;

                    // 2. Calculate Scale
                    if (!disableScaleToFit.value && contentWidth > containerWidth) {
                        const scale = containerWidth / contentWidth;

                        // 3. Apply Transform
                        body.style.transform = `scale(${scale})`;
                        body.style.width = `${contentWidth}px`; // Lock width to prevent reflow during transform

                        // 4. Fix Layout Flow (Remove whitespace)
                        // Transform preserves original layout space. We use negative margins to pull bounds in.
                        const vSpaceToRemove = contentHeight * (1 - scale);
                        const hSpaceToRemove = contentWidth * (1 - scale);

                        body.style.marginBottom = `-${vSpaceToRemove}px`;
                        body.style.marginRight = `-${hSpaceToRemove}px`;

                        // Ensure wrapper scrolls vertically if needed
                        wrapper.style.overflowY = "auto";
                        wrapper.style.overflowX = "hidden";
                    } else {
                        // Fit naturally
                        body.style.width = "100%";
                        body.style.transform = "none";
                        body.style.marginBottom = "0";
                        body.style.marginRight = "0";
                        wrapper.style.overflowY = "auto";
                        wrapper.style.overflowX =
                            disableScaleToFit.value && contentWidth > containerWidth
                                ? "auto"
                                : "hidden";
                    }
                });

                resizeObserver.observe(wrapper);
                shadowResizeObserver.value = resizeObserver;
            });

            // Make all links open in new tab (safety fallback)
            shadowRoot.value?.querySelectorAll("a").forEach((link) => {
                link.setAttribute("rel", "noopener noreferrer");
            });

            // Add click listener for external link warning
            (shadowRoot.value as any)?.addEventListener(
                "click",
                handleLinkClick,
            );

            // Add click listener for Image Preview (MediaViewer)
            (shadowRoot.value as any)?.addEventListener(
                "click",
                handleImageClick,
            );

            // Add error listener for Images (Retry Loop)
            shadowRoot.value?.querySelectorAll("img").forEach(async (img) => {
                const src = img.getAttribute("src");
                if (
                    src &&
                    (src.includes("/api/media/") ||
                        img.hasAttribute("data-is-syncing"))
                ) {
                    // Apply caching if not a data URI
                    if (src.startsWith("http") || src.startsWith("/")) {
                        const cachedUrl = await getCachedImage(src);
                        if (cachedUrl !== src) {
                            img.src = cachedUrl;
                        }
                    }

                    setupImageSyncRetry(img);
                }
            });
        }
    },
    { immediate: true },
);

function buildIframePreviewDocument(html: string): string {
    return `<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <style>
        html, body {
            margin: 0;
            padding: 0;
            background: #ffffff;
            color: #1f2937;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.5;
        }
        #email-body {
            padding: 24px;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }
        img, video, iframe, svg {
            max-width: 100% !important;
            height: auto !important;
        }
        a {
            color: #2563eb !important;
            text-decoration: underline !important;
            word-break: break-all !important;
        }
        blockquote {
            margin: 1em 0;
            border-left: 4px solid #e5e7eb;
            padding-left: 1em;
            color: #6b7280 !important;
        }
        pre {
            background: #f3f4f6;
            padding: 1em;
            overflow-x: auto;
            border-radius: 0.5em;
            color: #1f2937 !important;
        }
        p {
            margin-bottom: 1em;
        }
    </style>
</head>
<body>
    <div id="email-body">${html}</div>
</body>
</html>`;
}

watch(
    [
        () => sanitizedRawBody.value,
        () => iframeHost.value,
        () => useIframeRenderer.value,
    ],
    async ([html, frame, useIframe], _, onCleanup) => {
        cleanupIframeRuntime();

        if (!useIframeRenderer.value || !frame || !html) return;

        const onLoad = () => {
            const doc = frame.contentDocument;
            if (!doc) return;

            iframeDocument = doc;

            doc.querySelectorAll("a").forEach((link) => {
                link.setAttribute("rel", "noopener noreferrer");
            });

            iframeClickHandler = (event: MouseEvent) => {
                const target = event.target as HTMLElement | null;
                if (!target) return;

                const link = target.closest("a");
                if (link) {
                    const href = link.getAttribute("href");
                    if (
                        href &&
                        !href.startsWith("#") &&
                        !href.startsWith("mailto:") &&
                        !href.startsWith("tel:")
                    ) {
                        event.preventDefault();
                        pendingLink.value = href;
                        showLinkWarning.value = true;
                    }
                    return;
                }

                if (target.tagName === "IMG") {
                    openMediaViewer(
                        getPreviewableImages(doc),
                        target as HTMLImageElement,
                    );
                }
            };
            doc.addEventListener("click", iframeClickHandler);

            doc.querySelectorAll("img").forEach(async (imgNode) => {
                const img = imgNode as HTMLImageElement;
                const src = img.getAttribute("src");
                if (!src) return;

                if (
                    src.includes("/api/media/") ||
                    img.hasAttribute("data-is-syncing")
                ) {
                    if (src.startsWith("http") || src.startsWith("/")) {
                        const cachedUrl = await getCachedImage(src);
                        if (cachedUrl !== src) {
                            img.src = cachedUrl;
                        }
                    }
                    setupImageSyncRetry(img);
                }
            });
        };

        frame.addEventListener("load", onLoad, { once: true });
        frame.srcdoc = buildIframePreviewDocument(html);

        onCleanup(() => {
            frame.removeEventListener("load", onLoad);
            cleanupIframeRuntime();
        });
    },
    { immediate: true },
);

function setupImageSyncRetry(img: HTMLImageElement) {
    let retries = 0;
    const maxRetries = 10;
    const interval = 5000; // 5 seconds
    const originalSrc = img.getAttribute("data-original-src") || img.src;

    // Security Check: Validate generic URL structure to prevent javascript: or vbscript: execution
    // We allow http/https, relative paths, and data URIs (though data URIs are usually blocked elsewhere)
    if (
        !originalSrc ||
        /^\s*(javascript|vbscript|data):/i.test(originalSrc) ||
        !/^(https?:\/\/|\/|data:image\/|blob:)/i.test(originalSrc)
    ) {
        console.warn(
            "[EmailPreview] Blocked unsafe image source retry:",
            originalSrc,
        );
        return;
    }

    const attemptReload = () => {
        if (retries >= maxRetries) {
            console.warn(
                `[EmailPreview] Image retry failed after ${maxRetries} attempts:`,
                originalSrc,
            );
            return;
        }

        const testImg = new Image();
        testImg.onload = () => {
            img.src = originalSrc;
            img.removeAttribute("data-is-syncing");
            img.removeAttribute("data-original-src");
            img.style.maxWidth = ""; // Reset placeholder constraints if any
            img.style.border = "";
        };
        testImg.onerror = () => {
            retries++;
            window.setTimeout(attemptReload, interval);
        };

        // Append unique param to bypass cache
        testImg.src =
            originalSrc +
            (originalSrc.includes("?") ? "&" : "?") +
            "retry=" +
            retries;
    };

    if (
        img.src === SYNCING_PLACEHOLDER ||
        img.getAttribute("data-is-syncing") === "true"
    ) {
        window.setTimeout(attemptReload, interval);
    } else {
        img.onerror = () => {
            if (img.src !== SYNCING_PLACEHOLDER) {
                img.setAttribute("data-original-src", img.src);
                img.src = SYNCING_PLACEHOLDER;
                img.setAttribute("data-is-syncing", "true");
                window.setTimeout(attemptReload, interval);
            }
        };
    }
}

function getPreviewableImages(scope: ParentNode | null): HTMLImageElement[] {
    if (!scope) return [];

    return Array.from(scope.querySelectorAll("img")).filter(
        (img) =>
            img.width >= 20 &&
            img.height >= 20 &&
            img.getAttribute("data-blocked") !== "true",
    );
}

function openMediaViewer(
    images: HTMLImageElement[],
    selectedImage: HTMLImageElement,
) {
    if (!images.length) return;
    if (selectedImage.getAttribute("data-blocked") === "true") return;
    if (selectedImage.width < 20 || selectedImage.height < 20) return;

    const index = images.indexOf(selectedImage);
    if (index < 0) return;

    const media = images.map((img) => ({
        src: img.src,
        download: img.src,
        type: "image",
    }));

    window.dispatchEvent(
        new CustomEvent("media-viewer:open", {
            detail: { media, index },
        }),
    );
}

function handleImageClick(event: MouseEvent) {
    const target = event.target as HTMLElement;
    if (target.tagName !== "IMG") return;

    openMediaViewer(
        getPreviewableImages(shadowRoot.value),
        target as HTMLImageElement,
    );
}

function handleLinkClick(event: MouseEvent) {
    const target = event.target as HTMLElement;
    const link = target.closest("a");
    if (link) {
        const href = link.getAttribute("href");
        // Intercept all external links (zero-trust)
        // We skip anchors and common non-web schemes
        if (
            href &&
            !href.startsWith("#") &&
            !href.startsWith("mailto:") &&
            !href.startsWith("tel:")
        ) {
            event.preventDefault();
            pendingLink.value = href;
            showLinkWarning.value = true;
        }
    }
}

function proceedToLink() {
    const targetUrl = pendingLink.value.trim();
    showLinkWarning.value = false;
    pendingLink.value = "";

    if (!targetUrl) return;
    if (!isAllowedNavigationUrl(targetUrl)) {
        console.warn("[EmailPreview] Blocked non-http(s) navigation target");
        return;
    }

    const opened = window.open(targetUrl, "_blank", "noopener,noreferrer");
    if (opened) {
        opened.opener = null;
    }
}

// Local formatting functions removed in favor of useDate composable

// --- Print ---
// --- Print ---
function printEmail() {
    if (props.isPopup) {
        window.print();
        return;
    }

    const printWindow = window.open("", "_blank");
    if (!printWindow) return;

    const email = props.email;
    const safeSubject = escapeHtmlForPrint(email.subject || "");
    const safeFromName = escapeHtmlForPrint(email.from_name || "");
    const safeFromEmail = escapeHtmlForPrint(email.from_email || "");
    const safeDate = escapeHtmlForPrint(formatDate(email.date));
    const safeTo = escapeHtmlForPrint(
        email.to
            ?.map((t) => (typeof t === "string" ? t : t?.email || ""))
            .filter(Boolean)
            .join(", ") || "",
    );
    const safeBody = email.body_html
        ? sanitizeHtml(stripUnsafeEmailMarkup(email.body_html), {
              ADD_TAGS: ["img", "table", "thead", "tbody", "tr", "th", "td"],
              ADD_ATTR: [
                  "src",
                  "alt",
                  "style",
                  "href",
                  "target",
                  "rel",
                  "colspan",
                  "rowspan",
                  "width",
                  "height",
                  "align",
                  "valign",
              ],
          })
        : `<pre>${escapeHtmlForPrint(email.body_plain || "")}</pre>`;

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>${safeSubject}</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 40px; max-width: 800px; margin: 0 auto; }
                .header { border-bottom: 1px solid #ddd; padding-bottom: 20px; margin-bottom: 20px; }
                .subject { font-size: 24px; font-weight: 600; margin-bottom: 10px; }
                .meta { color: #666; font-size: 14px; }
                .meta div { margin: 4px 0; }
                .body { line-height: 1.6; }
                @media print { body { padding: 20px; } }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="subject">${safeSubject}</div>
                <div class="meta">
                    <div><strong>From:</strong> ${safeFromName} &lt;${safeFromEmail}&gt;</div>
                    <div><strong>Date:</strong> ${safeDate}</div>
                    <div><strong>To:</strong> ${safeTo}</div>
                </div>
            </div>
            <div class="body">${safeBody}</div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// --- Expand to Popup ---
function expandEmail() {
    const width = 900;
    const height = 700;
    const left = (window.screen.width - width) / 2;
    const top = (window.screen.height - height) / 2;

    window.open(
        `/email/popup/${props.email.id}`,
        "_blank",
        `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`,
    );
}

// --- Export as EML ---
function exportAsEml() {
    window.open(`/api/emails/${props.email.id}/export`, "_blank");
}

// --- Animation ---
let animation: any = null;

function runEnterAnimation() {
    if (!props.email) return;
    if (animation) animation.pause();

    const targets = document.querySelectorAll(".preview-animate-item");
    if (targets.length === 0) return;

    animation = animate(targets, {
        opacity: [0, 1],
        translateY: [15, 0],
        delay: stagger(80),
        duration: 350,
        easing: "easeOutQuad",
    });
}

watch(
    () => props.email,
    () => {
        nextTick(() => runEnterAnimation());
    },
);

onMounted(() => {
    if (props.email) {
        nextTick(() => runEnterAnimation());
    }
});

onBeforeUnmount(() => {
    if (animation) animation.pause();
    cleanupShadowRuntime();
    cleanupIframeRuntime();
});
</script>
