<template>
    <aside class="side-panel chat-panel">
        <div class="chat-header">
            <div class="chat-header-left">
                <button
                    v-if="activeThreadRootId"
                    type="button"
                    class="thread-back-btn"
                    @click="closeThread"
                >
                    Back
                </button>
                <h3>{{ activeThreadRootId ? "Thread" : "Messages" }}</h3>
            </div>
            <button @click="$emit('close')" class="chat-header-close">
                <Icon name="x" size="18" />
            </button>
        </div>
        <div
            v-if="!activeThreadRootId && pinnedMessages.length > 0"
            class="chat-pinned-dock"
        >
            <div class="chat-pinned-header">
                <div class="pinned-section-title">
                    Pinned ({{ pinnedMessages.length }})
                </div>
                <div class="pinned-header-actions">
                    <button
                        v-if="canModerateMessages && pinnedMessages.length > 0"
                        type="button"
                        class="pinned-dock-toggle pinned-dock-toggle-danger"
                        :disabled="isClearPinsBusy"
                        @click="clearAllPinnedMessages"
                    >
                        {{ isClearPinsBusy ? "Clearing..." : "Clear all" }}
                    </button>
                    <button
                        type="button"
                        class="pinned-dock-toggle"
                        @click="isPinnedDockCollapsed = !isPinnedDockCollapsed"
                    >
                        {{ isPinnedDockCollapsed ? "Expand" : "Collapse" }}
                    </button>
                </div>
            </div>
            <div v-show="!isPinnedDockCollapsed" class="pinned-section-list">
                <div
                    v-for="pinned in pinnedMessages"
                    :key="`pinned-${pinned.id}`"
                    class="pinned-item"
                >
                    <div
                        class="pinned-item-body"
                        :class="{
                            'pinned-item-body--clamped':
                                isPinnedExpandable(pinned) &&
                                !isPinnedExpanded(pinned),
                        }"
                    >
                        {{ pinned.body || (getAttachments(pinned).length ? "Attachment" : "") }}
                    </div>
                    <button
                        v-if="isPinnedExpandable(pinned)"
                        type="button"
                        class="pinned-item-toggle"
                        @click="togglePinnedExpanded(pinned)"
                    >
                        {{ isPinnedExpanded(pinned) ? "See less" : "See more" }}
                    </button>
                    <div class="pinned-item-meta">
                        {{
                            getParticipantName(
                                pinned.participant_public_id,
                                pinned.participant_name,
                            )
                        }}
                        · {{ formatTime(pinned.created_at) }}
                    </div>
                </div>
            </div>
        </div>
        <div
            class="side-panel-body chat-messages"
            ref="messagesContainer"
            @scroll="handleMessagesScroll"
        >
            <template v-if="!activeThreadRootId">
                <div v-if="isLoadingOlderMain" class="chat-history-loader">
                    <span class="chat-history-loader-spinner" aria-hidden="true"></span>
                    <span>Loading older messages...</span>
                </div>
                <TransitionGroup
                    name="chat-message-transition"
                    tag="div"
                    class="chat-message-list"
                >
                    <div
                        v-for="(msg, index) in topLevelMessages"
                        :key="messageRenderKey(msg)"
                        class="chat-message-item"
                        :class="{
                            'chat-message-me': isMe(msg.participant_public_id),
                            'chat-message-grouped': !shouldShowHeader(topLevelMessages, msg, index),
                            'chat-message-actions-open': isActionsOpen(msg),
                        }"
                        @click="handleMessageTap(msg, $event)"
                    >
                    <div
                        v-if="shouldShowDateDivider(topLevelMessages, msg, index)"
                        class="chat-date-divider"
                    >
                        {{ formatDateDivider(msg.created_at) }}
                    </div>
                    <div
                        v-if="shouldShowHeader(topLevelMessages, msg, index)"
                        class="chat-message-header"
                        :class="{ 'chat-message-header-me': isMe(msg.participant_public_id) }"
                    >
                        <Avatar
                            v-if="
                                msg.participant_public_id !== 'system' &&
                                !isMe(msg.participant_public_id)
                            "
                            :src="getParticipantAvatar(msg.participant_public_id)"
                            :fallback="
                                getParticipantInitial(
                                    msg.participant_public_id,
                                    msg.participant_name,
                                )
                            "
                            :color="getParticipantColor(msg.participant_public_id)"
                            size="xs"
                            class="chat-avatar"
                        />
                        <span v-if="!isMe(msg.participant_public_id)" class="chat-sender-name">{{
                            getParticipantName(
                                msg.participant_public_id,
                                msg.participant_name,
                            )
                        }}</span>
                        <span class="chat-time">{{
                            formatTime(msg.created_at)
                        }}</span>
                    </div>
                    <div
                        v-if="isEditingMessage(msg)"
                        class="chat-edit-wrap"
                    >
                        <textarea
                            v-model="editDraft"
                            class="chat-edit-input"
                            rows="3"
                            maxlength="2000"
                        />
                        <div class="chat-edit-actions">
                            <button
                                type="button"
                                class="thread-action-btn"
                                :disabled="editBusy"
                                @click="saveMessageEdit(msg)"
                            >
                                Save
                            </button>
                            <button
                                type="button"
                                class="thread-action-btn thread-action-btn-secondary"
                                :disabled="editBusy"
                                @click="cancelMessageEdit"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                    <div v-else-if="hasRenderableBody(msg)" class="chat-bubble" :class="{ 'chat-bubble-deleted': isDeletedMessage(msg) }">
                        {{ getRenderableBody(msg) }}
                    </div>
                    <div
                        v-if="!isDeletedMessage(msg) && hasUnsafeUrl(msg)"
                        class="chat-unsafe-badge"
                    >
                        {{ getUnsafeBadgeLabel(msg) }}
                    </div>
                    <template
                        v-for="previewUrl in [getMessagePrimaryUrl(msg)]"
                        :key="`preview-${msg.id}`"
                    >
                        <div
                            v-if="!isDeletedMessage(msg) && previewUrl"
                            class="chat-link-preview-wrap"
                        >
                            <LinkPreview
                                :url="previewUrl"
                                :hide-unsafe="true"
                                @unsafe="markUnsafeUrl(msg, $event)"
                            />
                        </div>
                    </template>
                    <div
                        v-if="!isDeletedMessage(msg) && getImageAttachments(msg).length > 0"
                        class="chat-media-bubble"
                    >
                        <div
                            class="chat-image-grid"
                            :class="getImageGridClass(msg)"
                        >
                            <button
                                v-for="(att, imageIndex) in getDisplayImageAttachments(msg)"
                                :key="`img-${msg.id}-${att.id}`"
                                type="button"
                                class="chat-image-link"
                                :class="getImageItemClass(imageIndex, getImageAttachments(msg).length)"
                                @click="openImageViewer(msg, att)"
                            >
                                <img
                                    :src="att.thumb_url || att.url"
                                    :alt="att.name || 'Image attachment'"
                                    class="chat-image"
                                />
                                <span
                                    v-if="
                                        imageIndex === 3 &&
                                        getImageAttachments(msg).length > 4
                                    "
                                    class="chat-image-overlay"
                                >
                                    +{{ getImageAttachments(msg).length - 4 }}
                                </span>
                            </button>
                        </div>
                    </div>
                    <div
                        v-if="!isDeletedMessage(msg) && getFileAttachments(msg).length > 0"
                        class="chat-media-bubble"
                    >
                        <div class="chat-file-list">
                            <a
                                v-for="att in getFileAttachments(msg)"
                                :key="`file-${msg.id}-${att.id}`"
                                :href="att.download_url || att.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="chat-file-link"
                            >
                                <Icon name="paperclip" size="14" />
                                <span class="chat-file-name">{{ att.name }}</span>
                                <span class="chat-file-size">{{
                                    formatFileSize(att.size)
                                }}</span>
                            </a>
                        </div>
                    </div>
                    <div
                        v-if="!isDeletedMessage(msg) && getVisibleReactions(msg).length > 0"
                        class="chat-reactions-row"
                    >
                        <button
                            v-for="reaction in getVisibleReactions(msg)"
                            :key="`reaction-${msg.id}-${reaction.key}`"
                            type="button"
                            class="chat-reaction-chip"
                            :class="{ 'is-active': reaction.active }"
                            @click="toggleMessageReaction(msg, reaction.key)"
                        >
                            <span>{{ reaction.emoji }}</span>
                            <span>{{ reaction.count }}</span>
                        </button>
                    </div>
                    <div v-if="msg.is_pinned" class="message-pill">Pinned</div>
                    <div v-if="isEditedMessage(msg) && !isDeletedMessage(msg)" class="message-pill message-pill-muted">
                        Edited
                    </div>
                    <div class="thread-actions">
                        <button
                            type="button"
                            class="thread-action-btn"
                            @click="openThread(msg)"
                        >
                            {{
                                threadReplyCount(msg.id) > 0
                                    ? `${threadReplyCount(msg.id)} repl${
                                          threadReplyCount(msg.id) === 1
                                              ? "y"
                                              : "ies"
                                      }`
                                    : "Reply in thread"
                            }}
                        </button>
                        <button
                            v-if="!isDeletedMessage(msg)"
                            type="button"
                            class="thread-action-btn thread-action-btn-secondary"
                            data-reaction-trigger="true"
                            @click.stop="toggleReactionMenu(msg)"
                        >
                            React
                        </button>
                        <button
                            v-if="canModerateMessages && !isDeletedMessage(msg)"
                            type="button"
                            class="thread-action-btn thread-action-btn-secondary"
                            :disabled="isPinBusy(msg)"
                            @click="togglePinned(msg)"
                        >
                            {{ msg.is_pinned ? "Unpin" : "Pin" }}
                        </button>
                        <button
                            v-if="canEditMessage(msg)"
                            type="button"
                            class="thread-action-btn thread-action-btn-secondary"
                            @click="startMessageEdit(msg)"
                        >
                            Edit
                        </button>
                        <button
                            v-if="canDeleteMessage(msg)"
                            type="button"
                            class="thread-action-btn thread-action-btn-secondary"
                            @click="deleteMessage(msg)"
                        >
                            Delete
                        </button>
                    </div>
                    <div
                        v-if="isReactionMenuOpen(msg)"
                        class="chat-reaction-menu"
                    >
                        <button
                            v-for="option in REACTION_OPTIONS"
                            :key="`reaction-option-${msg.id}-${option.key}`"
                            type="button"
                            class="chat-reaction-option"
                            :class="{ 'is-active': hasMyReaction(msg, option.key) }"
                            @click="toggleMessageReaction(msg, option.key)"
                        >
                            <span class="chat-reaction-option-emoji">{{
                                option.emoji
                            }}</span>
                            <span class="chat-reaction-option-label">{{
                                option.label
                            }}</span>
                        </button>
                    </div>
                    </div>
                </TransitionGroup>
                <div v-if="topLevelMessages.length === 0" class="panel-empty">
                    <Icon name="message-square" size="28" class="text-[#5f6368]" />
                    <p>
                        Messages can only be seen by people in the call and are
                        deleted when the call ends.
                    </p>
                </div>
            </template>

            <template v-else>
                <div v-if="activeThreadRoot" class="thread-root-card">
                    <div class="thread-root-header">
                        <span class="chat-sender-name">{{
                            getParticipantName(
                                activeThreadRoot.participant_public_id,
                                activeThreadRoot.participant_name,
                            )
                        }}</span>
                        <span
                            v-if="activeThreadRoot.is_pinned"
                            class="message-pill thread-pill"
                            >Pinned</span
                        >
                        <span class="chat-time">{{
                            formatTime(activeThreadRoot.created_at)
                        }}</span>
                    </div>
                    <div v-if="hasRenderableBody(activeThreadRoot)" class="chat-bubble thread-root-bubble" :class="{ 'chat-bubble-deleted': isDeletedMessage(activeThreadRoot) }">
                        {{ getRenderableBody(activeThreadRoot) }}
                    </div>
                    <div
                        v-if="!isDeletedMessage(activeThreadRoot) && hasUnsafeUrl(activeThreadRoot)"
                        class="chat-unsafe-badge thread-root-unsafe-badge"
                    >
                        {{ getUnsafeBadgeLabel(activeThreadRoot) }}
                    </div>
                    <template
                        v-for="
                            previewUrl in [getMessagePrimaryUrl(activeThreadRoot)]
                        "
                        :key="`thread-root-preview-${activeThreadRoot.id}`"
                    >
                        <div
                            v-if="!isDeletedMessage(activeThreadRoot) && previewUrl"
                            class="chat-link-preview-wrap thread-root-link-preview-wrap"
                        >
                            <LinkPreview
                                :url="previewUrl"
                                :hide-unsafe="true"
                                @unsafe="markUnsafeUrl(activeThreadRoot, $event)"
                            />
                        </div>
                    </template>
                    <div
                        v-if="!isDeletedMessage(activeThreadRoot) && getImageAttachments(activeThreadRoot).length > 0"
                        class="chat-media-bubble thread-root-media-bubble"
                    >
                        <div
                            class="chat-image-grid"
                            :class="getImageGridClass(activeThreadRoot)"
                        >
                            <button
                                v-for="(att, imageIndex) in getDisplayImageAttachments(activeThreadRoot)"
                                :key="`thread-root-img-${activeThreadRoot.id}-${att.id}`"
                                type="button"
                                class="chat-image-link"
                                :class="
                                    getImageItemClass(
                                        imageIndex,
                                        getImageAttachments(activeThreadRoot).length,
                                    )
                                "
                                @click="openImageViewer(activeThreadRoot, att)"
                            >
                                <img
                                    :src="att.thumb_url || att.url"
                                    :alt="att.name || 'Image attachment'"
                                    class="chat-image"
                                />
                                <span
                                    v-if="
                                        imageIndex === 3 &&
                                        getImageAttachments(activeThreadRoot).length > 4
                                    "
                                    class="chat-image-overlay"
                                >
                                    +{{ getImageAttachments(activeThreadRoot).length - 4 }}
                                </span>
                            </button>
                        </div>
                    </div>
                    <div
                        v-if="!isDeletedMessage(activeThreadRoot) && getFileAttachments(activeThreadRoot).length > 0"
                        class="chat-media-bubble thread-root-media-bubble"
                    >
                        <div class="chat-file-list">
                            <a
                                v-for="att in getFileAttachments(activeThreadRoot)"
                                :key="`thread-root-file-${activeThreadRoot.id}-${att.id}`"
                                :href="att.download_url || att.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="chat-file-link"
                            >
                                <Icon name="paperclip" size="14" />
                                <span class="chat-file-name">{{ att.name }}</span>
                                <span class="chat-file-size">{{
                                    formatFileSize(att.size)
                                }}</span>
                            </a>
                        </div>
                    </div>
                    <div
                        v-if="!isDeletedMessage(activeThreadRoot) && getVisibleReactions(activeThreadRoot).length > 0"
                        class="chat-reactions-row thread-root-reactions"
                    >
                        <button
                            v-for="reaction in getVisibleReactions(activeThreadRoot)"
                            :key="`thread-root-reaction-${activeThreadRoot.id}-${reaction.key}`"
                            type="button"
                            class="chat-reaction-chip"
                            :class="{ 'is-active': reaction.active }"
                            @click="toggleMessageReaction(activeThreadRoot, reaction.key)"
                        >
                            <span>{{ reaction.emoji }}</span>
                            <span>{{ reaction.count }}</span>
                        </button>
                    </div>
                </div>

                <div class="thread-divider">Replies</div>
                <div v-if="isThreadLoading" class="thread-loading">
                    Loading thread...
                </div>

                <TransitionGroup
                    name="chat-message-transition"
                    tag="div"
                    class="chat-message-list"
                >
                    <div
                        v-for="(msg, index) in threadReplies"
                        :key="messageRenderKey(msg)"
                        class="chat-message-item"
                        :class="{
                            'chat-message-me': isMe(msg.participant_public_id),
                            'chat-message-grouped': !shouldShowHeader(threadReplies, msg, index),
                            'chat-message-actions-open': isActionsOpen(msg),
                        }"
                        @click="handleMessageTap(msg, $event)"
                    >
                    <div
                        v-if="shouldShowDateDivider(threadReplies, msg, index)"
                        class="chat-date-divider"
                    >
                        {{ formatDateDivider(msg.created_at) }}
                    </div>
                    <div
                        v-if="shouldShowHeader(threadReplies, msg, index)"
                        class="chat-message-header"
                        :class="{ 'chat-message-header-me': isMe(msg.participant_public_id) }"
                    >
                        <Avatar
                            v-if="
                                msg.participant_public_id !== 'system' &&
                                !isMe(msg.participant_public_id)
                            "
                            :src="getParticipantAvatar(msg.participant_public_id)"
                            :fallback="
                                getParticipantInitial(
                                    msg.participant_public_id,
                                    msg.participant_name,
                                )
                            "
                            :color="getParticipantColor(msg.participant_public_id)"
                            size="xs"
                            class="chat-avatar"
                        />
                        <span v-if="!isMe(msg.participant_public_id)" class="chat-sender-name">{{
                            getParticipantName(
                                msg.participant_public_id,
                                msg.participant_name,
                            )
                        }}</span>
                        <span class="chat-time">{{
                            formatTime(msg.created_at)
                        }}</span>
                    </div>
                    <div
                        v-if="isEditingMessage(msg)"
                        class="chat-edit-wrap"
                    >
                        <textarea
                            v-model="editDraft"
                            class="chat-edit-input"
                            rows="3"
                            maxlength="2000"
                        />
                        <div class="chat-edit-actions">
                            <button
                                type="button"
                                class="thread-action-btn"
                                :disabled="editBusy"
                                @click="saveMessageEdit(msg)"
                            >
                                Save
                            </button>
                            <button
                                type="button"
                                class="thread-action-btn thread-action-btn-secondary"
                                :disabled="editBusy"
                                @click="cancelMessageEdit"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                    <div v-else-if="hasRenderableBody(msg)" class="chat-bubble" :class="{ 'chat-bubble-deleted': isDeletedMessage(msg) }">
                        {{ getRenderableBody(msg) }}
                    </div>
                    <div
                        v-if="!isDeletedMessage(msg) && hasUnsafeUrl(msg)"
                        class="chat-unsafe-badge"
                    >
                        {{ getUnsafeBadgeLabel(msg) }}
                    </div>
                    <template
                        v-for="previewUrl in [getMessagePrimaryUrl(msg)]"
                        :key="`thread-preview-${msg.id}`"
                    >
                        <div
                            v-if="!isDeletedMessage(msg) && previewUrl"
                            class="chat-link-preview-wrap"
                        >
                            <LinkPreview
                                :url="previewUrl"
                                :hide-unsafe="true"
                                @unsafe="markUnsafeUrl(msg, $event)"
                            />
                        </div>
                    </template>
                    <div
                        v-if="!isDeletedMessage(msg) && getImageAttachments(msg).length > 0"
                        class="chat-media-bubble"
                    >
                        <div
                            class="chat-image-grid"
                            :class="getImageGridClass(msg)"
                        >
                            <button
                                v-for="(att, imageIndex) in getDisplayImageAttachments(msg)"
                                :key="`thread-img-${msg.id}-${att.id}`"
                                type="button"
                                class="chat-image-link"
                                :class="getImageItemClass(imageIndex, getImageAttachments(msg).length)"
                                @click="openImageViewer(msg, att)"
                            >
                                <img
                                    :src="att.thumb_url || att.url"
                                    :alt="att.name || 'Image attachment'"
                                    class="chat-image"
                                />
                                <span
                                    v-if="
                                        imageIndex === 3 &&
                                        getImageAttachments(msg).length > 4
                                    "
                                    class="chat-image-overlay"
                                >
                                    +{{ getImageAttachments(msg).length - 4 }}
                                </span>
                            </button>
                        </div>
                    </div>
                    <div
                        v-if="!isDeletedMessage(msg) && getFileAttachments(msg).length > 0"
                        class="chat-media-bubble"
                    >
                        <div class="chat-file-list">
                            <a
                                v-for="att in getFileAttachments(msg)"
                                :key="`thread-file-${msg.id}-${att.id}`"
                                :href="att.download_url || att.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="chat-file-link"
                            >
                                <Icon name="paperclip" size="14" />
                                <span class="chat-file-name">{{ att.name }}</span>
                                <span class="chat-file-size">{{
                                    formatFileSize(att.size)
                                }}</span>
                            </a>
                        </div>
                    </div>
                    <div
                        v-if="!isDeletedMessage(msg) && getVisibleReactions(msg).length > 0"
                        class="chat-reactions-row"
                    >
                        <button
                            v-for="reaction in getVisibleReactions(msg)"
                            :key="`thread-reaction-${msg.id}-${reaction.key}`"
                            type="button"
                            class="chat-reaction-chip"
                            :class="{ 'is-active': reaction.active }"
                            @click="toggleMessageReaction(msg, reaction.key)"
                        >
                            <span>{{ reaction.emoji }}</span>
                            <span>{{ reaction.count }}</span>
                        </button>
                    </div>
                    <div v-if="isEditedMessage(msg) && !isDeletedMessage(msg)" class="message-pill message-pill-muted">
                        Edited
                    </div>
                    <div class="thread-actions">
                        <button
                            v-if="!isDeletedMessage(msg)"
                            type="button"
                            class="thread-action-btn"
                            data-reaction-trigger="true"
                            @click.stop="toggleReactionMenu(msg)"
                        >
                            React
                        </button>
                        <button
                            v-if="canEditMessage(msg)"
                            type="button"
                            class="thread-action-btn thread-action-btn-secondary"
                            @click="startMessageEdit(msg)"
                        >
                            Edit
                        </button>
                        <button
                            v-if="canDeleteMessage(msg)"
                            type="button"
                            class="thread-action-btn thread-action-btn-secondary"
                            @click="deleteMessage(msg)"
                        >
                            Delete
                        </button>
                    </div>
                    <div
                        v-if="isReactionMenuOpen(msg)"
                        class="chat-reaction-menu"
                    >
                        <button
                            v-for="option in REACTION_OPTIONS"
                            :key="`thread-reaction-option-${msg.id}-${option.key}`"
                            type="button"
                            class="chat-reaction-option"
                            :class="{ 'is-active': hasMyReaction(msg, option.key) }"
                            @click="toggleMessageReaction(msg, option.key)"
                        >
                            <span class="chat-reaction-option-emoji">{{
                                option.emoji
                            }}</span>
                            <span class="chat-reaction-option-label">{{
                                option.label
                            }}</span>
                        </button>
                    </div>
                    </div>
                </TransitionGroup>

                <div
                    v-if="!isThreadLoading && threadReplies.length === 0"
                    class="thread-empty"
                >
                    No replies yet. Be the first to reply.
                </div>
            </template>

            <div
                v-if="activeThreadRootId && !activeThreadRoot && !isThreadLoading"
                class="panel-empty"
            >
                <Icon name="message-square" size="28" class="text-[#5f6368]" />
                <p>Thread not found.</p>
            </div>
        </div>
        <button
            v-if="showJumpToLatest"
            type="button"
            class="chat-jump-latest-btn"
            title="Jump to latest"
            @click="jumpToLatest"
        >
            <Icon name="ChevronDown" size="16" />
        </button>
        <div class="chat-input-area relative">
            <input
                ref="fileInputRef"
                type="file"
                class="hidden"
                multiple
                @change="handleFileSelect"
            />
            <!-- Emoji Picker Popover -->
            <div
                v-show="showEmoji"
                ref="emojiMountRef"
                class="emoji-picker-container"
            ></div>

            <div v-if="pendingFiles.length > 0" class="chat-pending-files">
                <div
                    v-for="(file, index) in pendingFiles"
                    :key="`${file.name}-${file.size}-${index}`"
                    class="chat-pending-file"
                >
                    <span class="chat-pending-file-name">{{ file.name }}</span>
                    <span class="chat-pending-file-size">{{
                        formatFileSize(file.size)
                    }}</span>
                    <button
                        type="button"
                        class="chat-pending-file-remove"
                        @click="removePendingFile(index)"
                        :disabled="isSending"
                    >
                        <Icon name="x" size="12" />
                    </button>
                </div>
            </div>

            <div v-if="activeSendFeedbackMessage" class="chat-send-feedback">
                {{ activeSendFeedbackMessage }}
            </div>

            <form @submit.prevent="submitMessage" class="chat-form">
                <button
                    type="button"
                    class="chat-action-btn"
                    title="Attach files"
                    @click="openFilePicker"
                    :disabled="isSending"
                >
                    <Icon name="paperclip" size="18" />
                </button>
                <button
                    type="button"
                    class="chat-action-btn"
                    title="Insert emoji"
                    @click.stop="toggleEmoji"
                >
                    <Icon name="Smile" size="18" />
                </button>
                <input
                    ref="chatInputRef"
                    type="text"
                    v-model="newMessage"
                    class="chat-input"
                    :placeholder="
                        activeThreadRootId
                            ? 'Reply in thread'
                            : 'Send a message to everyone'
                    "
                />
                <button
                    type="submit"
                    class="chat-send-btn"
                    :disabled="
                        (!newMessage.trim() && pendingFiles.length === 0) ||
                        isSending ||
                        isThrottleActive
                    "
                    title="Send message"
                >
                    <Icon name="send" size="18" />
                </button>
            </form>
        </div>
    </aside>
</template>

<script setup lang="ts">
import { ref, nextTick, watch, onMounted, onUnmounted, computed } from "vue";
import { useMeetingStore } from "@/stores/meeting";
import { useThemeStore } from "@/stores/theme";
import { meetingService } from "@/services/meeting.service";
import { useToast } from "@/composables/useToast";
import LinkPreview from "@/components/LinkPreview.vue";
import { Icon, Avatar } from "@/components/ui";
import data from "@emoji-mart/data";
import { Picker } from "emoji-mart";

defineEmits(["close"]);

const meetingStore = useMeetingStore();
const themeStore = useThemeStore();
const { warning } = useToast();
const newMessage = ref("");
const isSending = ref(false);
const messagesContainer = ref<HTMLElement | null>(null);
const chatInputRef = ref<HTMLInputElement | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);
const isThreadLoading = ref(false);
const activeThreadRootId = ref<string | null>(null);
const pinBusyMap = ref<Record<string, boolean>>({});
const pinnedExpandedMap = ref<Record<string, boolean>>({});
const isPinnedDockCollapsed = ref(false);
const isClearPinsBusy = ref(false);
const pendingFiles = ref<Array<{ file: File; name: string; size: number }>>([]);
const editingMessageId = ref<string | null>(null);
const editDraft = ref("");
const editBusy = ref(false);
const isLoadingOlderMain = ref(false);
const hasMoreOlderMain = ref(true);
const shouldStickToBottom = ref(true);
const showJumpToLatest = ref(false);
const reactionMenuMessageId = ref<string | null>(null);
const unsafeUrlsByMessage = ref<Record<string, string[]>>({});
const lastSendAtMs = ref(0);
const lastSentBodyHash = ref<string>("");
const activeActionMessageId = ref<string | null>(null);
const throttleUntilMs = ref(0);
const throttleNowMs = ref(Date.now());

const MAX_FILES = 10;
const MAX_FILE_SIZE = 5 * 1024 * 1024;
const MAX_TOTAL_SIZE = 10 * 1024 * 1024;
const MAIN_HISTORY_PAGE_SIZE = 80;
const CLIENT_SEND_COOLDOWN_MS = 450;
const CLIENT_DUPLICATE_BODY_COOLDOWN_MS = 3000;
const MESSAGE_URL_REGEX = /(https?:\/\/[^\s<>"']+)/i;
const MESSAGE_URL_GLOBAL_REGEX = /https?:\/\/[^\s<>"']+/gi;
const REACTION_OPTIONS = [
    { key: "like", emoji: "👍", label: "Like" },
    { key: "laugh", emoji: "😂", label: "Laugh" },
    { key: "hundred", emoji: "💯", label: "100" },
    { key: "care", emoji: "🤗", label: "Care" },
    { key: "angry", emoji: "😡", label: "Angry" },
    { key: "scared", emoji: "😱", label: "Scared" },
    { key: "sad", emoji: "😢", label: "Sad" },
    { key: "love", emoji: "❤️", label: "Love" },
] as const;
type ReactionKey = (typeof REACTION_OPTIONS)[number]["key"];

// Emoji State
const showEmoji = ref(false);
const emojiMountRef = ref<HTMLElement | null>(null);
let pickerInstance: any = null;
let pickerTheme = "";
let throttleTicker: ReturnType<typeof setInterval> | null = null;

const throttleSecondsLeft = computed(() =>
    Math.max(0, Math.ceil((throttleUntilMs.value - throttleNowMs.value) / 1000)),
);

const isThrottleActive = computed(() => throttleSecondsLeft.value > 0);

const activeSendFeedbackMessage = computed(() => {
    if (isThrottleActive.value) {
        return `You're sending messages too fast. Try again in ${throttleSecondsLeft.value}s.`;
    }
    return "";
});

const sortedMessages = computed(() => {
    return [...meetingStore.chatMessages].sort((a, b) => {
        const aTime = new Date(a.created_at || 0).getTime();
        const bTime = new Date(b.created_at || 0).getTime();
        if (aTime !== bTime) return aTime - bTime;
        return String(a.id).localeCompare(String(b.id));
    });
});

const topLevelMessages = computed(() =>
    sortedMessages.value.filter((msg: any) => !msg.thread_root_id),
);

const pinnedMessages = computed(() =>
    topLevelMessages.value
        .filter((msg: any) => !!msg.is_pinned)
        .sort((a: any, b: any) => {
            const aTime = new Date(a.pinned_at || a.created_at || 0).getTime();
            const bTime = new Date(b.pinned_at || b.created_at || 0).getTime();
            return bTime - aTime;
        }),
);

const canModerateMessages = computed(() => Boolean(meetingStore.isModerator));

const activeThreadRoot = computed(() => {
    if (!activeThreadRootId.value) return null;
    return (
        meetingStore.chatMessages.find(
            (msg: any) => String(msg.id) === String(activeThreadRootId.value),
        ) || null
    );
});

const threadReplies = computed(() => {
    if (!activeThreadRootId.value) return [];
    return sortedMessages.value.filter(
        (msg: any) =>
            String(msg.thread_root_id || "") === String(activeThreadRootId.value),
    );
});

function normalizePublicId(publicId: string | null | undefined) {
    return String(publicId || "").trim().toLowerCase();
}

function resolveParticipant(publicId: string) {
    const normalizedId = normalizePublicId(publicId);
    return (
        meetingStore.allParticipants.find(
            (x: any) => normalizePublicId(x.public_id) === normalizedId,
        ) || null
    );
}

function getParticipantName(publicId: string, fallbackName?: string | null) {
    if (publicId === "system") return "System";
    if (isMe(publicId)) return "You";
    const p = resolveParticipant(publicId);
    if (!p) {
        const normalizedFallback = String(fallbackName || "").trim();
        return normalizedFallback || "Guest";
    }
    const name = String(p?.user?.name || p?.metadata?.guest_name || "Guest").trim();
    const hasLinkedUser = Boolean(p?.user_id || p?.user?.id || p?.user?.public_id);
    const isGuest = !hasLinkedUser;
    if (isGuest) {
        if (/\(guest\)$/i.test(name)) return name;
        if (/^guest$/i.test(name)) return "Guest";
        return `${name} (Guest)`;
    }
    return name;
}

function getParticipantAvatar(publicId: string) {
    const p = resolveParticipant(publicId);
    return p?.user?.avatar_url || p?.metadata?.avatar_url;
}

function getParticipantColor(publicId: string) {
    const p = resolveParticipant(publicId);
    return p?.user?.color;
}

function getParticipantInitial(publicId: string, fallbackName?: string | null) {
    const p = resolveParticipant(publicId);
    const name = String(
        p?.user?.name || p?.metadata?.guest_name || fallbackName || "G",
    ).trim();
    return name.charAt(0).toUpperCase();
}

function isMe(publicId: string) {
    return (
        normalizePublicId(publicId) ===
        normalizePublicId(meetingStore.localParticipant?.public_id)
    );
}

function isDeletedMessage(msg: any) {
    if (!msg) return false;
    return Boolean(msg.is_deleted || msg?.metadata?.is_deleted);
}

function isEditedMessage(msg: any) {
    if (!msg) return false;
    return Boolean(msg.is_edited || msg?.metadata?.is_edited);
}

function messageKey(msg: any) {
    return String(msg?.id ?? msg?.public_id ?? "");
}

function isTouchLikeDevice() {
    if (typeof window === "undefined") return false;
    return window.matchMedia("(hover: none), (pointer: coarse)").matches;
}

function isInteractiveMessageTarget(target: EventTarget | null) {
    const node = target as HTMLElement | null;
    if (!node?.closest) return false;
    return Boolean(
        node.closest(
            "button, a, input, textarea, select, label, [role='button'], [data-reaction-trigger], .chat-reaction-menu",
        ),
    );
}

function isActionsOpen(msg: any) {
    return activeActionMessageId.value === messageKey(msg);
}

function handleMessageTap(msg: any, event: MouseEvent) {
    if (!isTouchLikeDevice()) return;
    if (isInteractiveMessageTarget(event.target)) return;
    const key = messageKey(msg);
    if (!key) return;
    activeActionMessageId.value =
        activeActionMessageId.value === key ? null : key;
}

function startThrottleCountdown(seconds = 30, notifyToast = true) {
    const duration = Math.max(1, Math.ceil(seconds));
    const newUntil = Date.now() + duration * 1000;
    throttleUntilMs.value = Math.max(throttleUntilMs.value, newUntil);
    throttleNowMs.value = Date.now();

    if (!throttleTicker) {
        throttleTicker = setInterval(() => {
            throttleNowMs.value = Date.now();
            if (throttleUntilMs.value <= throttleNowMs.value) {
                throttleUntilMs.value = 0;
                if (throttleTicker) {
                    clearInterval(throttleTicker);
                    throttleTicker = null;
                }
            }
        }, 250);
    }

    if (notifyToast) {
        warning("Slow down", `You can send again in ${duration}s.`);
    }
}

function messageRenderKey(msg: any) {
    const tempId = String(msg?.temp_id || "").trim();
    if (tempId) {
        return `temp:${normalizePublicId(msg?.participant_public_id)}:${tempId}`;
    }

    const canonical = messageKey(msg);
    if (canonical) return `msg:${canonical}`;

    return `fallback:${normalizePublicId(msg?.participant_public_id)}:${String(msg?.created_at || "")}:${String(msg?.body || "").slice(0, 32)}`;
}

function normalizeUrlToken(url: string) {
    return String(url || "")
        .trim()
        .replace(/[),.;!?]+$/g, "")
        .toLowerCase();
}

function getUnsafeUrls(msg: any): string[] {
    const key = messageKey(msg);
    if (!key) return [];
    return unsafeUrlsByMessage.value[key] || [];
}

function hasUnsafeUrl(msg: any) {
    return getUnsafeUrls(msg).length > 0;
}

function getUnsafeBadgeLabel(msg: any) {
    const count = getUnsafeUrls(msg).length;
    return count > 1 ? `${count} unsafe links blocked` : "Unsafe link blocked";
}

function markUnsafeUrl(msg: any, url: string) {
    const key = messageKey(msg);
    if (!key || !url) return;
    const normalized = normalizeUrlToken(url);
    if (!normalized) return;

    const existing = unsafeUrlsByMessage.value[key] || [];
    if (existing.includes(normalized)) return;
    unsafeUrlsByMessage.value[key] = [...existing, normalized];
}

function getRenderableBody(msg: any) {
    if (isDeletedMessage(msg)) return "Message deleted";
    const body = String(msg?.body || "");
    if (!body) return "";

    const blocked = new Set(getUnsafeUrls(msg));
    if (blocked.size === 0) return body;

    const redacted = body.replace(MESSAGE_URL_GLOBAL_REGEX, (rawUrl) => {
        return blocked.has(normalizeUrlToken(rawUrl)) ? "" : rawUrl;
    });

    return redacted.replace(/\s{2,}/g, " ").trim();
}

function hasRenderableBody(msg: any) {
    if (isDeletedMessage(msg)) return true;
    return getRenderableBody(msg).length > 0;
}

function getMessagePrimaryUrl(msg: any) {
    if (!msg || isDeletedMessage(msg)) return null;
    const body = String(msg?.body || "").trim();
    if (!body) return null;
    const match = body.match(MESSAGE_URL_REGEX);
    if (!match?.[0]) return null;
    return match[0].replace(/[),.;!?]+$/g, "");
}

function normalizeBodyForThrottle(body: string) {
    return String(body || "")
        .toLowerCase()
        .replace(/\s+/g, " ")
        .trim();
}

function canEditMessage(msg: any) {
    if (!msg || isDeletedMessage(msg)) return false;
    return isMe(msg.participant_public_id);
}

function canDeleteMessage(msg: any) {
    if (!msg || isDeletedMessage(msg)) return false;
    return isMe(msg.participant_public_id) || canModerateMessages.value;
}

function isEditingMessage(msg: any) {
    return String(msg?.id || "") === String(editingMessageId.value || "");
}

function startMessageEdit(msg: any) {
    if (!canEditMessage(msg)) return;
    editingMessageId.value = String(msg.id);
    editDraft.value = String(msg.body || "");
}

function cancelMessageEdit() {
    editingMessageId.value = null;
    editDraft.value = "";
}

async function saveMessageEdit(msg: any) {
    if (!canEditMessage(msg) || !isEditingMessage(msg) || editBusy.value) return;
    const body = editDraft.value.trim();
    if (!body || body === String(msg.body || "").trim()) {
        cancelMessageEdit();
        return;
    }

    editBusy.value = true;
    try {
        await meetingStore.editChatMessage(msg.id, body);
        cancelMessageEdit();
    } catch (e) {
        console.error("Failed to edit message", e);
    } finally {
        editBusy.value = false;
    }
}

async function deleteMessage(msg: any) {
    if (!canDeleteMessage(msg)) return;
    const accepted = window.confirm("Delete this message?");
    if (!accepted) return;

    try {
        await meetingStore.deleteChatMessage(msg.id);
        if (isEditingMessage(msg)) {
            cancelMessageEdit();
        }
    } catch (e) {
        console.error("Failed to delete message", e);
    }
}

function formatTime(isoString: string) {
    if (!isoString) return "";
    const date = new Date(isoString);
    return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

function dateKey(isoString: string) {
    if (!isoString) return "";
    const d = new Date(isoString);
    if (Number.isNaN(d.getTime())) return "";
    return `${d.getFullYear()}-${d.getMonth() + 1}-${d.getDate()}`;
}

function shouldShowDateDivider(messageList: any[], msg: any, index: number) {
    if (index === 0) return true;
    const prev = messageList[index - 1];
    return dateKey(prev?.created_at) !== dateKey(msg?.created_at);
}

function formatDateDivider(isoString: string) {
    if (!isoString) return "";
    const target = new Date(isoString);
    if (Number.isNaN(target.getTime())) return "";

    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const targetStart = new Date(
        target.getFullYear(),
        target.getMonth(),
        target.getDate(),
    ).getTime();
    const diffDays = Math.round((today - targetStart) / 86400000);

    if (diffDays === 0) return "Today";
    if (diffDays === 1) return "Yesterday";

    return target.toLocaleDateString([], {
        month: "short",
        day: "numeric",
        year: target.getFullYear() === now.getFullYear() ? undefined : "numeric",
    });
}

function formatFileSize(bytes: number) {
    if (!bytes || bytes <= 0) return "0 B";
    if (bytes < 1024) return `${bytes} B`;
    const units = ["KB", "MB", "GB"];
    const i = Math.min(
        units.length - 1,
        Math.floor(Math.log(bytes) / Math.log(1024)) - 1,
    );
    const value = bytes / Math.pow(1024, i + 1);
    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[i]}`;
}

function pinnedExpandKey(msg: any) {
    return String(msg?.id ?? msg?.public_id ?? "");
}

function isPinnedExpandable(msg: any) {
    const text = String(msg?.body || "").trim();
    if (!text) return false;
    return text.length > 120 || text.includes("\n");
}

function isPinnedExpanded(msg: any) {
    return !!pinnedExpandedMap.value[pinnedExpandKey(msg)];
}

function togglePinnedExpanded(msg: any) {
    const key = pinnedExpandKey(msg);
    if (!key) return;
    pinnedExpandedMap.value[key] = !pinnedExpandedMap.value[key];
}

function getAttachments(msg: any): any[] {
    return Array.isArray(msg?.attachments) ? msg.attachments : [];
}

function getImageAttachments(msg: any): any[] {
    return getAttachments(msg).filter((att: any) =>
        String(att?.mime_type || "").startsWith("image/"),
    );
}

function getDisplayImageAttachments(msg: any): any[] {
    return getImageAttachments(msg).slice(0, 4);
}

function getImageGridClass(msg: any) {
    const count = getImageAttachments(msg).length;
    if (count <= 1) return "chat-image-grid-single";
    if (count === 2) return "chat-image-grid-two";
    if (count === 3) return "chat-image-grid-three";
    return "chat-image-grid-many";
}

function getImageItemClass(index: number, total: number) {
    if (total === 3 && index === 0) return "chat-image-item-wide";
    return "";
}

function getFileAttachments(msg: any): any[] {
    return getAttachments(msg).filter(
        (att: any) => !String(att?.mime_type || "").startsWith("image/"),
    );
}

function openImageViewer(msg: any, targetAttachment: any) {
    const allImages = getImageAttachments(msg);
    if (!allImages.length) return;

    const media = allImages.map((image: any) => ({
        src: image.url,
        download: image.download_url || image.url,
        id: image.id,
        type: "image",
        mimeType: image.mime_type,
    }));

    const index = media.findIndex(
        (item: any) => String(item.id) === String(targetAttachment?.id),
    );

    window.dispatchEvent(
        new CustomEvent("media-viewer:open", {
            detail: {
                media,
                index: index >= 0 ? index : 0,
            },
        }),
    );
}

function openFilePicker() {
    fileInputRef.value?.click();
}

function handleFileSelect(e: Event) {
    const input = e.target as HTMLInputElement;
    if (!input.files) return;

    const selected = Array.from(input.files);
    const currentTotal = pendingFiles.value.reduce((sum, file) => sum + file.size, 0);

    if (pendingFiles.value.length + selected.length > MAX_FILES) {
        console.warn(`Meeting chat upload limit exceeded: max ${MAX_FILES} files.`);
        input.value = "";
        return;
    }

    const selectedTotal = selected.reduce((sum, file) => sum + file.size, 0);
    if (currentTotal + selectedTotal > MAX_TOTAL_SIZE) {
        console.warn("Meeting chat upload total size exceeded: max 10MB.");
        input.value = "";
        return;
    }

    selected.forEach((file) => {
        if (file.size > MAX_FILE_SIZE) {
            console.warn(`Skipping oversized meeting chat file: ${file.name}`);
            return;
        }

        pendingFiles.value.push({
            file,
            name: file.name,
            size: file.size,
        });
    });

    input.value = "";
}

function removePendingFile(index: number) {
    pendingFiles.value.splice(index, 1);
}

function shouldShowHeader(messageList: any[], msg: any, index: number) {
    if (index === 0) return true;
    const prevMsg = messageList[index - 1];

    // Show header if sender changed
    if (
        normalizePublicId(prevMsg.participant_public_id) !==
        normalizePublicId(msg.participant_public_id)
    )
        return true;

    // Show header if more than 5 minutes passed
    if (msg.created_at && prevMsg.created_at) {
        const diff =
            new Date(msg.created_at).getTime() -
            new Date(prevMsg.created_at).getTime();
        if (diff > 5 * 60 * 1000) return true;
    }

    return false;
}

function threadReplyCount(rootMessageId: string | number) {
    return meetingStore.chatMessages.filter(
        (msg: any) => String(msg.thread_root_id || "") === String(rootMessageId),
    ).length;
}

function getReactionParticipants(msg: any, reactionKey: ReactionKey): string[] {
    const reactions = msg?.metadata?.reactions;
    if (!reactions || typeof reactions !== "object") return [];
    const buckets: any[] = [];
    if (Array.isArray(reactions[reactionKey])) {
        buckets.push(...reactions[reactionKey]);
    }
    // Backward-compat for old payloads that stored 💯 as "100".
    if (
        reactionKey === "hundred" &&
        Array.isArray(reactions["100"])
    ) {
        buckets.push(...reactions["100"]);
    }
    return Array.from(
        new Set(
            buckets
                .map((id: any) => normalizePublicId(id))
                .filter(Boolean),
        ),
    );
}

function getReactionCount(msg: any, reactionKey: ReactionKey) {
    return getReactionParticipants(msg, reactionKey).length;
}

function hasMyReaction(msg: any, reactionKey: ReactionKey) {
    const myId = normalizePublicId(meetingStore.localParticipant?.public_id);
    if (!myId) return false;
    return getReactionParticipants(msg, reactionKey).includes(myId);
}

function getVisibleReactions(msg: any) {
    return REACTION_OPTIONS.map((option) => ({
        ...option,
        count: getReactionCount(msg, option.key),
        active: hasMyReaction(msg, option.key),
    })).filter((item) => item.count > 0);
}

function isReactionMenuOpen(msg: any) {
    return reactionMenuMessageId.value === messageKey(msg);
}

function toggleReactionMenu(msg: any) {
    const key = messageKey(msg);
    if (!key) return;
    reactionMenuMessageId.value =
        reactionMenuMessageId.value === key ? null : key;
}

async function toggleMessageReaction(msg: any, reaction: ReactionKey) {
    if (!msg?.id) return;
    try {
        await meetingStore.toggleChatMessageReaction(msg.id, reaction);
    } catch (e) {
        console.error("Failed to toggle reaction", e);
    } finally {
        reactionMenuMessageId.value = null;
    }
}

function isNearBottom(el: HTMLElement, threshold = 72) {
    return el.scrollHeight - el.scrollTop - el.clientHeight <= threshold;
}

function jumpToLatest() {
    const el = messagesContainer.value;
    if (!el) return;
    el.scrollTop = el.scrollHeight;
    shouldStickToBottom.value = true;
    showJumpToLatest.value = false;
}

async function loadOlderMainMessages() {
    if (
        isLoadingOlderMain.value ||
        !hasMoreOlderMain.value ||
        activeThreadRootId.value ||
        !meetingStore.meeting?.public_id
    ) {
        return;
    }

    const oldest = topLevelMessages.value[0];
    if (!oldest?.id) {
        hasMoreOlderMain.value = false;
        return;
    }

    const container = messagesContainer.value;
    const previousHeight = container?.scrollHeight ?? 0;
    const previousTop = container?.scrollTop ?? 0;

    isLoadingOlderMain.value = true;
    try {
        const olderMessages = await meetingService.getMessages(
            meetingStore.meeting.public_id,
            {
                before: oldest.id,
                limit: MAIN_HISTORY_PAGE_SIZE,
            },
        );

        if (!olderMessages.length) {
            hasMoreOlderMain.value = false;
            return;
        }

        if (olderMessages.length < MAIN_HISTORY_PAGE_SIZE) {
            hasMoreOlderMain.value = false;
        }

        olderMessages.forEach((msg: any) => meetingStore.receiveChatMessage(msg));
        await nextTick();

        if (container) {
            const newHeight = container.scrollHeight;
            container.scrollTop = Math.max(
                0,
                newHeight - previousHeight + previousTop,
            );
        }
    } catch (e) {
        console.error("Failed to load older meeting messages", e);
    } finally {
        isLoadingOlderMain.value = false;
    }
}

function handleMessagesScroll() {
    const el = messagesContainer.value;
    if (!el) return;

    const nearBottom = isNearBottom(el);
    shouldStickToBottom.value = nearBottom;
    showJumpToLatest.value = !nearBottom;

    if (!activeThreadRootId.value && el.scrollTop <= 80) {
        loadOlderMainMessages();
    }
}

async function openThread(msg: any) {
    activeThreadRootId.value = String(msg.id);
    await loadThreadMessages(msg.id);
}

function closeThread() {
    activeThreadRootId.value = null;
}

async function loadThreadMessages(threadRootId: string | number) {
    if (!meetingStore.meeting?.public_id) return;
    isThreadLoading.value = true;
    try {
        const threadMessages = await meetingService.getMessages(
            meetingStore.meeting.public_id,
            { thread_root_id: threadRootId, limit: 300 },
        );
        threadMessages.forEach((msg: any) => meetingStore.receiveChatMessage(msg));
    } catch (e) {
        console.error("Failed to load thread messages", e);
    } finally {
        isThreadLoading.value = false;
    }
}

async function submitMessage() {
    const body = newMessage.value.trim();
    const files = pendingFiles.value.map((item) => item.file);
    if ((!body && files.length === 0) || isSending.value) return;

    if (isThrottleActive.value) {
        return;
    }

    const now = Date.now();
    if (now - lastSendAtMs.value < CLIENT_SEND_COOLDOWN_MS) {
        startThrottleCountdown(30);
        return;
    }

    const normalizedBody = normalizeBodyForThrottle(body);
    if (
        files.length === 0 &&
        normalizedBody &&
        normalizedBody === lastSentBodyHash.value &&
        now - lastSendAtMs.value < CLIENT_DUPLICATE_BODY_COOLDOWN_MS
    ) {
        startThrottleCountdown(30);
        return;
    }

    isSending.value = true;
    const hasFiles = files.length > 0;
    lastSendAtMs.value = now;
    lastSentBodyHash.value = hasFiles ? "" : normalizedBody;

    // Optimistic UI Append for text-only messages.
    const tempId = `temp-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    if (!hasFiles) {
        const optimisticMessage: any = {
            id: tempId,
            temp_id: tempId,
            participant_public_id:
                meetingStore.localParticipant?.public_id || "system",
            body: body,
            created_at: new Date().toISOString(),
        };

        if (activeThreadRootId.value) {
            optimisticMessage.reply_to_id = activeThreadRootId.value;
            optimisticMessage.thread_root_id = activeThreadRootId.value;
        }

        meetingStore.chatMessages.push(optimisticMessage);
    }

    newMessage.value = "";
    showEmoji.value = false;

    // Scroll to bottom immediately
    await nextTick();
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop =
            messagesContainer.value.scrollHeight;
    }

    try {
        await meetingStore.sendMessage(body, {
            tempId,
            replyTo: activeThreadRootId.value || undefined,
            files,
        });
        pendingFiles.value = [];
    } catch (e) {
        console.error("Failed to send message", e);
        const status = (e as any)?.response?.status;
        const responseMessage = String(
            (e as any)?.response?.data?.message || (e as any)?.message || "",
        );
        if (status === 429 || /too\s+many|rate\s*limit/i.test(responseMessage)) {
            const retryAfterRaw =
                (e as any)?.response?.headers?.["retry-after"] ??
                (e as any)?.response?.data?.retry_after ??
                null;
            const retryAfterSeconds = Number(retryAfterRaw);
            const throttleSeconds =
                Number.isFinite(retryAfterSeconds) && retryAfterSeconds > 0
                    ? Math.max(30, Math.ceil(retryAfterSeconds))
                    : 30;
            startThrottleCountdown(throttleSeconds);
        }
        // Allow immediate retry on failure.
        lastSentBodyHash.value = "";
        lastSendAtMs.value = 0;
        if (!hasFiles) {
            // Remove optimistic message if failed
            meetingStore.chatMessages = meetingStore.chatMessages.filter(
                (m: any) => m.id !== tempId,
            );
        }
    } finally {
        isSending.value = false;
        nextTick(() => {
            chatInputRef.value?.focus();
        });
    }
}

function pinBusyKey(msg: any) {
    return String(msg.public_id || msg.id || "");
}

function isPinBusy(msg: any) {
    return !!pinBusyMap.value[pinBusyKey(msg)];
}

async function togglePinned(msg: any) {
    if (!canModerateMessages.value) return;

    const key = pinBusyKey(msg);
    if (!key || pinBusyMap.value[key]) return;

    pinBusyMap.value[key] = true;
    try {
        if (msg.is_pinned) {
            await meetingStore.unpinChatMessage(msg.id);
        } else {
            await meetingStore.pinChatMessage(msg.id);
        }
    } catch (e) {
        console.error("Failed to toggle pinned state", e);
    } finally {
        pinBusyMap.value[key] = false;
    }
}

async function clearAllPinnedMessages() {
    if (!canModerateMessages.value) return;
    if (!pinnedMessages.value.length || isClearPinsBusy.value) return;

    const confirmed = window.confirm(
        "Clear all pinned messages for this meeting?",
    );
    if (!confirmed) return;

    isClearPinsBusy.value = true;
    try {
        await meetingStore.clearPinnedChatMessages();
    } catch (e) {
        console.error("Failed to clear pinned messages", e);
    } finally {
        isClearPinsBusy.value = false;
    }
}

// Emoji picker initialization
async function toggleEmoji() {
    showEmoji.value = !showEmoji.value;
    await nextTick();

    if (showEmoji.value && emojiMountRef.value) {
        const desiredTheme = themeStore.isDark ? "dark" : "light";
        if (!pickerInstance || pickerTheme !== desiredTheme) {
            emojiMountRef.value.textContent = "";
            pickerInstance = new Picker({
                data,
                onEmojiSelect: (emoji: any) => {
                    insertEmoji(emoji.native);
                },
                previewPosition: "none",
                theme: desiredTheme,
                perLine: 8,
                maxHeight: 250,
                searchPosition: "static",
                skinTonePosition: "none",
                onClickOutside: () => {},
            });
            pickerTheme = desiredTheme;
            emojiMountRef.value.appendChild(pickerInstance);
        }
    }
}

function insertEmoji(emoji: string) {
    const el = chatInputRef.value;
    const currentValue = newMessage.value;

    if (!el) {
        newMessage.value = currentValue + emoji;
        return;
    }

    const start = el.selectionStart || 0;
    const end = el.selectionEnd || 0;
    newMessage.value =
        currentValue.substring(0, start) + emoji + currentValue.substring(end);

    nextTick(() => {
        el.focus();
        el.selectionStart = el.selectionEnd = start + emoji.length;
    });
}

function handleClickOutside(e: MouseEvent) {
    const target = e.target as HTMLElement;

    // Check emoji button
    const emojiButton = document.querySelector('button[title="Insert emoji"]');
    if (emojiButton && emojiButton.contains(target)) return;

    if (
        showEmoji.value &&
        emojiMountRef.value &&
        !emojiMountRef.value.contains(target)
    ) {
        showEmoji.value = false;
    }

    const inReactionMenu = !!target.closest(".chat-reaction-menu");
    const inReactionTrigger = !!target.closest("[data-reaction-trigger]");
    if (!inReactionMenu && !inReactionTrigger) {
        reactionMenuMessageId.value = null;
    }

    if (!target.closest(".chat-message-item")) {
        activeActionMessageId.value = null;
    }
}

function handleEsc(e: KeyboardEvent) {
    if (e.key === "Escape" && showEmoji.value) {
        showEmoji.value = false;
    }
    if (e.key === "Escape" && reactionMenuMessageId.value) {
        reactionMenuMessageId.value = null;
    }
}

onMounted(() => {
    document.addEventListener("click", handleClickOutside);
    document.addEventListener("keydown", handleEsc);
});

onUnmounted(() => {
    document.removeEventListener("click", handleClickOutside);
    document.removeEventListener("keydown", handleEsc);
    pendingFiles.value = [];
    cancelMessageEdit();
    if (throttleTicker) {
        clearInterval(throttleTicker);
        throttleTicker = null;
    }
});

// Auto-scroll to bottom when new messages arrive only if user is already near latest.
watch(
    () => topLevelMessages.value.length + threadReplies.value.length,
    async () => {
        await nextTick();
        const el = messagesContainer.value;
        if (!el) return;
        if (shouldStickToBottom.value && !isLoadingOlderMain.value) {
            el.scrollTop = el.scrollHeight;
        }
        showJumpToLatest.value = !isNearBottom(el);
    },
    { immediate: true, flush: "post" },
);

watch(
    () => meetingStore.meeting?.public_id,
    () => {
        hasMoreOlderMain.value = true;
        shouldStickToBottom.value = true;
        showJumpToLatest.value = false;
        reactionMenuMessageId.value = null;
        unsafeUrlsByMessage.value = {};
        lastSentBodyHash.value = "";
        lastSendAtMs.value = 0;
        cancelMessageEdit();
        activeActionMessageId.value = null;
        throttleUntilMs.value = 0;
        throttleNowMs.value = Date.now();
    },
);

watch(activeThreadRootId, async () => {
    await nextTick();
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
    shouldStickToBottom.value = true;
    showJumpToLatest.value = false;
    reactionMenuMessageId.value = null;
    cancelMessageEdit();
    activeActionMessageId.value = null;
});
</script>

<style scoped>
.side-panel.chat-panel {
    display: flex;
    flex-direction: column;
    position: relative;
    width: min(420px, 100%);
    max-width: 100%;
    min-width: 0;
    background: linear-gradient(
        180deg,
        var(--chat-surface-1) 0%,
        var(--chat-surface-2) 100%
    );
    border-left: 1px solid var(--chat-header-border);
    color: var(--chat-text-primary);
    font-family: "Segoe UI", "Segoe UI Variable", "Helvetica Neue", Arial,
        sans-serif;
    --chat-surface-1: var(--surface-primary, #f3f4f8);
    --chat-surface-2: var(--surface-secondary, #eceff5);
    --chat-header-bg: var(--surface-secondary, #f7f8fb);
    --chat-header-border: var(--border-default, #d8dce7);
    --chat-header-text: var(--text-primary, #252b3a);
    --chat-control-bg: var(--surface-tertiary, #e8ebf3);
    --chat-control-border: var(--border-default, #cfd5e4);
    --chat-control-text: var(--text-secondary, #4a5267);
    --chat-control-hover-bg: color-mix(
        in srgb,
        var(--surface-tertiary, #dde3f0) 82%,
        var(--text-primary, #252b3a) 18%
    );
    --chat-control-hover-border: var(--border-default, #bec8dd);
    --chat-text-primary: var(--text-primary, #222b3a);
    --chat-text-muted: var(--text-secondary, #6f7992);
    --chat-bubble-bg: var(--surface-primary, #ffffff);
    --chat-bubble-border: var(--border-default, #d5dceb);
    --chat-bubble-text: var(--text-primary, #1f2633);
    --chat-bubble-me-bg: color-mix(
        in srgb,
        var(--color-primary-600, #4f5cc7) 90%,
        white 10%
    );
    --chat-bubble-me-border: color-mix(
        in srgb,
        var(--color-primary-600, #4f5cc7) 78%,
        white 22%
    );
    --chat-bubble-me-text: #f5f8ff;
    --chat-thread-action: var(--color-primary-500, #4f5ccf);
    --chat-thread-action-hover: var(--color-primary-400, #3c48b2);
    --chat-thread-card-bg: var(--surface-secondary, #eff3fd);
    --chat-thread-card-border: var(--border-default, #ccd6ec);
    --chat-thread-divider: var(--border-default, #ced5e4);
    --chat-pill-bg: var(--surface-tertiary, #eef2ff);
    --chat-pill-border: var(--border-default, #cad5f4);
    --chat-pill-text: var(--color-primary-500, #3a4ea3);
    --chat-pinned-section-bg: var(--surface-tertiary, #e7ebf4);
    --chat-pinned-section-border: var(--border-default, #cfd7e8);
    --chat-pinned-item-bg: var(--surface-primary, #fdfdff);
    --chat-pinned-item-border: var(--border-default, #d3dbee);
    --chat-input-area-bg: var(--surface-secondary, #eef1f7);
    --chat-input-bg: var(--surface-primary, #ffffff);
    --chat-input-border: var(--border-default, #cfd7e8);
    --chat-input-text: var(--text-primary, #20283a);
    --chat-input-placeholder: var(--text-secondary, #75809a);
    --chat-send-bg: var(--color-primary-600, #5b5fc7);
    --chat-send-bg-hover: var(--color-primary-500, #6f74dd);
    --chat-send-disabled-bg: var(--surface-tertiary, #cfd5e3);
    --chat-send-disabled-text: var(--text-secondary, #8d98ae);
}

.chat-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid var(--chat-header-border);
    flex-shrink: 0;
    background: var(--chat-header-bg);
}

.chat-header-left {
    display: flex;
    align-items: center;
    gap: 8px;
}

.thread-back-btn {
    background: var(--chat-control-bg);
    border: 1px solid var(--chat-control-border);
    color: var(--chat-control-text);
    font-size: 12px;
    line-height: 1;
    border-radius: 6px;
    padding: 6px 10px;
    cursor: pointer;
    transition: background-color 0.16s, border-color 0.16s, color 0.16s;
}

.thread-back-btn:hover {
    color: var(--chat-header-text);
    background: var(--chat-control-hover-bg);
    border-color: var(--chat-control-hover-border);
}

.chat-header h3 {
    font-size: 18px;
    font-weight: 600;
    color: var(--chat-header-text);
    margin: 0;
    letter-spacing: 0;
}

.chat-header-close {
    background: transparent;
    border: none;
    color: var(--chat-control-text);
    cursor: pointer;
    padding: 6px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.15s;
}

.chat-header-close:hover {
    background: var(--chat-control-hover-bg);
    color: var(--chat-header-text);
}

.emoji-picker-container {
    position: absolute;
    bottom: 100%;
    left: 8px;
    margin-bottom: 4px;
    z-index: 100;
    border: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    outline: none !important;
    background: transparent !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    padding: 0 !important;
    max-width: calc(100vw - 24px);
}

:deep(em-emoji-picker) {
    height: 350px !important;
    --em-height: 250px;
    width: min(344px, calc(100vw - 40px)) !important;
    border: none !important;
    box-shadow: none !important;
    outline: none !important;
    --shadow: none !important;
    --border-color: transparent !important;
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    overflow-x: visible;
    padding: 14px 14px 18px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.chat-message-list {
    display: flex;
    flex-direction: column;
}

.chat-history-loader {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--chat-text-muted);
    padding: 4px 10px 8px;
    margin: 0 auto;
    border: 1px solid var(--chat-control-border);
    border-radius: 999px;
    background: var(--chat-control-bg);
}

.chat-history-loader-spinner {
    width: 12px;
    height: 12px;
    border-radius: 999px;
    border: 2px solid color-mix(in srgb, var(--chat-control-border) 78%, transparent);
    border-top-color: var(--chat-send-bg);
    animation: chat-spin 0.75s linear infinite;
}

.chat-jump-latest-btn {
    position: absolute;
    right: 18px;
    bottom: 86px;
    z-index: 20;
    width: 30px;
    height: 30px;
    border-radius: 999px;
    border: 1px solid var(--chat-control-hover-border);
    background: var(--chat-input-bg);
    color: var(--chat-control-text);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.22);
    transition: transform 0.15s, background-color 0.15s, color 0.15s;
}

.chat-jump-latest-btn:hover {
    transform: translateY(-1px);
    background: var(--chat-control-hover-bg);
    color: var(--chat-header-text);
}

.chat-pinned-dock {
    padding: 10px 16px 8px;
    border-bottom: 1px solid var(--chat-header-border);
    background: var(--chat-pinned-section-bg);
    flex-shrink: 0;
}

.chat-pinned-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}

.pinned-header-actions {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.pinned-section-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-height: 110px;
    overflow-y: auto;
    padding-right: 2px;
}

.pinned-section-title {
    font-size: 11px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--chat-text-muted);
    margin-bottom: 0;
}

.pinned-dock-toggle {
    border: 1px solid var(--chat-control-border);
    background: var(--chat-control-bg);
    color: var(--chat-control-text);
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 11px;
    cursor: pointer;
}

.pinned-dock-toggle:hover {
    background: var(--chat-control-hover-bg);
    color: var(--chat-header-text);
}

.pinned-dock-toggle:disabled {
    opacity: 0.6;
    cursor: default;
}

.pinned-dock-toggle-danger {
    border-color: color-mix(in srgb, #ef4444 42%, var(--chat-control-border));
    color: color-mix(in srgb, #ef4444 84%, var(--chat-text-primary) 16%);
}

.pinned-dock-toggle-danger:hover:not(:disabled) {
    background: color-mix(in srgb, #ef4444 14%, var(--chat-control-bg) 86%);
    color: color-mix(in srgb, #ef4444 92%, #ffffff 8%);
}

.pinned-item {
    border: 1px solid var(--chat-pinned-item-border);
    background: var(--chat-pinned-item-bg);
    border-radius: 8px;
    padding: 7px 9px;
}

.pinned-item + .pinned-item {
    margin-top: 6px;
}

.pinned-item-body {
    font-size: 13px;
    color: var(--chat-text-primary);
    line-height: 1.35;
    word-break: break-word;
}

.pinned-item-body--clamped {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.pinned-item-toggle {
    margin-top: 4px;
    padding: 0;
    border: none;
    background: transparent;
    color: var(--chat-thread-action);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
}

.pinned-item-toggle:hover {
    color: var(--chat-thread-action-hover);
    text-decoration: underline;
}

.pinned-item-meta {
    margin-top: 4px;
    font-size: 11px;
    color: var(--chat-text-muted);
}

.chat-message-item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    margin-bottom: 2px;
    position: relative;
}

.chat-message-transition-enter-active,
.chat-message-transition-leave-active,
.chat-message-transition-move {
    transition:
        opacity 0.2s ease,
        transform 0.22s ease,
        max-height 0.22s ease,
        margin 0.22s ease;
}

.chat-message-transition-enter-from {
    opacity: 0;
    transform: translateY(10px) scale(0.98);
}

.chat-message-transition-leave-to {
    opacity: 0;
    transform: translateY(-6px) scale(0.98);
    max-height: 0;
    margin-top: 0;
    margin-bottom: 0;
}

.chat-date-divider {
    align-self: center;
    margin: 10px 0 6px;
    padding: 3px 10px;
    border-radius: 999px;
    border: 1px solid var(--chat-control-border);
    background: var(--chat-control-bg);
    color: var(--chat-text-muted);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.chat-message-me {
    align-items: flex-end;
}

.chat-message-header {
    display: grid;
    grid-template-columns: 20px 1fr auto;
    align-items: center;
    gap: 8px;
    margin-bottom: 5px;
    margin-top: 6px;
    padding-left: 0;
}

.chat-message-header-me {
    grid-template-columns: auto;
    justify-items: end;
    margin-top: 4px;
}

.chat-avatar {
    margin-right: 0;
}

.chat-sender-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--chat-text-primary);
    letter-spacing: 0;
}

.chat-time {
    font-size: 11px;
    color: var(--chat-text-muted);
    font-weight: 400;
}

.chat-bubble {
    font-size: 14px;
    color: var(--chat-bubble-text);
    background: var(--chat-bubble-bg);
    border: 1px solid var(--chat-bubble-border);
    padding: 8px 11px;
    border-radius: 14px 14px 14px 6px;
    word-break: break-word;
    line-height: 1.4;
    max-width: min(84%, 380px);
    margin-left: 28px;
    align-self: flex-start;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}

.chat-bubble-deleted {
    font-style: italic;
    color: var(--chat-text-muted);
}

.chat-message-me .chat-bubble {
    background: var(--chat-bubble-me-bg);
    border-color: var(--chat-bubble-me-border);
    color: var(--chat-bubble-me-text);
    margin-left: 0;
    max-width: min(80%, 340px);
    align-self: flex-end;
    border-radius: 14px 14px 6px 14px;
}

.chat-message-item.chat-message-grouped:not(.chat-message-me) .chat-bubble {
    border-top-left-radius: 8px;
}

.chat-message-item.chat-message-grouped.chat-message-me .chat-bubble {
    border-top-right-radius: 8px;
}

.chat-link-preview-wrap {
    margin-top: 6px;
    margin-left: 28px;
    max-width: min(320px, calc(100% - 28px));
    align-self: flex-start;
}

.thread-root-link-preview-wrap {
    margin-left: 0;
    max-width: 100%;
}

.chat-message-me .chat-link-preview-wrap {
    margin-left: 0;
    align-self: flex-end;
    max-width: min(84%, 320px);
}

.chat-unsafe-badge {
    margin-top: 6px;
    margin-left: 28px;
    align-self: flex-start;
    border: 1px solid color-mix(in srgb, #dc2626 46%, transparent);
    background: color-mix(in srgb, #dc2626 12%, transparent);
    color: color-mix(in srgb, #ef4444 82%, #ffffff 18%);
    border-radius: 10px;
    padding: 6px 9px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.2;
}

.thread-root-unsafe-badge {
    margin-left: 0;
}

.chat-message-me .chat-unsafe-badge {
    margin-left: 0;
    align-self: flex-end;
}

.chat-edit-wrap {
    width: 100%;
    max-width: min(88%, 420px);
    margin-left: 28px;
    align-self: flex-start;
}

.chat-message-me .chat-edit-wrap {
    margin-left: 0;
    max-width: min(84%, 420px);
    align-self: flex-end;
}

.chat-edit-input {
    width: 100%;
    border: 1px solid var(--chat-input-border);
    background: var(--chat-input-bg);
    color: var(--chat-input-text);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 13px;
    line-height: 1.4;
    resize: vertical;
}

.chat-edit-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 6px;
}

.chat-edit-actions .thread-action-btn {
    padding: 6px 10px;
    border-radius: 8px;
    border: 1px solid var(--chat-control-border);
    background: var(--chat-control-bg);
    color: var(--chat-control-text);
    font-size: 12px;
    line-height: 1;
    text-decoration: none;
}

.chat-edit-actions .thread-action-btn:hover {
    text-decoration: none;
}

.chat-edit-actions .thread-action-btn:not(.thread-action-btn-secondary) {
    border-color: color-mix(in srgb, var(--chat-send-bg) 50%, transparent);
    background: var(--chat-send-bg);
    color: #ffffff;
}

.chat-edit-actions .thread-action-btn:not(.thread-action-btn-secondary):hover {
    background: var(--chat-send-bg-hover);
}

.chat-edit-actions .thread-action-btn:disabled {
    opacity: 0.6;
    cursor: default;
}

.chat-media-bubble {
    margin-top: 6px;
    margin-left: 28px;
    align-self: flex-start;
    background: var(--chat-bubble-bg);
    border: 1px solid var(--chat-bubble-border);
    border-radius: 14px 14px 14px 6px;
    padding: 6px;
    max-width: min(84%, 380px);
}

.chat-message-me .chat-media-bubble {
    margin-left: 0;
    align-self: flex-end;
    background: color-mix(in srgb, var(--chat-bubble-me-bg) 22%, var(--chat-bubble-bg) 78%);
    border-color: color-mix(in srgb, var(--chat-bubble-me-border) 38%, var(--chat-bubble-border) 62%);
    border-radius: 14px 14px 6px 14px;
}

.thread-root-media-bubble {
    margin-left: 0;
    max-width: 100%;
}

.chat-image-grid {
    margin: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(84px, 1fr));
    gap: 6px;
    max-width: 100%;
    align-self: stretch;
}

.chat-image-grid-single {
    grid-template-columns: 1fr;
    max-width: min(240px, 100%);
}

.chat-image-grid-two {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.chat-image-grid-three,
.chat-image-grid-many {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.chat-image-link {
    position: relative;
    appearance: none;
    cursor: pointer;
    display: block;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--chat-bubble-border);
    background: var(--chat-bubble-bg);
    padding: 0;
}

.chat-image {
    width: 100%;
    height: 100%;
    max-height: 140px;
    object-fit: cover;
    display: block;
}

.chat-image-item-wide {
    grid-column: span 2;
    aspect-ratio: 2 / 1;
}

.chat-image-overlay {
    position: absolute;
    inset: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(9, 12, 18, 0.62);
    color: #ffffff;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 0.01em;
}

.chat-file-list {
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-width: 100%;
    align-self: stretch;
}

.chat-file-link {
    display: flex;
    align-items: center;
    gap: 7px;
    text-decoration: none;
    color: var(--chat-bubble-text);
    background: var(--chat-bubble-bg);
    border: 1px solid var(--chat-bubble-border);
    border-radius: 8px;
    padding: 7px 9px;
}

.chat-message-me .chat-file-link {
    color: var(--chat-bubble-me-text);
    background: color-mix(in srgb, var(--chat-bubble-me-bg) 72%, #0d1324 28%);
    border-color: color-mix(in srgb, var(--chat-bubble-me-border) 68%, #000000 32%);
}

.chat-file-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
}

.chat-file-size {
    font-size: 11px;
    color: var(--chat-text-muted);
}

.chat-message-me .chat-file-size {
    color: color-mix(in srgb, var(--chat-bubble-me-text) 70%, transparent);
}

.message-pill {
    margin-top: 4px;
    margin-left: 28px;
    align-self: flex-start;
    background: var(--chat-pill-bg);
    border: 1px solid var(--chat-pill-border);
    color: var(--chat-pill-text);
    font-size: 11px;
    line-height: 1;
    border-radius: 999px;
    padding: 4px 8px;
    font-weight: 600;
}

.message-pill-muted {
    background: var(--chat-control-bg);
    border-color: var(--chat-control-border);
    color: var(--chat-control-text);
}

.thread-pill {
    margin-top: 0;
    margin-left: 0;
}

.chat-message-me .message-pill {
    margin-left: 0;
    align-self: flex-end;
}

.chat-reactions-row {
    margin-top: 6px;
    margin-left: 28px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-self: flex-start;
}

.thread-root-reactions {
    margin-left: 0;
}

.chat-message-me .chat-reactions-row {
    margin-left: 0;
    align-self: flex-end;
}

.chat-reaction-chip {
    border: 1px solid var(--chat-control-border);
    background: var(--chat-control-bg);
    color: var(--chat-text-primary);
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.2;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
}

.chat-reaction-chip.is-active {
    border-color: color-mix(in srgb, var(--chat-send-bg) 72%, transparent);
    background: color-mix(in srgb, var(--chat-send-bg) 22%, var(--chat-control-bg) 78%);
}

.chat-reaction-menu {
    position: absolute;
    z-index: 30;
    top: calc(100% - 4px);
    left: 28px;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    padding: 8px;
    border-radius: 10px;
    border: 1px solid var(--chat-control-border);
    background: var(--chat-input-bg);
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.26);
    max-width: min(320px, calc(100% - 28px));
}

.chat-message-me .chat-reaction-menu {
    left: auto;
    right: 0;
}

.chat-reaction-option {
    border: 1px solid var(--chat-control-border);
    background: var(--chat-control-bg);
    color: var(--chat-text-primary);
    border-radius: 8px;
    padding: 4px 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
}

.chat-reaction-option.is-active {
    border-color: color-mix(in srgb, var(--chat-send-bg) 78%, transparent);
    background: color-mix(in srgb, var(--chat-send-bg) 18%, var(--chat-control-bg) 82%);
}

.chat-reaction-option-emoji {
    font-size: 14px;
    line-height: 1;
}

.chat-reaction-option-label {
    line-height: 1;
}

.thread-actions {
    margin-top: 0;
    margin-left: 28px;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    max-height: 0;
    overflow: hidden;
    opacity: 0;
    pointer-events: none;
    transform: translateY(-2px);
    transition:
        opacity 0.16s ease,
        transform 0.16s ease,
        max-height 0.16s ease,
        margin-top 0.16s ease;
}

.chat-message-me .thread-actions {
    margin-left: 0;
    align-self: flex-end;
}

.chat-message-item.chat-message-actions-open .thread-actions,
.chat-message-item:focus-within .thread-actions {
    max-height: 46px;
    margin-top: 4px;
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
}

@media (hover: hover) {
    .chat-message-item:hover .thread-actions {
        max-height: 46px;
        margin-top: 4px;
        opacity: 1;
        pointer-events: auto;
        transform: translateY(0);
    }
}

.thread-action-btn {
    background: transparent;
    border: none;
    color: var(--chat-thread-action);
    font-size: 12px;
    padding: 0;
    cursor: pointer;
    font-weight: 500;
    letter-spacing: 0.01em;
}

.thread-action-btn:hover {
    text-decoration: underline;
    color: var(--chat-thread-action-hover);
}

.thread-root-card {
    border: 1px solid var(--chat-thread-card-border);
    background: var(--chat-thread-card-bg);
    border-radius: 10px;
    padding: 12px;
    margin-bottom: 6px;
}

.thread-root-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.thread-root-bubble {
    max-width: 100%;
    margin-left: 0;
}

.thread-root-card .chat-image-grid,
.thread-root-card .chat-file-list {
    margin-left: 0;
    max-width: 100%;
}

.thread-root-card .chat-media-bubble,
.thread-root-card .chat-reactions-row,
.thread-root-card .chat-unsafe-badge {
    margin-left: 0;
    max-width: 100%;
}

.thread-divider {
    font-size: 11px;
    color: var(--chat-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.09em;
    margin: 6px 0 8px;
    padding-top: 8px;
    border-top: 1px solid var(--chat-thread-divider);
}

.thread-loading,
.thread-empty {
    font-size: 13px;
    color: var(--chat-text-muted);
    margin: 8px 0 12px;
}

@keyframes chat-spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

.chat-input-area {
    padding: 12px 14px 14px;
    border-top: 1px solid var(--chat-header-border);
    background: var(--chat-input-area-bg);
}

.chat-pending-files {
    margin-bottom: 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.chat-pending-file {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--chat-bubble-bg);
    border: 1px solid var(--chat-bubble-border);
    color: var(--chat-bubble-text);
    border-radius: 999px;
    padding: 4px 8px;
    max-width: 100%;
}

.chat-pending-file-name {
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
}

.chat-pending-file-size {
    font-size: 11px;
    color: var(--chat-text-muted);
}

.chat-pending-file-remove {
    border: none;
    background: transparent;
    color: var(--chat-control-text);
    cursor: pointer;
    line-height: 0;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.chat-send-feedback {
    margin-bottom: 8px;
    font-size: 12px;
    line-height: 1.35;
    color: color-mix(in srgb, #ef4444 84%, var(--chat-text-primary) 16%);
    background: color-mix(in srgb, #ef4444 12%, transparent);
    border: 1px solid color-mix(in srgb, #ef4444 36%, transparent);
    border-radius: 8px;
    padding: 6px 8px;
}

.chat-form {
    display: flex;
    align-items: center;
    background: var(--chat-input-bg);
    border: 1px solid var(--chat-input-border);
    border-radius: 10px;
    padding: 2px 10px;
    gap: 4px;
}

.chat-action-btn {
    background: transparent;
    border: none;
    color: var(--chat-control-text);
    cursor: pointer;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.18s, color 0.18s;
}

.chat-action-btn:hover:not(:disabled) {
    background: var(--chat-control-hover-bg);
    color: var(--chat-header-text);
}

.chat-action-btn:disabled {
    opacity: 0.55;
    cursor: default;
}

.chat-input {
    flex: 1;
    background: transparent;
    border: none;
    color: var(--chat-input-text);
    font-size: 14px;
    padding: 10px 4px;
    outline: none;
}

.chat-input::placeholder {
    color: var(--chat-input-placeholder);
}

.chat-send-btn {
    color: #ffffff;
    background: var(--chat-send-bg);
    border: none;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s, transform 0.1s;
}

.chat-send-btn:hover:not(:disabled) {
    background: var(--chat-send-bg-hover);
}

.chat-send-btn:disabled {
    background: var(--chat-send-disabled-bg);
    color: var(--chat-send-disabled-text);
    cursor: default;
}

.panel-empty {
    margin: auto;
    width: 100%;
    max-width: 280px;
    text-align: center;
    color: var(--chat-text-muted);
}

.panel-empty p {
    margin-top: 10px;
    font-size: 13px;
    line-height: 1.4;
}

@media (max-width: 900px) {
    .side-panel.chat-panel {
        width: 100%;
        max-width: 100%;
        border-left: none;
    }

    .chat-header {
        padding: 12px;
    }

    .chat-pinned-dock {
        padding: 8px 12px 6px;
    }

    .chat-messages {
        padding: 10px 10px 14px;
    }

    .chat-jump-latest-btn {
        right: 14px;
        bottom: 82px;
    }

    .chat-bubble,
    .chat-media-bubble,
    .chat-edit-wrap,
    .chat-unsafe-badge,
    .chat-reactions-row,
    .chat-link-preview-wrap,
    .chat-image-grid,
    .chat-file-list,
    .thread-actions,
    .message-pill {
        margin-left: 0;
        max-width: 100%;
    }

    .chat-message-header {
        grid-template-columns: auto 1fr auto;
    }

    .chat-input-area {
        padding: 10px 10px calc(10px + env(safe-area-inset-bottom));
    }

    .chat-message-item.chat-message-actions-open .thread-actions {
        max-height: 72px;
    }

    .chat-reaction-menu {
        left: 0;
        right: 0;
        top: calc(100% + 2px);
        max-width: 100%;
    }

    .emoji-picker-container {
        left: 50%;
        transform: translateX(-50%);
        width: min(344px, calc(100vw - 20px));
    }

    :deep(em-emoji-picker) {
        width: 100% !important;
        height: 350px !important;
    }
}
</style>
