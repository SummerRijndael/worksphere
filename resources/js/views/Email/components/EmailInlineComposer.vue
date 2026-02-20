<template>
    <div class="h-full flex flex-col min-h-0">
        <div
            class="flex flex-col h-full max-h-[calc(100vh-110px)] bg-(--surface-primary) overflow-hidden"
            @drop.prevent="handleDrop"
            @dragover.prevent
        >
            <!-- Compact Header -->
            <div
                class="p-4 border-b border-(--border-default) bg-linear-to-r from-(--surface-secondary) to-transparent"
            >
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <div class="p-1.5 rounded-lg" :class="modeStyles.bg">
                            <component
                                :is="modeIcon"
                                :class="['w-4 h-4', modeStyles.text]"
                            />
                        </div>
                        <span
                            class="text-sm font-semibold text-(--text-primary)"
                            >{{ modeLabel }}</span
                        >
                    </div>

                    <!-- Action Buttons (Moved to Header) -->
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-(--text-muted) tabular-nums"
                            >{{ characterCount }} chars</span
                        >
                        <div class="h-4 w-px bg-(--border-default)"></div>

                        <!-- Saving Indicator -->
                        <span
                            v-if="savingDraft"
                            class="text-xs text-(--text-muted) animate-pulse"
                            >Saving...</span
                        >
                        <span
                            v-else-if="lastSavedAt"
                            class="text-xs text-(--text-muted)"
                            >Saved {{ formatTime(lastSavedAt) }}</span
                        >

                        <button
                            @click="saveDraft()"
                            class="p-2 rounded-lg text-(--text-muted) hover:text-(--text-primary) hover:bg-(--surface-tertiary) transition-colors"
                            title="Save Draft"
                        >
                            <SaveIcon class="w-4 h-4" />
                        </button>

                        <button
                            @click="handleDiscardClick"
                            class="p-2 rounded-lg text-(--text-muted) hover:text-error hover:bg-error/10 transition-colors"
                            title="Discard"
                        >
                            <TrashIcon class="w-4 h-4" />
                        </button>
                        <button
                            @click="handleSend"
                            :disabled="isSending"
                            class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold text-white bg-(--interactive-primary) hover:bg-(--interactive-primary-hover) shadow-lg shadow-(--interactive-primary)/25 transition-all hover:scale-[1.02] active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-none"
                        >
                            <span v-if="isSending">Sending...</span>
                            <span v-else>Send</span>
                            <SendIcon v-if="!isSending" class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>

                <!-- Email Fields -->
                <div class="space-y-2">
                    <!-- From Field -->
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-(--text-muted) w-12 shrink-0"
                            >From</span
                        >
                        <div class="flex-1 relative">
                            <Dropdown
                                :items="accountItems"
                                align="start"
                                class="w-full"
                            >
                                <template #trigger>
                                    <button
                                        class="flex items-center gap-2 w-full px-3 py-1.5 text-sm border border-(--border-default) rounded-lg bg-(--surface-elevated) hover:bg-(--surface-secondary) transition-colors text-left"
                                    >
                                        <span
                                            v-if="selectedAccount"
                                            class="flex-1 truncate"
                                        >
                                            {{ selectedAccount.name }} &lt;{{
                                                selectedAccount.email
                                            }}&gt;
                                        </span>
                                        <span v-else class="text-(--text-muted)"
                                            >Select account...</span
                                        >
                                        <ChevronDownIcon
                                            class="w-4 h-4 text-(--text-muted)"
                                        />
                                    </button>
                                </template>
                            </Dropdown>
                        </div>
                    </div>

                    <!-- To Field -->
                    <div class="flex items-start gap-2">
                        <span
                            class="text-xs text-(--text-muted) w-12 pt-2.5 shrink-0"
                            >To</span
                        >
                        <div class="flex-1">
                            <EmailTagInput
                                v-model="toEmails"
                                placeholder="Recipients"
                            />
                        </div>
                        <div class="flex items-center gap-1 pt-1.5">
                            <button
                                @click="showCc = !showCc"
                                class="px-2 py-1 text-xs rounded-md transition-colors"
                                :class="
                                    showCc
                                        ? 'bg-(--interactive-primary)/10 text-(--interactive-primary)'
                                        : 'text-(--text-muted) hover:text-(--text-primary) hover:bg-(--surface-tertiary)'
                                "
                            >
                                Cc
                            </button>
                            <button
                                @click="showBcc = !showBcc"
                                class="px-2 py-1 text-xs rounded-md transition-colors"
                                :class="
                                    showBcc
                                        ? 'bg-(--interactive-primary)/10 text-(--interactive-primary)'
                                        : 'text-(--text-muted) hover:text-(--text-primary) hover:bg-(--surface-tertiary)'
                                "
                            >
                                Bcc
                            </button>
                        </div>
                    </div>

                    <!-- CC Field -->
                    <Transition name="slide-fade">
                        <div v-if="showCc" class="flex items-start gap-2">
                            <span
                                class="text-xs text-(--text-muted) w-12 pt-2.5 shrink-0"
                                >Cc</span
                            >
                            <EmailTagInput
                                v-model="ccEmails"
                                placeholder="CC recipients"
                                class="flex-1"
                            />
                        </div>
                    </Transition>

                    <!-- BCC Field -->
                    <Transition name="slide-fade">
                        <div v-if="showBcc" class="flex items-start gap-2">
                            <span
                                class="text-xs text-(--text-muted) w-12 pt-2.5 shrink-0"
                                >Bcc</span
                            >
                            <EmailTagInput
                                v-model="bccEmails"
                                placeholder="BCC recipients"
                                class="flex-1"
                            />
                        </div>
                    </Transition>

                    <!-- Subject Field (always shown) -->
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-(--text-muted) w-12 shrink-0"
                            >Subject</span
                        >
                        <input
                            type="text"
                            v-model="subject"
                            @blur="saveDraft(true)"
                            class="flex-1 bg-(--surface-elevated) border border-(--border-default) rounded-lg px-3 py-2 text-sm text-(--text-primary) placeholder-(--text-muted) focus:outline-none focus:ring-2 focus:ring-(--interactive-primary)/50 focus:border-(--interactive-primary) transition-all"
                            placeholder="Subject"
                        />
                    </div>
                </div>
            </div>

            <!-- Editor -->
            <div class="flex-1 p-4 overflow-y-auto min-h-0">
                <EditorContent
                    :editor="editor"
                    class="prose prose-sm max-w-none focus:outline-none min-h-[200px]"
                />

                <!-- Attachments List -->
                <div
                    v-if="attachments.length > 0"
                    class="flex flex-wrap gap-2 p-2 relative z-10"
                >
                    <div
                        v-for="(file, index) in attachments"
                        :key="index"
                        class="flex items-center gap-2 px-3 py-1.5 bg-(--surface-tertiary) rounded-full text-xs border border-(--border-default)"
                    >
                        <span class="truncate max-w-[200px]">{{
                            file.name
                        }}</span>
                        <span class="text-(--text-muted)"
                            >({{ formatFileSize(file.size) }})</span
                        >
                        <button
                            @click="removeAttachment(index)"
                            class="p-0.5 rounded hover:bg-(--surface-secondary) text-(--text-secondary)"
                        >
                            <XIcon class="w-3 h-3" />
                        </button>
                    </div>
                </div>
                <!-- Signature Preview -->
                <div
                    v-if="selectedSignature?.content"
                    class="mt-4 pt-4 border-t border-dashed border-(--border-default)"
                >
                    <div class="text-xs text-(--text-muted) mb-2">
                        Signature
                    </div>
                    <div
                        class="text-sm text-(--text-secondary)"
                        v-html="selectedSignature.content"
                    ></div>
                </div>

                <!-- Quoted Content for Reply/Forward -->
                <div
                    v-if="
                        replyTo &&
                        (actualMode === 'reply' || actualMode === 'forward')
                    "
                    class="mt-4 pt-4 border-t border-dashed border-(--border-default)"
                >
                    <div class="text-xs text-(--text-muted) mb-2">
                        {{
                            actualMode === "reply" ? "On" : "Forwarded message"
                        }}
                        {{ formatDate(replyTo.date) }},
                        {{ replyTo.from_name || replyTo.from_email }} wrote:
                    </div>
                    <div
                        class="pl-3 border-l-2 border-(--border-default) text-sm text-(--text-secondary)"
                        v-html="replyTo.body_html || replyTo.body_plain"
                    ></div>
                </div>
            </div>

            <!-- Action Bar -->
            <div
                class="p-2 border-t border-(--border-default) bg-(--surface-secondary)"
            >
                <div class="flex items-center flex-wrap gap-2.5 px-1 pb-1">
                    <!-- Group 1: Core Actions -->
                    <div
                        class="flex items-center bg-(--surface-tertiary)/40 rounded-xl p-0.5 shadow-sm"
                    >
                        <input
                            ref="fileInput"
                            type="file"
                            multiple
                            class="hidden"
                            @change="handleFileSelect"
                        />
                        <button
                            @click="fileInput?.click()"
                            class="p-2 rounded-lg text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) transition-colors shrink-0"
                            title="Attach file"
                        >
                            <PaperclipIcon class="w-4 h-4" />
                        </button>

                        <!-- Template Selector -->
                        <Dropdown :items="templateItems" align="start">
                            <template #trigger>
                                <button
                                    class="p-2 rounded-lg text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) transition-colors shrink-0"
                                    title="Insert template"
                                >
                                    <FileTextIcon class="w-4 h-4" />
                                </button>
                            </template>
                        </Dropdown>

                        <!-- Signature Selector -->
                        <Dropdown :items="signatureItems" align="start">
                            <template #trigger>
                                <button
                                    class="p-2 rounded-lg text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) transition-colors shrink-0"
                                    title="Select signature"
                                >
                                    <PenToolIcon class="w-4 h-4" />
                                </button>
                            </template>
                        </Dropdown>
                    </div>

                    <!-- Group 2: Typography -->
                    <div
                        class="flex items-center bg-(--surface-tertiary)/40 rounded-xl p-0.5 shadow-sm"
                    >
                        <Dropdown
                            align="start"
                            class="shrink-0"
                            :close-on-select="true"
                        >
                            <template #trigger>
                                <button
                                    class="p-2 rounded-lg text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) transition-colors flex items-center gap-1"
                                    title="Font Family"
                                >
                                    <TypeIcon class="w-4 h-4" />
                                    <ChevronDownIcon
                                        class="w-3 h-3 opacity-50"
                                    />
                                </button>
                            </template>
                            <div class="p-1 min-w-[150px]">
                                <button
                                    v-for="font in fontFamilies"
                                    :key="font.value"
                                    @click="setFontFamily(font.value)"
                                    class="w-full text-left px-3 py-2 text-sm rounded-md hover:bg-(--surface-tertiary) transition-colors"
                                    :style="{ fontFamily: font.value }"
                                    :class="
                                        editor?.isActive('textStyle', {
                                            fontFamily: font.value,
                                        })
                                            ? 'text-(--interactive-primary) bg-(--interactive-primary)/5'
                                            : 'text-(--text-primary)'
                                    "
                                >
                                    {{ font.label }}
                                </button>
                            </div>
                        </Dropdown>

                        <Dropdown
                            align="start"
                            class="shrink-0"
                            :close-on-select="true"
                        >
                            <template #trigger>
                                <button
                                    class="p-2 rounded-lg text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) transition-colors flex items-center gap-1"
                                    title="Font Size"
                                >
                                    <BaselineIcon class="w-4 h-4" />
                                    <ChevronDownIcon
                                        class="w-3 h-3 opacity-50"
                                    />
                                </button>
                            </template>
                            <div class="p-1 min-w-[100px]">
                                <button
                                    v-for="size in fontSizes"
                                    :key="size.value"
                                    @click="setFontSize(size.value)"
                                    class="w-full text-left px-3 py-2 text-sm rounded-md hover:bg-(--surface-tertiary) transition-colors"
                                    :class="
                                        editor?.isActive('textStyle', {
                                            fontSize: size.value,
                                        })
                                            ? 'text-(--interactive-primary) bg-(--interactive-primary)/5'
                                            : 'text-(--text-primary)'
                                    "
                                >
                                    <span :style="{ fontSize: size.value }">{{
                                        size.label
                                    }}</span>
                                </button>
                            </div>
                        </Dropdown>
                    </div>

                    <!-- Group 3: Formatting -->
                    <div
                        class="flex items-center bg-(--surface-tertiary)/40 rounded-xl p-0.5 shadow-sm"
                    >
                        <button
                            @click="editor?.chain().focus().toggleBold().run()"
                            :class="[
                                editor?.isActive('bold')
                                    ? 'bg-(--surface-tertiary) text-(--text-primary)'
                                    : 'text-(--text-secondary)',
                            ]"
                            class="p-2 rounded-lg hover:bg-(--surface-tertiary) transition-colors shrink-0"
                            title="Bold"
                        >
                            <BoldIcon class="w-4 h-4" />
                        </button>
                        <button
                            @click="
                                editor?.chain().focus().toggleItalic().run()
                            "
                            :class="[
                                editor?.isActive('italic')
                                    ? 'bg-(--surface-tertiary) text-(--text-primary)'
                                    : 'text-(--text-secondary)',
                            ]"
                            class="p-2 rounded-lg hover:bg-(--surface-tertiary) transition-colors shrink-0"
                            title="Italic"
                        >
                            <ItalicIcon class="w-4 h-4" />
                        </button>
                        <button
                            @click="
                                editor?.chain().focus().toggleUnderline().run()
                            "
                            :class="[
                                editor?.isActive('underline')
                                    ? 'bg-(--surface-tertiary) text-(--text-primary)'
                                    : 'text-(--text-secondary)',
                            ]"
                            class="p-2 rounded-lg hover:bg-(--surface-tertiary) transition-colors shrink-0"
                            title="Underline"
                        >
                            <UnderlineIcon class="w-4 h-4" />
                        </button>
                        <Dropdown
                            align="start"
                            class="shrink-0"
                            :close-on-select="true"
                        >
                            <template #trigger>
                                <button
                                    class="p-2 rounded-lg text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) transition-colors"
                                    title="Text Color"
                                >
                                    <PaletteIcon
                                        class="w-4 h-4"
                                        :style="{
                                            color: editor?.getAttributes(
                                                'textStyle',
                                            ).color,
                                        }"
                                    />
                                </button>
                            </template>
                            <div class="p-2 grid grid-cols-5 gap-1 w-[160px]">
                                <button
                                    v-for="color in colors"
                                    :key="color"
                                    @click="setColor(color)"
                                    class="w-6 h-6 rounded-full border border-(--border-subtle) hover:scale-110 transition-transform"
                                    :style="{ backgroundColor: color }"
                                    :title="color"
                                />
                            </div>
                        </Dropdown>
                    </div>

                    <!-- Group 4: Layout -->
                    <div
                        class="flex items-center bg-(--surface-tertiary)/40 rounded-xl p-0.5 shadow-sm"
                    >
                        <button
                            @click="
                                editor
                                    ?.chain()
                                    .focus()
                                    .setTextAlign('left')
                                    .run()
                            "
                            :class="[
                                editor?.isActive({ textAlign: 'left' })
                                    ? 'bg-(--surface-tertiary) text-(--text-primary)'
                                    : 'text-(--text-secondary)',
                            ]"
                            class="p-2 rounded-lg hover:bg-(--surface-tertiary) transition-colors shrink-0"
                            title="Align Left"
                        >
                            <AlignLeftIcon class="w-4 h-4" />
                        </button>
                        <button
                            @click="
                                editor
                                    ?.chain()
                                    .focus()
                                    .setTextAlign('center')
                                    .run()
                            "
                            :class="[
                                editor?.isActive({ textAlign: 'center' })
                                    ? 'bg-(--surface-tertiary) text-(--text-primary)'
                                    : 'text-(--text-secondary)',
                            ]"
                            class="p-2 rounded-lg hover:bg-(--surface-tertiary) transition-colors shrink-0"
                            title="Align Center"
                        >
                            <AlignCenterIcon class="w-4 h-4" />
                        </button>
                        <button
                            @click="
                                editor
                                    ?.chain()
                                    .focus()
                                    .setTextAlign('right')
                                    .run()
                            "
                            :class="[
                                editor?.isActive({ textAlign: 'right' })
                                    ? 'bg-(--surface-tertiary) text-(--text-primary)'
                                    : 'text-(--text-secondary)',
                            ]"
                            class="p-2 rounded-lg hover:bg-(--surface-tertiary) transition-colors shrink-0"
                            title="Align Right"
                        >
                            <AlignRightIcon class="w-4 h-4" />
                        </button>
                        <button
                            @click="
                                editor?.chain().focus().toggleBulletList().run()
                            "
                            :class="[
                                editor?.isActive('bulletList')
                                    ? 'bg-(--surface-tertiary) text-(--text-primary)'
                                    : 'text-(--text-secondary)',
                            ]"
                            class="p-2 rounded-lg hover:bg-(--surface-tertiary) transition-colors shrink-0"
                            title="Bullet List"
                        >
                            <ListIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Group 5: Inserts -->
                    <div
                        class="flex items-center bg-(--surface-tertiary)/40 rounded-xl p-0.5 shadow-sm"
                    >
                        <button
                            @click="addImage"
                            class="p-2 rounded-lg text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) transition-colors shrink-0"
                            title="Insert Image"
                        >
                            <ImageIcon class="w-4 h-4" />
                        </button>
                        <button
                            @click="setLink"
                            :class="[
                                editor?.isActive('link')
                                    ? 'bg-(--surface-tertiary) text-(--text-primary)'
                                    : 'text-(--text-secondary)',
                            ]"
                            class="p-2 rounded-lg hover:bg-(--surface-tertiary) transition-colors shrink-0"
                            title="Insert Link"
                        >
                            <LinkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Group 6: Intelligence & Premium -->
                    <div
                        class="flex items-center bg-(--accent-primary)/10 rounded-xl p-0.5 shadow-sm border border-(--accent-primary)/20"
                    >
                        <button
                            @click="handleAiAssist"
                            class="p-1.5 rounded-lg text-(--accent-primary) hover:bg-(--surface-active) transition-colors flex items-center gap-1.5 shrink-0 whitespace-nowrap"
                            title="AI Assist (Coming Soon)"
                        >
                            <SparklesIcon class="w-4 h-4" />
                            <span class="text-xs font-bold hidden sm:inline"
                                >Auto-Complete</span
                            >
                        </button>

                        <div
                            class="w-px h-4 bg-(--accent-primary)/20 mx-1"
                        ></div>

                        <button
                            @click="isImportant = !isImportant"
                            :class="[
                                isImportant
                                    ? 'bg-red-100 text-red-600 shadow-sm'
                                    : 'text-(--text-secondary) hover:text-red-500 hover:bg-red-50',
                            ]"
                            class="p-2 rounded-lg transition-all shrink-0"
                            :title="
                                isImportant
                                    ? 'High Priority Enabled'
                                    : 'Mark as High Priority'
                            "
                        >
                            <AlertTriangleIcon class="w-4 h-4" />
                        </button>

                        <Dropdown align="start" :close-on-select="false">
                            <template #trigger>
                                <button
                                    :class="[
                                        scheduledAt
                                            ? 'bg-blue-100 text-blue-600 shadow-sm'
                                            : 'text-(--text-secondary) hover:text-blue-500 hover:bg-blue-50',
                                    ]"
                                    class="p-2 rounded-lg transition-all shrink-0 flex items-center gap-1.5"
                                    title="Schedule Send"
                                >
                                    <AlarmClockIcon class="w-4 h-4" />
                                    <span
                                        v-if="scheduledAt"
                                        class="text-[10px] font-bold"
                                        >Planned</span
                                    >
                                </button>
                            </template>
                            <div class="p-3 min-w-[240px] space-y-3">
                                <div
                                    class="text-sm font-semibold text-(--text-primary)"
                                >
                                    Schedule Send
                                </div>
                                <input
                                    type="datetime-local"
                                    v-model="scheduledAt"
                                    class="w-full bg-(--surface-elevated) border border-(--border-default) rounded-lg px-3 py-2 text-sm text-(--text-primary) focus:ring-2 focus:ring-(--interactive-primary)/30 transition-all outline-none"
                                />
                                <div class="flex justify-between items-center">
                                    <button
                                        @click="scheduledAt = null"
                                        class="text-xs text-red-500 hover:text-red-600 font-bold transition-colors"
                                    >
                                        Clear
                                    </button>
                                    <span
                                        class="text-[10px] text-(--text-muted)"
                                        >Timezone: {{ userTimezone }}</span
                                    >
                                </div>
                            </div>
                        </Dropdown>
                    </div>

                    <!-- Group 7: Settings -->
                    <div
                        class="flex items-center bg-(--surface-tertiary)/40 rounded-xl p-0.5 shadow-sm ml-auto"
                    >
                        <label
                            class="flex items-center gap-2 px-3 py-1.5 cursor-pointer group hover:bg-(--surface-tertiary) rounded-lg transition-all"
                        >
                            <input
                                type="checkbox"
                                v-model="requestReadReceipt"
                                class="h-3.5 w-3.5 rounded border-(--border-default) text-(--interactive-primary) focus:ring-(--interactive-primary)/20 transition-all cursor-pointer"
                            />
                            <span
                                class="text-[11px] font-medium text-(--text-secondary) group-hover:text-(--text-primary) transition-colors select-none"
                                >Receipt</span
                            >
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Discard Confirmation Modal -->
        <Modal
            v-model:open="showDiscardModal"
            title="Discard Draft?"
            description="You have unsaved changes. Would you like to save this email as a draft before closing?"
        >
            <div class="flex flex-col gap-3">
                <div class="text-sm text-(--text-secondary)">
                    This action cannot be undone if you Choose to discard.
                </div>
            </div>
            <template #footer>
                <div class="flex items-center gap-3 w-full">
                    <Button
                        variant="ghost"
                        class="flex-1"
                        @click="showDiscardModal = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="danger"
                        class="flex-1"
                        @click="confirmDiscard"
                    >
                        Discard
                    </Button>
                    <Button
                        variant="primary"
                        class="flex-1"
                        :loading="savingDraft"
                        @click="saveAndClose"
                    >
                        Save Draft
                    </Button>
                </div>
            </template>
        </Modal>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeUnmount, markRaw, onMounted } from "vue";
import { useEditor, EditorContent } from "@tiptap/vue-3";
import StarterKit from "@tiptap/starter-kit";
import Placeholder from "@tiptap/extension-placeholder";
import {
    XIcon,
    SendIcon,
    PaperclipIcon,
    BoldIcon,
    TrashIcon,
    ItalicIcon,
    ListIcon,
    ReplyIcon,
    ForwardIcon,
    PencilIcon,
    FileTextIcon,
    PenToolIcon,
    SparklesIcon,
    ReplyAllIcon,
    ChevronDownIcon,
    CheckIcon,
    TypeIcon,
    PaletteIcon,
    BaselineIcon,
    AlignLeftIcon,
    AlignCenterIcon,
    AlignRightIcon,
    UnderlineIcon,
    ImageIcon,
    LinkIcon,
    SaveIcon,
    AlertTriangleIcon,
    AlarmClockIcon,
} from "lucide-vue-next";
import { useDate } from "@/composables/useDate";

const { formatDate } = useDate();
import { watch } from "vue";
import type { Email } from "@/types/models/email";
import { useEmailStore } from "@/stores/emailStore";
import { useAuthStore } from "@/stores/auth";
import EmailTagInput from "./EmailTagInput.vue";
import Dropdown from "@/components/ui/Dropdown.vue";
import Modal from "@/components/ui/Modal.vue";
import Button from "@/components/ui/Button.vue";
import { useEmailSignatures } from "../composables/useEmailSignatures";
import { useEmailTemplates } from "../composables/useEmailTemplates";
// import {} from "@/services/email-account.service";
import axios from "axios";

// Tiptap Extensions
import TextAlign from "@tiptap/extension-text-align";
import TextStyle from "@tiptap/extension-text-style";
import Color from "@tiptap/extension-color";
import FontFamily from "@tiptap/extension-font-family";
import Underline from "@tiptap/extension-underline";
import Image from "@tiptap/extension-image";
import Link from "@tiptap/extension-link";
import { FontSize } from "@/components/ui/editor/extensions/FontSize";
import { useDebounceFn } from "@vueuse/core";

const props = defineProps<{
    mode: string;
    replyTo?: Email | null;
}>();

const emit = defineEmits<{
    close: [];
    send: [];
}>();

// Composables
const { signatures, selectedSignatureId, getSignatureById, fetchSignatures } =
    useEmailSignatures();
const { templates, getTemplateById, fetchTemplates } = useEmailTemplates();
const store = useEmailStore();

// Account Management
const accounts = ref<any[]>([]);
const selectedAccountId = ref<string | number | null>(null);
const requestReadReceipt = ref(false);
const isImportant = ref(false);
const scheduledAt = ref<string | null>(null);
const draftId = ref<string | null>(null);
const savingDraft = ref(false);
const lastSavedAt = ref<Date | null>(null);
// const autoSaveInterval = ref<any>(null);
// const lastSavedHash = ref<string>("");
const showDiscardModal = ref(false);
const authStore = useAuthStore();
const userTimezone = computed(() => {
    return (
        authStore.user?.preferences?.timezone ||
        Intl.DateTimeFormat().resolvedOptions().timeZone ||
        "UTC"
    );
});

const selectedAccount = computed(() =>
    accounts.value.find((a) => a.id === selectedAccountId.value),
);

const accountItems = computed(() =>
    accounts.value.map((a) => ({
        label: `${a.name} <${a.email}>`,
        icon: a.id === selectedAccountId.value ? CheckIcon : undefined,
        action: () => {
            selectedAccountId.value = a.id;
        },
    })),
);

// Fetch accounts and set default
onMounted(async () => {
    // Start parallel fetches for independent data
    fetchSignatures();
    fetchTemplates();

    try {
        // Use store accounts if already loaded, otherwise fetch via store
        if (store.accounts && store.accounts.length > 0) {
            accounts.value = store.accounts;
        } else {
            await store.fetchInitialData();
            accounts.value = store.accounts;
        }

        // Determine initial account
        if (accounts.value.length > 0) {
            // 1. If replying, try to match the "TO" of the original email to one of our accounts
            if (props.replyTo) {
                // Checking if any of our accounts received this email
                // This is tricky because `replyTo.to` is an array of recipients.
                // We check if any of our account emails are in the recipient list.
                const originalRecipients =
                    props.replyTo.to?.map((t: any) => t.email) || [];
                // Also check CC
                const originalCc =
                    props.replyTo.cc?.map((c: any) => c.email) || [];
                const allDirectRecipients = [
                    ...originalRecipients,
                    ...originalCc,
                ];

                const matchingAccount = accounts.value.find((a) =>
                    allDirectRecipients.includes(a.email),
                );
                if (matchingAccount) {
                    selectedAccountId.value = matchingAccount.id;
                    return;
                }
            }

            // 2. Use currently selected account from sidebar
            if (store.selectedEmailId || store.selectedAccountId) {
                // store.selectedAccountId is likely available via storeToRefs if we imported it
                // store property is likely called 'selectedAccountId' but let's access it safely via store instance
                // We need to verify if the store exposes 'selectedAccountId' directly or we need storeToRefs
                // Looking at previous file view, logic was: `const { selectedAccountId } = storeToRefs(store);`
                // But here we are inside setup, let's just assume store state
                if (store.selectedAccountId) {
                    const activeAccount = accounts.value.find(
                        (a) =>
                            a.id == store.selectedAccountId ||
                            a.public_id == store.selectedAccountId,
                    );
                    if (activeAccount) {
                        selectedAccountId.value = activeAccount.id;
                        return;
                    }
                }
            }

            // 3. Fallback to Default
            const defaultAccount = accounts.value.find((a) => a.is_default);
            if (defaultAccount) {
                selectedAccountId.value = defaultAccount.id;
                return;
            }

            // 4. Fallback to First
            selectedAccountId.value = accounts.value[0].id;
        }
    } catch (e) {
        console.error("Failed to fetch email accounts", e);
    }
});

// Parse mode from tab id
const actualMode = computed(() => {
    if (props.mode.startsWith("reply-all")) return "reply-all";
    if (props.mode.startsWith("reply")) return "reply";
    if (props.mode.startsWith("forward-as-attachment"))
        return "forward-as-attachment";
    if (props.mode.startsWith("forward")) return "forward";
    if (props.mode.startsWith("compose")) return "compose";
    if (props.mode.startsWith("edit")) return "edit";
    return "compose";
});

const modeLabel = computed(() => {
    switch (actualMode.value) {
        case "reply-all":
            return "Reply All";
        case "reply":
            return "Reply";
        case "forward-as-attachment":
            return "Forward as Attachment";
        case "forward":
            return "Forward";
        case "edit":
            return "Edit Draft";
        default:
            return "New Email";
    }
});

const modeIcon = computed(() => {
    switch (actualMode.value) {
        case "reply-all":
            return markRaw(ReplyAllIcon);
        case "reply":
            return markRaw(ReplyIcon);
        case "forward-as-attachment":
            return markRaw(PaperclipIcon);
        case "forward":
            return markRaw(ForwardIcon);
        case "edit":
            return markRaw(PencilIcon);
        default:
            return markRaw(PencilIcon);
    }
});

const modeStyles = computed(() => {
    switch (actualMode.value) {
        case "reply-all":
            return { bg: "bg-indigo-500/10", text: "text-indigo-500" };
        case "reply":
            return { bg: "bg-blue-500/10", text: "text-blue-500" };
        case "forward-as-attachment":
            return { bg: "bg-orange-500/10", text: "text-orange-500" };
        case "forward":
            return { bg: "bg-purple-500/10", text: "text-purple-500" };
        case "edit":
            return { bg: "bg-amber-500/10", text: "text-amber-500" };
        default:
            return { bg: "bg-green-500/10", text: "text-green-500" };
    }
});

// Form state
const toEmails = ref<string[]>([]);
const ccEmails = ref<string[]>([]);
const bccEmails = ref<string[]>([]);
const showCc = ref(false);
const showBcc = ref(false);
const subject = ref("");
const attachments = ref<File[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);

function formatFileSize(bytes: number) {
    if (bytes === 0) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + " " + sizes[i];
}

function handleFileSelect(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files) {
        attachments.value.push(...Array.from(input.files));
    }
    // Reset input so same file can be selected again
    if (input.value) input.value = "";
}

function removeAttachment(index: number) {
    attachments.value.splice(index, 1);
}

async function fetchEmlAttachment(emailId: number | string, subject: string) {
    try {
        const response = await axios.get(`/api/emails/${emailId}/export`, {
            responseType: "blob",
        });

        const blob = response.data;
        const safeName = subject.replace(/[^a-zA-Z0-9_-]/g, "_") + ".eml";
        const file = new File([blob], safeName, { type: "message/rfc822" });

        // Add to attachments if not already present
        if (
            !attachments.value.some(
                (f) => f.name === safeName && f.size === file.size,
            )
        ) {
            attachments.value.push(file);
        }
    } catch (e) {
        console.error("Error fetching EML attachment:", e);
    }
}

const editor = useEditor({
    content: "",
    extensions: [
        StarterKit,
        Placeholder.configure({
            placeholder: "Write your message...",
        }),
        TextAlign.configure({
            types: ["heading", "paragraph"],
        }),
        TextStyle,
        Color,
        FontFamily,
        Underline,
        Image.configure({
            inline: true,
            allowBase64: true,
        }),
        FontSize,
        Link.configure({
            openOnClick: false,
            HTMLAttributes: {
                class: "text-blue-500 underline cursor-pointer",
            },
        }),
    ],
    onUpdate: () => {
        // Trigger auto-save on content change
        autoSave();
    },
    onBlur: () => {
        saveDraft(true);
    },
    editorProps: {
        attributes: {
            class: "prose dark:prose-invert max-w-none focus:outline-none min-h-[100px] text-(--text-primary)",
        },
        handleDrop: (view: any, event: any, slice: any, moved: boolean) => {
            if (
                !moved &&
                event.dataTransfer &&
                event.dataTransfer.files &&
                event.dataTransfer.files.length > 0
            ) {
                // Return false to let our custom drag handler in the parent div or explicit handling take over,
                // or handle it right here.
                // We'll let the custom directive on the container handle it for simplicity in Vue
                return false;
            }
            return false;
        },
    },
});

// Initialize based on mode
watch(
    actualMode,
    (mode) => {
        if (!props.replyTo) {
            subject.value = "";
            toEmails.value = [];
            return;
        }

        // Ensure we have the body for reply/forward
        if (!props.replyTo.body_html && !props.replyTo.body_plain) {
            store.fetchEmailBody(String(props.replyTo.id)).then((data) => {
                if (data && props.replyTo) {
                    props.replyTo.body_html = data.body_html;
                    props.replyTo.body_plain = data.body_plain;
                    // Trigger editor update after body fetch
                    setQuotedContent(mode);
                }
            });
        }

        const {
            from_email,
            to = [],
            cc = [],
            subject: origSubject,
        } = props.replyTo;

        // Subject
        if (mode === "forward" || mode === "forward-as-attachment") {
            subject.value = origSubject?.startsWith("Fwd:")
                ? origSubject
                : `Fwd: ${origSubject || ""}`;
        } else if (mode === "reply" || mode === "reply-all") {
            subject.value = origSubject?.startsWith("Re:")
                ? origSubject
                : `Re: ${origSubject || ""}`;
        } else {
            subject.value = "";
        }

        // Handle Forward as Attachment
        if (mode === "forward-as-attachment") {
            fetchEmlAttachment(props.replyTo.id, origSubject || "email");
        }

        // Recipients
        if (mode === "reply") {
            toEmails.value = from_email ? [from_email] : [];
            // Remove our own email from TO if present (optional, but good UX)
            // For now, simple implementation
            ccEmails.value = [];
        } else if (mode === "reply-all") {
            // TO: Sender
            const allTos = new Set<string>();
            if (from_email) allTos.add(from_email);

            // CC: Original TOs + Original CCs
            // Exclude our own email accounts if possible, but for now just add everyone
            const allCcs = new Set<string>();

            to.forEach((t) => {
                if (t?.email) allTos.add(t.email);
            });

            cc.forEach((c) => {
                if (c?.email) allCcs.add(c.email);
            });

            toEmails.value = Array.from(allTos);
            ccEmails.value = Array.from(allCcs);

            if (ccEmails.value.length > 0) showCc.value = true;
        } else if (mode === "forward") {
            toEmails.value = [];
            ccEmails.value = [];
            // Forward body content is handled by setQuotedContent
        } else if (mode === "edit") {
            // Populate from draft
            toEmails.value = to.map((t: any) => t.email);
            ccEmails.value = cc.map((c: any) => c.email);
            bccEmails.value = (props.replyTo.bcc || []).map(
                (b: any) => b.email,
            );
            subject.value = origSubject || "";

            if (ccEmails.value.length > 0) showCc.value = true;
            if (bccEmails.value.length > 0) showBcc.value = true;

            // Set Draft ID to update existing
            draftId.value = props.replyTo.id;

            // Set Account
            if (props.replyTo.email_account_id) {
                selectedAccountId.value = props.replyTo.email_account_id;
            }

            // Set Body
            let body =
                props.replyTo.body_html || props.replyTo.body_plain || "";

            // Resolve CID images if any
            if (body.includes("cid:") && props.replyTo.attachments) {
                props.replyTo.attachments.forEach((att: any) => {
                    if (att.content_id && att.url) {
                        const cleanCid = att.content_id.replace(/[<>]/g, "");
                        const escapedCid = cleanCid.replace(
                            /[.*+?^${}()|[\]\\]/g,
                            "\\$&",
                        );
                        const escapedFullCid = att.content_id.replace(
                            /[.*+?^${}()|[\]\\]/g,
                            "\\$&",
                        );

                        // Match src="cid:..." or src='cid:...' or src=cid:...
                        // Also handle urlencoded versions
                        const pattern = new RegExp(
                            `src=["'\\\\]*cid:(${escapedCid}|${escapedFullCid}|${encodeURIComponent(cleanCid)})["'\\\\]*`,
                            "gi",
                        );
                        body = body.replace(pattern, `src="${att.url}"`);
                    }
                });
            }

            if (body && editor.value) {
                editor.value.commands.setContent(body);
            } else {
                setTimeout(() => {
                    editor.value?.commands.setContent(body);
                }, 100);
            }

            // TODO: Attachments
            // We need to fetch existing attachments or show them?
            // Existing attachments are on the server. We should probably show them as chips?
            // But `attachments` ref is `File[]` for NEW uploads.
            // We might need a separate `existingAttachments` ref or convert them?
            // For now, assuming basic text edit.
        }

        // Set initial quoted content
        // Must wait for next tick or ensure editor is ready
        setTimeout(() => setQuotedContent(mode), 100);
    },
    { immediate: true },
);

function setQuotedContent(mode: string) {
    if (
        !props.replyTo ||
        mode === "compose" ||
        mode === "forward-as-attachment" ||
        mode === "edit"
    )
        return;

    const body = props.replyTo.body_html || props.replyTo.body_plain || "";
    if (!body) return;

    const date = props.replyTo.date
        ? formatDate(props.replyTo.date)
        : "Unknown date";
    const sender =
        props.replyTo.from_name || props.replyTo.from_email || "Unknown";

    let quotedHtml = "";

    if (mode === "reply" || mode === "reply-all") {
        quotedHtml = `
            <br><br>
            <div class="gmail_quote">
                <div dir="ltr" class="gmail_attr">
                    On ${date}, ${sender} wrote:<br>
                </div>
                <blockquote class="gmail_quote" style="margin:0px 0px 0px 0.8ex;border-left:1px solid rgb(204,204,204);padding-left:1ex">
                    ${body}
                </blockquote>
            </div>
        `;
    } else if (mode === "forward") {
        const toList =
            props.replyTo.to
                ?.map((t) =>
                    t.name ? `${t.name} &lt;${t.email}&gt;` : t.email,
                )
                .join(", ") || "";
        const ccList =
            props.replyTo.cc
                ?.map((c) =>
                    c.name ? `${c.name} &lt;${c.email}&gt;` : c.email,
                )
                .join(", ") || "";

        quotedHtml = `
            <br><br>
            <div class="gmail_quote">
                <div dir="ltr" class="gmail_attr">
                    ---------- Forwarded message ---------<br>
                    From: <strong>${props.replyTo.from_name || sender}</strong> &lt;${props.replyTo.from_email}&gt;<br>
                    Date: ${date}<br>
                    Subject: ${props.replyTo.subject}<br>
                    To: ${toList}<br>
                    ${ccList ? `Cc: ${ccList}<br>` : ""}
                </div>
                <br>
                ${body}
            </div>
        `;
    }

    if (quotedHtml && editor.value) {
        // Keep existing content if user has typed?
        // For now, just set it as we are initializing
        // Check if editor is empty to avoid overwriting if hot module reload or similar triggers
        if (editor.value.getText().length === 0) {
            editor.value.commands.setContent(quotedHtml);
            editor.value.commands.focus("start");
        }
    }
}

const characterCount = computed(() => {
    return editor.value?.getText().length || 0;
});

// Dropdown items for templates
const templateItems = computed(() =>
    templates.value.map((t) => ({
        label: t.name,
        icon: FileTextIcon,
        action: () => applyTemplate(t.id),
    })),
);

const selectedSignature = computed(() =>
    getSignatureById(selectedSignatureId.value),
);

// Dropdown items for signatures
const signatureItems = computed(() =>
    signatures.value.map((s) => ({
        label: s.name,
        icon: PenToolIcon,
        action: () => {
            selectedSignatureId.value = s.id;
        },
    })),
);

function applyTemplate(templateId: string) {
    const template = getTemplateById(templateId);
    if (template) {
        subject.value = template.subject;
        editor.value?.commands.setContent(template.body);
    }
}

// Local formatDate removed in favor of useDate composable

const isSending = ref(false);

async function handleSend() {
    if (!selectedAccount.value) {
        alert("Please select a sending account.");
        return;
    }

    // Basic validation
    if (
        !toEmails.value.length &&
        !ccEmails.value.length &&
        !bccEmails.value.length
    ) {
        alert("Please add at least one recipient.");
        return;
    }

    if (isSending.value) return;
    isSending.value = true;

    // Prepare data
    const emailData = {
        account_id: selectedAccount.value.id,
        to: toEmails.value,
        cc: ccEmails.value,
        bcc: bccEmails.value,
        subject: subject.value,
        body_html: editor.value?.getHTML() || "",
        body_plain: editor.value?.getText() || "",
        attachments: attachments.value,
        request_read_receipt: requestReadReceipt.value,
    };

    try {
        console.log("[EmailComposer] Starting handleSend...");
        // Use FormData for file uploads
        const formData = new FormData();
        formData.append("account_id", String(emailData.account_id));
        emailData.to.forEach((email, i) =>
            formData.append(`to[${i}][email]`, email),
        );
        emailData.cc.forEach((email, i) =>
            formData.append(`cc[${i}][email]`, email),
        );
        emailData.bcc.forEach((email, i) =>
            formData.append(`bcc[${i}][email]`, email),
        );
        formData.append("subject", emailData.subject);

        let bodyContent = emailData.body_html;
        // Append signature if selected
        if (selectedSignature.value?.content) {
            bodyContent += `<br><br>${selectedSignature.value.content}`;
        }
        formData.append("body", bodyContent);

        if (emailData.request_read_receipt) {
            formData.append("request_read_receipt", "1");
        }

        formData.append("is_important", isImportant.value ? "1" : "0");

        if (scheduledAt.value) {
            formData.append("scheduled_at", scheduledAt.value);
        }

        if (draftId.value) {
            formData.append("draft_id", draftId.value);
        }

        emailData.attachments.forEach((file, i) => {
            formData.append(`attachments[${i}]`, file);
        });

        const response = await axios.post("/api/emails", formData, {
            headers: {
                "Content-Type": "multipart/form-data",
            },
        });
        console.log("[EmailComposer] Send success:", response.data);

        emit("send");
        emit("close");
    } catch (error) {
        console.error("Error sending email:", error);
        alert("Failed to send email. Please try again.");
    } finally {
        console.log(
            "[EmailComposer] handleSend finished, resetting isSending.",
        );
        isSending.value = false;
    }
}

// Formatting Handlers
const showColorPicker = ref(false);
const showFontFamily = ref(false);
const showFontSize = ref(false);

const fontFamilies = [
    { label: "Sans Serif", value: "Inter, sans-serif" },
    { label: "Serif", value: "serif" },
    { label: "Monospace", value: "monospace" },
    { label: "Comic Sans", value: '"Comic Sans MS", "Comic Sans", cursive' },
];

const fontSizes = [
    { label: "Small", value: "12px" },
    { label: "Normal", value: "14px" },
    { label: "Large", value: "18px" },
    { label: "Huge", value: "24px" },
];

const colors = [
    "#000000",
    "#434343",
    "#666666",
    "#999999",
    "#b7b7b7",
    "#cccccc",
    "#d9d9d9",
    "#efefef",
    "#f3f3f3",
    "#ffffff",
    "#980000",
    "#ff0000",
    "#ff9900",
    "#ffff00",
    "#00ff00",
    "#00ffff",
    "#4a86e8",
    "#0000ff",
    "#9900ff",
    "#ff00ff",
];

function setFontFamily(font: string) {
    editor.value?.chain().focus().setFontFamily(font).run();
    showFontFamily.value = false;
}

function setFontSize(size: string) {
    editor.value?.chain().focus().setFontSize(size).run();
    showFontSize.value = false;
}

function setColor(color: string) {
    editor.value?.chain().focus().setColor(color).run();
    showColorPicker.value = false;
}

function addImage() {
    const input = document.createElement("input");
    input.type = "file";
    input.accept = "image/*";
    input.onchange = async (e: Event) => {
        const file = (e.target as HTMLInputElement).files?.[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const result = e.target?.result as string;
                editor.value?.chain().focus().setImage({ src: result }).run();
            };
            reader.readAsDataURL(file);
        }
    };
    input.click();
}

function handleAiAssist() {
    alert("AI Assist features are coming soon!");
}

// --- Link Support ---
function setLink() {
    const previousUrl = editor.value?.getAttributes("link").href;
    const url = window.prompt("URL", previousUrl);

    // cancelled
    if (url === null) {
        return;
    }

    // empty
    if (url === "") {
        editor.value?.chain().focus().extendMarkRange("link").unsetLink().run();
        return;
    }

    // update
    editor.value
        ?.chain()
        .focus()
        .extendMarkRange("link")
        .setLink({ href: url })
        .run();
}

// --- Draft Logic ---
function formatTime(date: Date) {
    return formatDate(date, "h:mm a");
}

const saveDraft = async (silent = false) => {
    if (!selectedAccount.value || savingDraft.value) return;

    // Don't save empty drafts
    if (
        !toEmails.value.length &&
        !subject.value &&
        (!editor.value || editor.value.isEmpty)
    )
        return;

    savingDraft.value = true;

    try {
        const formData = new FormData();
        formData.append("account_id", String(selectedAccount.value.id));

        toEmails.value.forEach((email, i) =>
            formData.append(`to[${i}][email]`, email),
        );
        ccEmails.value.forEach((email, i) =>
            formData.append(`cc[${i}][email]`, email),
        );
        bccEmails.value.forEach((email, i) =>
            formData.append(`bcc[${i}][email]`, email),
        );

        formData.append("subject", subject.value);
        formData.append("body", editor.value?.getHTML() || "");
        formData.append("is_draft", "1");

        if (requestReadReceipt.value) {
            formData.append("request_read_receipt", "1");
        }

        if (scheduledAt.value) {
            formData.append("scheduled_at", scheduledAt.value);
        }

        attachments.value.forEach((file, i) => {
            formData.append(`attachments[${i}]`, file);
        });

        let response;
        if (draftId.value) {
            // Update existing draft (use POST with _method=PUT for file uploads)
            formData.append("_method", "PUT");
            response = await axios.post(
                `/api/emails/${draftId.value}`,
                formData,
                {
                    headers: { "Content-Type": "multipart/form-data" },
                },
            );
        } else {
            // Create new draft
            response = await axios.post("/api/emails", formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });
            draftId.value = response.data.id;
        }

        lastSavedAt.value = new Date();
    } catch (error) {
        console.error("Failed to save draft:", error);
    } finally {
        savingDraft.value = false;
    }
};

const handleDiscardClick = () => {
    // If empty, just close
    const isBodyEmpty = !editor.value || editor.value.isEmpty;
    const isSubjectEmpty = !subject.value;
    const isToEmpty = !toEmails.value.length;

    if (isBodyEmpty && isSubjectEmpty && isToEmpty) {
        emit("close");
        return;
    }

    showDiscardModal.value = true;
};

const confirmDiscard = () => {
    showDiscardModal.value = false;
    emit("close");
};

const saveAndClose = async () => {
    await saveDraft();
    showDiscardModal.value = false;
    emit("close");
};

// Auto-save debounced
const autoSave = useDebounceFn(() => {
    saveDraft(true);
}, 5000);

const handleVisibilityChange = () => {
    if (document.visibilityState === "hidden") {
        saveDraft(true);
    }
};

onMounted(() => {
    window.addEventListener("visibilitychange", handleVisibilityChange);
});

// Watch for changes to trigger auto-save
watch(
    [toEmails, ccEmails, bccEmails, subject],
    () => {
        autoSave();
    },
    { deep: true },
);

// Watch editor content separately (since it's not a simple ref)
// actually we can just hook into editor onUpdate
watch(
    () => editor.value?.getHTML(),
    () => {
        autoSave();
    },
);

// Drag and drop for inline images
function handleDrop(event: DragEvent) {
    const hasFiles = event.dataTransfer?.files?.length;
    if (hasFiles) {
        event.preventDefault();
        const files = Array.from(event.dataTransfer!.files);
        // Separate images for inline and others for attachment
        files.forEach((file) => {
            if (file.type.startsWith("image/")) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    const result = e.target?.result as string;
                    editor.value
                        ?.chain()
                        .focus()
                        .setImage({ src: result })
                        .run();
                };
                reader.readAsDataURL(file);
            } else {
                attachments.value.push(file);
            }
        });
    }
}

onBeforeUnmount(() => {
    window.removeEventListener("visibilitychange", handleVisibilityChange);
    saveDraft(true);
    editor.value?.destroy();
});
</script>

<style scoped>
.slide-fade-enter-active,
.slide-fade-leave-active {
    transition: all 0.2s ease;
}
.slide-fade-enter-from,
.slide-fade-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}

:deep(.ProseMirror p.is-editor-empty:first-child::before) {
    content: attr(data-placeholder);
    float: left;
    color: var(--text-muted);
    pointer-events: none;
    height: 0;
}
</style>

<style>
/* Custom Scrollbar for Action Bar */
.scrollbar-hide::-webkit-scrollbar {
    display: none;
}
.scrollbar-hide {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>
