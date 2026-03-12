<template>
    <div class="gmeet-root">
        <!-- Waiting Room Overlay (Participant Only) -->
        <Transition
            enter-active-class="transition duration-500 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition duration-300 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div v-if="isWaiting" class="waiting-overlay">
                <div class="waiting-content">
                    <div class="waiting-icon-wrap">
                        <div class="waiting-icon-circle">
                            <Icon
                                name="lock"
                                size="40"
                                class="text-indigo-400"
                            />
                        </div>
                        <div class="waiting-ping">
                            <div class="ping-dot"></div>
                        </div>
                    </div>
                    <h1 class="waiting-title">{{ waitingTitle }}</h1>
                    <p class="waiting-desc">{{ waitingDescription }}</p>
                    <div class="waiting-meta">
                        <div class="waiting-host-badge">
                            <Avatar
                                :src="meetingStore?.meeting?.host?.avatar_url"
                                :fallback="
                                    meetingStore?.meeting?.host?.name?.charAt(
                                        0,
                                    ) || 'H'
                                "
                                :color="meetingStore?.meeting?.host?.color"
                                size="sm"
                                class="waiting-host-avatar shrink-0"
                            />
                            <span>Host: {{ meetingHostName }}</span>
                        </div>
                        <button
                            @click="
                                router.push({
                                    name: 'meeting-lobby',
                                    params: { id: meetingId },
                                })
                            "
                            class="waiting-cancel-btn"
                        >
                            Cancel and return to lobby
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Initializing Overlay -->
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-500 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="initializing" class="initializing-overlay">
                <div class="initializing-content">
                    <div class="loading-ring"></div>
                    <h2 class="initializing-title">Entering Room...</h2>
                    <p class="initializing-subtitle">
                        Setting up secure media session
                    </p>
                </div>
            </div>
        </Transition>

        <!-- Meeting Details Overlay -->
        <aside v-if="meetingStore?.meeting" class="meeting-details-overlay">
            <Transition name="panel">
                <div
                    v-if="showMeetingDetails"
                    class="details-panel"
                    v-click-outside="() => (showMeetingDetails = false)"
                >
                    <div class="details-header">
                        <h3>Meeting Info</h3>
                        <button
                            @click="showMeetingDetails = false"
                            class="details-close"
                        >
                            <Icon name="x" size="18" />
                        </button>
                    </div>

                    <div class="details-body">
                        <!-- ID & Title section -->
                        <div class="details-group">
                            <label>Room Details</label>
                            <div class="detail-row">
                                <div class="detail-label">Title</div>
                                <div class="detail-value">
                                    {{ meetingStore.meeting.title }}
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Meeting ID</div>
                                <div class="detail-value font-mono">
                                    {{ meetingId }}
                                </div>
                                <button
                                    @click="
                                        copyToClipboard(meetingId, 'Meeting ID')
                                    "
                                    class="copy-small-btn"
                                >
                                    <Icon name="copy" size="14" />
                                </button>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Created</div>
                                <div class="detail-value">
                                    {{
                                        formatDate(
                                            meetingStore.meeting.created_at,
                                        )
                                    }}
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Last Updated</div>
                                <div class="detail-value">
                                    {{
                                        formatDate(
                                            meetingStore.meeting.updated_at,
                                        )
                                    }}
                                </div>
                            </div>
                        </div>

                        <!-- Security section -->
                        <div class="details-group">
                            <label>Security & Access</label>
                            <div class="detail-row">
                                <div class="detail-label">Room Lock</div>
                                <div
                                    class="detail-value"
                                    :class="
                                        meetingStore.isLocked
                                            ? 'text-red-400'
                                            : 'text-green-400'
                                    "
                                >
                                    {{
                                        meetingStore.isLocked
                                            ? "Locked"
                                            : "Unlocked"
                                    }}
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">
                                    Lobby/Waiting Room
                                </div>
                                <div class="detail-value">
                                    {{
                                        meetingStore.meeting.settings
                                            .lobby_enabled
                                            ? "Enabled"
                                            : "Disabled"
                                    }}
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">
                                    Host/Co-host First
                                </div>
                                <div class="detail-value">
                                    {{
                                        meetingStore.meeting.settings
                                            .require_host_or_cohost_present
                                            ? "Required"
                                            : "Not required"
                                    }}
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Guest Access</div>
                                <div class="detail-value">
                                    {{
                                        meetingStore.meeting.settings
                                            .guest_access
                                            ? "Allowed"
                                            : "Not allowed"
                                    }}
                                </div>
                            </div>
                            <div
                                v-if="meetingStore.meeting.has_password"
                                class="detail-row"
                            >
                                <div class="detail-label">Passcode</div>
                                <div
                                    v-if="meetingStore.meeting.password"
                                    class="detail-value font-mono"
                                >
                                    {{ meetingPasscodeDisplay }}
                                </div>
                                <div v-else class="detail-value">
                                    Protected. Ask the host for the passcode.
                                </div>
                                <button
                                    v-if="meetingStore.meeting.password"
                                    @click="showMeetingPasscode = !showMeetingPasscode"
                                    class="copy-small-btn"
                                    :title="
                                        showMeetingPasscode
                                            ? 'Hide passcode'
                                            : 'Show passcode'
                                    "
                                >
                                    <Icon
                                        :name="
                                            showMeetingPasscode
                                                ? 'eye-off'
                                                : 'eye'
                                        "
                                        size="14"
                                    />
                                </button>
                                <button
                                    v-if="meetingStore.meeting.password"
                                    @click="
                                        copyToClipboard(
                                            meetingStore.meeting.password,
                                            'Passcode',
                                        )
                                    "
                                    class="copy-small-btn"
                                >
                                    <Icon name="copy" size="14" />
                                </button>
                            </div>
                        </div>

                        <!-- Link section -->
                        <div class="details-group">
                            <label>Joining Info</label>
                            <div class="link-box">
                                <p class="text-xs text-secondary mb-2 truncate">
                                    {{ meetingShareUrl || "Link unavailable" }}
                                </p>
                                <button
                                    @click="copyMeetingLink"
                                    class="copy-full-link-btn"
                                >
                                    <Icon name="copy" size="16" class="mr-2" />
                                    <span>Copy joining link</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </aside>

        <!-- Main Content Area -->
        <div
            class="gmeet-body"
            :class="{
                'has-panel':
                    showParticipantsPanel || showChatPanel || showPollPanel,
            }"
        >
            <div class="gmeet-stage" style="position: relative">
                <!-- Poor Connection Notice -->
                <Transition
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="opacity-0 -translate-y-4"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition duration-200 ease-in"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 -translate-y-4"
                >
                    <div
                        v-if="poorConnectionDetected"
                        class="poor-connection-banner"
                    >
                        <div class="poor-connection-content">
                            <Icon
                                name="alert-triangle"
                                size="18"
                                class="text-red-400"
                            />
                            <div class="poor-connection-text">
                                <span class="font-bold">Poor Connection:</span>
                                Meeting quality may be affected.
                            </div>
                            <button
                                @click="meetingStore.resetSFUSession()"
                                class="poor-connection-action"
                            >
                                <Icon name="refresh-cw" size="14" />
                                <span>Reconnect</span>
                            </button>
                        </div>
                    </div>
                </Transition>

                <!-- Laser Pointer Overlay: transparent, sits above video grid -->

                <template v-if="isSpotlightMode && spotlightTile">
                    <div class="spotlight-main">
                        <ParticipantTile
                            :participant="spotlightTile.participant"
                            :is-spotlight="true"
                            :is-screen-share="spotlightTile.isScreen"
                            :local-camera-on="isCameraOn"
                            :local-mic-on="isMicOn"
                            :is-loading="isTileLoading(spotlightTile, 'stage')"
                            :loading-label="
                                getTileLoadingLabel(spotlightTile, 'stage')
                            "
                            :local-stream-override="
                                spotlightTile.isScreen ? screenStream : null
                            "
                        />
                    </div>
                    <div class="filmstrip" v-if="paginatedTiles.length > 0">
                        <button
                            v-if="filmstripPage > 0"
                            @click="prevPage"
                            class="filmstrip-nav filmstrip-nav--left"
                        >
                            <Icon name="chevron-left" size="16" />
                        </button>
                        <div class="filmstrip-tiles">
                            <div
                                v-for="tile in paginatedTiles"
                                :key="tile.id"
                                class="filmstrip-tile"
                            >
                                <ParticipantTile
                                    :participant="tile.participant"
                                    :is-screen-share="tile.isScreen"
                                    :local-camera-on="isCameraOn"
                                    :local-mic-on="isMicOn"
                                    :is-loading="isTileLoading(tile)"
                                    :loading-label="getTileLoadingLabel(tile)"
                                    :local-stream-override="
                                        tile.isScreen ? screenStream : null
                                    "
                                />
                            </div>
                        </div>
                        <button
                            v-if="filmstripPage < totalFilmstripPages - 1"
                            @click="nextPage"
                            class="filmstrip-nav filmstrip-nav--right"
                        >
                            <Icon name="chevron-right" size="16" />
                        </button>
                    </div>
                </template>

                <!-- Grid Mode: Equal-sized tiles -->
                <template v-else-if="paginatedTiles.length > 0">
                    <div class="grid-container" :class="gridLayoutClass">
                        <div
                            v-for="tile in paginatedTiles"
                            :key="tile.id"
                            class="grid-tile"
                        >
                            <ParticipantTile
                                :participant="tile.participant"
                                :is-screen-share="tile.isScreen"
                                :local-camera-on="isCameraOn"
                                :local-mic-on="isMicOn"
                                :is-loading="isTileLoading(tile)"
                                :loading-label="getTileLoadingLabel(tile)"
                                :local-stream-override="
                                    tile.isScreen ? screenStream : null
                                "
                            />
                        </div>
                    </div>
                    <!-- Grid Pagination Arrows -->
                    <button
                        v-if="gridPage > 0"
                        @click="prevPage"
                        class="grid-nav grid-nav--left"
                    >
                        <Icon name="chevron-left" size="20" />
                    </button>
                    <button
                        v-if="gridPage < totalGridPages - 1"
                        @click="nextPage"
                        class="grid-nav grid-nav--right"
                    >
                        <Icon name="chevron-right" size="20" />
                    </button>
                </template>

                <!-- Solo/Empty State when alone with no camera -->
                <template v-else>
                    <div class="solo-empty-state">
                        <div class="ambient-background">
                            <div class="blob blob-1"></div>
                            <div class="blob blob-2"></div>
                            <div class="blob blob-3"></div>
                        </div>
                        <div
                            v-if="isCameraOn"
                            class="solo-camera-wrapper m-auto relative z-10 w-full max-w-[800px] aspect-video rounded-xl shadow-[0_12px_40px_rgba(0,0,0,0.5)] overflow-hidden"
                        >
                            <ParticipantTile
                                class="solo-camera-tile border-accent w-full h-full"
                                :participant="meetingStore.localParticipant"
                                :is-local="true"
                                :local-camera-on="isCameraOn"
                                :local-mic-on="isMicOn"
                                :is-loading="isCameraToggleBusy"
                                loading-label="Updating camera..."
                            />
                        </div>
                        <div v-else class="solo-content glass-panel">
                            <div class="solo-avatar-wrap">
                                <Avatar
                                    :src="
                                        meetingStore.localParticipant?.user
                                            ?.avatar_url ||
                                        meetingStore.localParticipant?.metadata
                                            ?.avatar_url
                                    "
                                    :fallback="
                                        getParticipantInitial(
                                            meetingStore.localParticipant,
                                        )
                                    "
                                    :color="
                                        meetingStore.localParticipant?.user
                                            ?.color
                                    "
                                    size="5xl"
                                    class="solo-avatar-comp"
                                />
                            </div>
                            <div class="solo-info">
                                <h2 class="solo-name">
                                    You're the only one here
                                </h2>
                                <p class="solo-hint">
                                    Share the meeting link with others you want
                                    in the meeting.
                                </p>
                                <p class="solo-hint-sub">
                                    They will need the meeting password if one
                                    is set.
                                </p>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- PiP Self-View -->
                <Transition
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="opacity-0 translateY-4"
                    enter-to-class="opacity-100 translateY-0"
                    leave-active-class="transition duration-200 ease-in"
                    leave-from-class="opacity-100 translateY-0"
                    leave-to-class="opacity-0 translateY-4"
                >
                    <div
                        v-if="localCameraTile && shouldShowPiPSelfView"
                        class="pip-self-view"
                    >
                        <ParticipantTile
                            :participant="localCameraTile.participant"
                            :is-screen-share="false"
                            :local-camera-on="isCameraOn"
                            :local-mic-on="isMicOn"
                            :is-local="true"
                            :is-loading="isCameraToggleBusy"
                            loading-label="Updating camera..."
                        />
                    </div>
                </Transition>

                <!-- Whiteboard Layer -->
                <Transition
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="opacity-0 scale-95"
                    enter-to-class="opacity-100 scale-100"
                    leave-active-class="transition duration-200 ease-in"
                    leave-from-class="opacity-100 scale-100"
                    leave-to-class="opacity-0 scale-95"
                >
                    <WhiteboardView v-if="whiteboardStore.isVisible" />
                </Transition>
            </div>
            <!-- Breakout Room Layer -->
            <BreakoutOverlay v-if="meetingStore.activeBreakoutSession" />
            <BreakoutDashboard
                v-if="meetingStore.activeBreakoutSession && meetingStore.isHost"
            />

            <!-- Participants Side Panel -->
            <Transition
                enter-active-class="transition duration-250 ease-out"
                enter-from-class="translate-x-full opacity-0"
                enter-to-class="translate-x-0 opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="translate-x-0 opacity-100"
                leave-to-class="translate-x-full opacity-0"
            >
                <aside v-if="showParticipantsPanel" class="side-panel">
                    <div class="side-panel-header">
                        <div class="participant-tabs">
                            <div
                                class="tab-item"
                                :class="{
                                    'tab-item--active':
                                        activeParticipantTab === 'members',
                                }"
                                @click="activeParticipantTab = 'members'"
                            >
                                Members ({{ participantCount }})
                            </div>
                            <div
                                v-if="meetingStore.isModerator"
                                class="tab-item relative"
                                :class="{
                                    'tab-item--active':
                                        activeParticipantTab === 'waiting',
                                }"
                                @click="activeParticipantTab = 'waiting'"
                            >
                                Requests
                                <span
                                    v-if="
                                        meetingStore.waitingParticipants
                                            .length > 0
                                    "
                                    class="requests-badge"
                                >
                                    {{
                                        meetingStore.waitingParticipants.length
                                    }}
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="
                                    meetingStore.isHost &&
                                    meetingStore.raisedHands.size > 0 &&
                                    activeParticipantTab === 'members'
                                "
                                @click="lowerAllHands"
                                class="text-xs px-3 py-1.5 bg-surface-tertiary hover:bg-border-strong text-primary rounded-full transition-colors border-none cursor-pointer whitespace-nowrap"
                                title="Lower all raised hands"
                            >
                                ✋ Lower all
                            </button>
                            <button
                                @click="showParticipantsPanel = false"
                                class="p-1 hover:bg-surface-tertiary rounded-full transition-colors side-panel-close text-secondary"
                            >
                                <Icon name="x" size="20" />
                            </button>
                        </div>
                    </div>

                    <div class="side-panel-body">
                        <!-- Members Tab -->
                        <div
                            v-if="activeParticipantTab === 'members'"
                            class="participant-list"
                        >
                            <div
                                v-for="p in meetingStore.allParticipants"
                                :key="p.public_id"
                                class="participant-row"
                            >
                                <Avatar
                                    :src="
                                        p.user?.avatar_url ||
                                        p.metadata?.avatar_url
                                    "
                                    :fallback="getParticipantInitial(p)"
                                    :color="p.user?.color"
                                    size="sm"
                                    class="mr-3 shrink-0"
                                />
                                <div class="participant-info">
                                    <span class="participant-name">
                                        {{ getParticipantName(p) }}
                                        <span
                                            v-if="
                                                p.public_id ===
                                                meetingStore.localParticipant
                                                    ?.public_id
                                            "
                                            class="you-badge"
                                            >You</span
                                        >
                                        <span
                                            v-if="p.role === 'host'"
                                            class="ml-2 text-[10px] text-blue-400 bg-blue-400/10 px-1.5 py-0.5 rounded border border-blue-400/20"
                                            >HOST</span
                                        >
                                        <span
                                            v-if="p.role === 'co-host'"
                                            class="ml-2 text-[10px] text-purple-400 bg-purple-400/10 px-1.5 py-0.5 rounded border border-purple-400/20"
                                            >CO-HOST</span
                                        >
                                    </span>
                                </div>
                                <div
                                    class="participant-status-icons flex items-center space-x-2"
                                >
                                    <Icon
                                        v-if="
                                            meetingStore.raisedHands.has(
                                                p.public_id,
                                            )
                                        "
                                        name="hand"
                                        size="14"
                                        class="text-amber-400 animate-bounce"
                                    />
                                    <Icon
                                        :name="
                                            isParticipantMicOn(p)
                                                ? 'mic'
                                                : 'mic-off'
                                        "
                                        size="14"
                                        :class="
                                            isParticipantMicOn(p)
                                                ? 'text-green-500'
                                                : 'text-red-500'
                                        "
                                    />
                                    <Icon
                                        :name="
                                            isParticipantVideoOn(p)
                                                ? 'video'
                                                : 'video-off'
                                        "
                                        size="14"
                                        :class="
                                            isParticipantVideoOn(p)
                                                ? 'text-green-500'
                                                : 'text-red-500'
                                        "
                                    />

                                    <!-- Moderator Menu -->
                                    <!-- Moderator Menu -->
                                    <DropdownMenuRoot
                                        v-if="
                                            meetingStore.isModerator &&
                                            p.public_id !==
                                                meetingStore.localParticipant
                                                    ?.public_id &&
                                            p.role !== 'host'
                                        "
                                    >
                                        <DropdownMenuTrigger as-child>
                                            <button
                                                class="p-1 hover:bg-surface-tertiary rounded-full text-secondary transition-colors outline-none"
                                            >
                                                <Icon
                                                    name="more-vertical"
                                                    size="16"
                                                />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuPortal>
                                            <DropdownMenuContent
                                                side="bottom"
                                                align="end"
                                                :side-offset="5"
                                                class="z-50 min-w-[180px] bg-surface-elevated border border-default rounded-lg shadow-xl overflow-hidden py-1 animate-in fade-in zoom-in-95 duration-100"
                                            >
                                                <DropdownMenuItem
                                                    @click="
                                                        p.is_muted_by_host
                                                            ? meetingStore.unmuteParticipant(
                                                                  p.public_id,
                                                              )
                                                            : meetingStore.muteParticipant(
                                                                  p.public_id,
                                                              )
                                                    "
                                                    class="group flex items-center w-full px-4 py-2 text-sm text-primary cursor-pointer outline-none hover:bg-blue-500/10 hover:text-blue-400 data-highlighted:bg-blue-500/10 data-highlighted:text-blue-400 transition-colors"
                                                >
                                                    <Icon
                                                        :name="
                                                            p.is_muted_by_host
                                                                ? 'mic'
                                                                : 'mic-off'
                                                        "
                                                        size="16"
                                                        class="mr-3 text-secondary group-hover:text-blue-400 transition-colors"
                                                    />
                                                    {{
                                                        p.is_muted_by_host
                                                            ? "Allow Unmute"
                                                            : "Mute Microphone"
                                                    }}
                                                </DropdownMenuItem>

                                                <DropdownMenuItem
                                                    @click="
                                                        p.is_camera_disabled_by_host
                                                            ? meetingStore.allowCamera(
                                                                  p.public_id,
                                                              )
                                                            : meetingStore.disableCamera(
                                                                  p.public_id,
                                                              )
                                                    "
                                                    class="group flex items-center w-full px-4 py-2 text-sm text-primary cursor-pointer outline-none hover:bg-blue-500/10 hover:text-blue-400 data-highlighted:bg-blue-500/10 data-highlighted:text-blue-400 transition-colors"
                                                >
                                                    <Icon
                                                        :name="
                                                            p.is_camera_disabled_by_host
                                                                ? 'video'
                                                                : 'video-off'
                                                        "
                                                        size="16"
                                                        class="mr-3 text-secondary group-hover:text-blue-400 transition-colors"
                                                    />
                                                    {{
                                                        p.is_camera_disabled_by_host
                                                            ? "Allow Camera"
                                                            : "Turn Off Camera"
                                                    }}
                                                </DropdownMenuItem>

                                                <template
                                                    v-if="meetingStore.isHost"
                                                >
                                                    <DropdownMenuSeparator
                                                        class="h-px bg-default mx-2 my-1"
                                                    />
                                                    <DropdownMenuItem
                                                        @click="
                                                            p.role ===
                                                            'participant'
                                                                ? meetingStore.promoteParticipant(
                                                                      p.public_id,
                                                                  )
                                                                : meetingStore.demoteParticipant(
                                                                      p.public_id,
                                                                  )
                                                        "
                                                        class="group flex items-center w-full px-4 py-2 text-sm text-primary cursor-pointer outline-none hover:bg-blue-500/10 hover:text-blue-400 data-highlighted:bg-blue-500/10 data-highlighted:text-blue-400 transition-colors"
                                                    >
                                                        <Icon
                                                            :name="
                                                                p.role ===
                                                                'participant'
                                                                    ? 'shield'
                                                                    : 'shield-off'
                                                            "
                                                            size="16"
                                                            class="mr-3 text-secondary group-hover:text-blue-400 transition-colors"
                                                        />
                                                        {{
                                                            p.role ===
                                                            "participant"
                                                                ? "Make Co-host"
                                                                : "Remove Co-host"
                                                        }}
                                                    </DropdownMenuItem>
                                                </template>

                                                <DropdownMenuSeparator
                                                    class="h-px bg-default mx-2 my-1"
                                                />
                                                <DropdownMenuItem
                                                    @click="
                                                        meetingStore.kickParticipant(
                                                            p.public_id,
                                                        )
                                                    "
                                                    class="group flex items-center w-full px-4 py-2 text-sm text-red-400 cursor-pointer outline-none hover:bg-red-500/20 hover:text-red-500 data-highlighted:bg-red-500/20 data-highlighted:text-red-500 transition-colors"
                                                >
                                                    <Icon
                                                        name="minus-circle"
                                                        size="16"
                                                        class="mr-3 text-red-400 group-hover:text-red-500 transition-colors"
                                                    />
                                                    Remove from meeting
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenuPortal>
                                    </DropdownMenuRoot>
                                </div>
                            </div>
                        </div>

                        <!-- Requests Tab -->
                        <div
                            v-else-if="activeParticipantTab === 'waiting'"
                            class="participant-list"
                        >
                            <div
                                v-if="
                                    meetingStore.waitingParticipants.length ===
                                    0
                                "
                                class="py-10 text-center text-gray-500 text-sm"
                            >
                                <Icon
                                    name="users"
                                    size="32"
                                    class="mx-auto mb-2 opacity-20"
                                />
                                No pending join requests
                            </div>
                            <div
                                v-for="p in meetingStore.waitingParticipants"
                                :key="p.public_id"
                                class="participant-row"
                            >
                                <div
                                    class="flex items-center justify-between w-full"
                                >
                                    <div class="flex items-center space-x-3">
                                        <Avatar
                                            :src="
                                                p.user?.avatar_url ||
                                                p.metadata?.avatar_url
                                            "
                                            :fallback="getParticipantInitial(p)"
                                            :color="p.user?.color"
                                            size="sm"
                                        />
                                        <div class="user-meta overflow-hidden">
                                            <div
                                                class="user-name text-sm font-medium truncate w-32 text-white"
                                            >
                                                {{ getParticipantName(p) }}
                                            </div>
                                            <div
                                                class="text-[10px] text-gray-500 truncate"
                                            >
                                                {{
                                                    p.metadata?.guest_email ||
                                                    ""
                                                }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex space-x-1">
                                        <button
                                            @click="
                                                meetingStore.rejectParticipant(
                                                    p.public_id,
                                                )
                                            "
                                            class="p-1.5 hover:bg-red-500/20 text-red-400 rounded-lg transition-colors"
                                            title="Deny"
                                        >
                                            <Icon name="x" size="16" />
                                        </button>
                                        <button
                                            @click="
                                                meetingStore.admitParticipant(
                                                    p.public_id,
                                                )
                                            "
                                            class="p-1.5 hover:bg-green-500/20 text-green-400 rounded-lg transition-colors"
                                            title="Approve"
                                        >
                                            <Icon name="check" size="16" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>
            </Transition>

            <!-- Chat Side Panel -->
            <Transition
                enter-active-class="transition duration-250 ease-out"
                enter-from-class="translate-x-full opacity-0"
                enter-to-class="translate-x-0 opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="translate-x-0 opacity-100"
                leave-to-class="translate-x-full opacity-0"
            >
                <MeetingChatPanel
                    v-if="showChatPanel"
                    @close="showChatPanel = false"
                />
            </Transition>

            <!-- Poll Side Panel -->
            <Transition
                enter-active-class="transition duration-250 ease-out"
                enter-from-class="translate-x-full opacity-0"
                enter-to-class="translate-x-0 opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="translate-x-0 opacity-100"
                leave-to-class="translate-x-full opacity-0"
            >
                <aside v-if="showPollPanel" class="side-panel">
                    <div class="side-panel-header">
                        <h3>Polls</h3>
                        <button
                            @click="showPollPanel = false"
                            class="side-panel-close"
                        >
                            <Icon name="x" size="18" />
                        </button>
                    </div>
                    <div
                        class="side-panel-body"
                        style="padding: 0; overflow: hidden"
                    >
                        <MeetingPollPanel />
                    </div>
                </aside>
            </Transition>
        </div>

        <ReactionOverlay
            :showPicker="showReactionPicker"
            @reaction-sent="showReactionPicker = false"
        />

        <!-- Vibe Summary (Host Only) -->
        <ReactionVibeSummary v-if="meetingStore.isHost" />

        <!-- Redesigned Bottom bar -->
        <footer class="app-bottom-bar">
            <!-- Left: Info -->
            <div class="bar-section bar-section--left">
                <div
                    class="meeting-info-pill"
                    :class="{ 'info-pill--active': showMeetingDetails }"
                    @click.stop="showMeetingDetails = !showMeetingDetails"
                    title="Meeting Details"
                >
                    <span class="info-time">{{ currentTime }}</span>
                    <span class="info-divider"></span>
                    <span class="info-code">{{
                        meetingStore.isInBreakout
                            ? meetingStore.currentRoomName
                            : meetingStore.meeting?.title || meetingId
                    }}</span>
                    <Transition name="fade">
                        <div
                            v-if="meetingStore.isLocked"
                            class="locked-status-badge"
                        >
                            <Icon name="lock" size="14" />
                            <span>Locked</span>
                        </div>
                    </Transition>
                </div>
                <div
                    v-if="isRecording"
                    class="recording-badge"
                    :title="
                        isRecording
                            ? `Recording in progress (${formattedDuration})`
                            : ''
                    "
                >
                    <span class="recording-dot"></span>
                    <span>REC</span>
                    <span
                        class="recording-timer ml-1 font-mono text-xs opacity-90"
                        >{{ formattedDuration }}</span
                    >
                </div>

                <NetworkHealthIndicator
                    v-if="
                        meetingStore.sfuPc() ||
                        (meetingStore.meeting &&
                            meetingStore.meeting.recording_enabled)
                    "
                    v-bind="networkStats"
                    compact
                    class="ml-2"
                />
            </div>

            <!-- Center: Media Controls -->
            <div class="bar-section bar-section--center">
                <div style="position: relative; display: flex">
                    <div
                        v-if="isMicOn"
                        class="mic-volume-ring"
                        :style="{
                            transform: `scale(${1 + Math.min(meetingStore.localVolume / 40, 0.7)})`,
                            opacity: meetingStore.localVolume > 2 ? 1 : 0,
                        }"
                    ></div>
                    <button
                        class="ctrl-btn ctrl-btn--media"
                        style="z-index: 1"
                        :class="{ 'ctrl-btn--off': !isMicOn }"
                        @click="toggleMicDebounced"
                        :disabled="isMicToggleBusy"
                        :title="micToggleTitle"
                    >
                        <Icon :name="isMicOn ? 'mic' : 'mic-off'" size="20" />
                    </button>
                </div>
                <button
                    class="ctrl-btn ctrl-btn--media"
                    :class="{ 'ctrl-btn--off': !isCameraOn }"
                    @click="toggleCameraDebounced"
                    :disabled="isCameraToggleBusy"
                    :title="cameraToggleTitle"
                >
                    <span class="ctrl-btn-inner">
                        <span
                            v-if="isCameraToggleBusy"
                            class="ctrl-btn-spinner"
                        ></span>
                        <Icon
                            v-else
                            :name="isCameraOn ? 'video' : 'video-off'"
                            size="20"
                        />
                    </span>
                </button>
                <button
                    class="ctrl-btn ctrl-btn--hand"
                    :class="{ 'ctrl-btn--active-bounce': isHandRaised }"
                    @click="meetingStore.toggleHand()"
                    title="Raise Hand"
                >
                    <Icon name="hand" size="20" />
                </button>

                <!-- Split Reaction Button -->
                <div class="reaction-split-wrap">
                    <button
                        class="reaction-quick-btn"
                        @click="sendQuickReaction"
                        :title="`Quick React: ${lastReactionEmoji}`"
                    >
                        <Transition name="pop" mode="out-in">
                            <span
                                :key="lastReactionEmoji"
                                class="quick-emoji"
                                >{{ lastReactionEmoji }}</span
                            >
                        </Transition>
                    </button>
                    <button
                        class="reaction-picker-trigger"
                        :class="{ 'picker-open': showReactionPicker }"
                        @click="showReactionPicker = !showReactionPicker"
                        title="Reaction Menu"
                    >
                        <Icon name="chevron-up" size="14" />
                    </button>
                </div>

                <button
                    v-if="showScreenShareControl"
                    class="ctrl-btn ctrl-btn--screen"
                    :class="{ 'ctrl-btn--sharing': isScreenSharing }"
                    @click="toggleScreenShareDebounced"
                    :disabled="
                        isScreenShareToggleBusy ||
                        (!canStartScreenShare && !isScreenSharing)
                    "
                    :title="screenShareToggleTitle"
                >
                    <span class="ctrl-btn-inner">
                        <span
                            v-if="isScreenShareToggleBusy"
                            class="ctrl-btn-spinner"
                        ></span>
                        <Icon v-else name="monitor" size="20" />
                    </span>
                </button>
                <!-- Annotation (Presenter only) -->
                <button
                    v-if="isScreenSharing"
                    class="ctrl-btn ctrl-btn--annotate"
                    :class="{ 'ctrl-btn--active': meetingStore.isAnnotating }"
                    @click="
                        meetingStore.isAnnotating = !meetingStore.isAnnotating
                    "
                    title="Annotate Screen"
                >
                    <Icon name="pen-tool" size="20" />
                </button>

                <div class="hangup-wrapper">
                    <button
                        class="ctrl-btn ctrl-btn--hangup"
                        @click.stop="showHangupMenu = !showHangupMenu"
                        title="Leave or End"
                    >
                        <Icon name="phone-off" size="22" />
                    </button>
                    <div
                        v-if="showHangupMenu"
                        class="modern-hangup-menu"
                        v-click-outside="() => (showHangupMenu = false)"
                    >
                        <button class="menu-item" @click="leaveMeeting">
                            <Icon name="log-out" size="16" />
                            <span>Leave meeting</span>
                        </button>
                        <button
                            v-if="meetingStore.isHost"
                            class="menu-item menu-item--danger"
                            @click="endMeetingForAll"
                        >
                            <Icon name="phone-off" size="16" />
                            <span>End meeting for all</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right: Activity Toggles -->
            <div class="bar-section bar-section--right">
                <!-- Secondary Actions (Desktop) -->
                <div class="hidden lg:flex items-center gap-2">
                    <button
                        v-if="
                            meetingStore.isModerator &&
                            meetingStore.waitingParticipants.length > 0
                        "
                        class="ctrl-btn btn--alert ctrl-btn--requests"
                        @click="openRequestsPanel"
                        title="Requests"
                    >
                        <Icon name="user-plus" size="20" />
                        <span class="badge-count">{{
                            meetingStore.waitingParticipants.length
                        }}</span>
                    </button>

                    <button
                        class="ctrl-btn ctrl-btn--participants"
                        :class="{ 'ctrl-btn--active': showParticipantsPanel }"
                        @click="toggleParticipantsPanel"
                        title="Participants"
                    >
                        <Icon name="users" size="20" />
                        <span class="badge-count badge-count--secondary">{{
                            meetingStore.allParticipants.length
                        }}</span>
                    </button>

                    <button
                        class="ctrl-btn ctrl-btn--chat"
                        :class="{ 'ctrl-btn--active': showChatPanel }"
                        @click="toggleChatPanel"
                        title="Chat"
                    >
                        <Icon name="message-square" size="20" />
                    </button>

                    <button
                        v-if="meetingStore.isHost"
                        class="ctrl-btn ctrl-btn--lock lock-btn-wrap disabled:opacity-60 disabled:cursor-not-allowed"
                        :class="{
                            'ctrl-btn--lock-active': meetingStore.isLocked,
                        }"
                        @click="meetingStore.toggleLock()"
                        :disabled="meetingStore.isLockToggleBusy"
                        :title="
                            meetingStore.isLocked
                                ? 'Unlock Meeting'
                                : 'Lock Meeting'
                        "
                    >
                        <Transition name="icon-morph" mode="out-in">
                            <Icon
                                :key="meetingStore.isLocked ? 'lock' : 'unlock'"
                                :name="
                                    meetingStore.isLocked ? 'lock' : 'unlock'
                                "
                                size="20"
                            />
                        </Transition>
                    </button>

                    <!-- Activities Menu (Desktop) -->
                    <div
                        class="relative hidden lg:flex"
                        v-click-outside="() => (showActivitiesMenu = false)"
                    >
                        <button
                            class="ctrl-btn ctrl-btn--activities"
                            :class="{
                                'ctrl-btn--active':
                                    whiteboardStore.isVisible ||
                                    showPollPanel ||
                                    meetingStore.showBreakoutManager,
                            }"
                            title="Activities"
                            @click="showActivitiesMenu = !showActivitiesMenu"
                        >
                            <Icon name="shapes" size="20" />
                        </button>

                        <Transition
                            enter-active-class="transition duration-200 ease-out"
                            enter-from-class="opacity-0 scale-95 translate-y-2"
                            enter-to-class="opacity-100 scale-100 translate-y-0"
                            leave-active-class="transition duration-150 ease-in"
                            leave-from-class="opacity-100 scale-100 translate-y-0"
                            leave-to-class="opacity-0 scale-95 translate-y-2"
                        >
                            <div
                                v-if="showActivitiesMenu"
                                class="layout-menu toolbox-menu shadow-2xl z-1000"
                            >
                                <div class="px-4 pt-4 pb-2 border-none">
                                    <h3
                                        class="text-[14px] font-bold tracking-wider text-white"
                                    >
                                        ACTIVITIES
                                    </h3>
                                </div>
                                <div class="p-2 flex flex-col gap-1">
                                    <button
                                        @click="
                                            togglePollPanel();
                                            showActivitiesMenu = false;
                                        "
                                        class="p-3 rounded-xl flex items-center transition-all cursor-pointer outline-none hover:bg-white/5 text-white/90 group w-full border-none bg-transparent"
                                    >
                                        <div
                                            class="w-10 h-10 flex items-center justify-center rounded-[10px] bg-white/5 group-hover:bg-white/10 transition-colors mr-3 shrink-0 text-white/60 group-hover:text-white/90"
                                        >
                                            <Icon
                                                name="bar-chart-2"
                                                size="20"
                                            />
                                        </div>
                                        <div class="flex flex-col text-left">
                                            <span class="text-[14px] mb-[2px]"
                                                >Polls</span
                                            >
                                            <span
                                                class="text-[12px] text-white/50"
                                                >Engage with participants</span
                                            >
                                        </div>
                                    </button>

                                    <button
                                        @click="
                                            whiteboardStore.isVisible =
                                                !whiteboardStore.isVisible;
                                            showMoreMenu = false;
                                            showActivitiesMenu = false;
                                        "
                                        class="p-3 rounded-xl flex items-center transition-all cursor-pointer outline-none hover:bg-white/5 text-white/90 group w-full border-none bg-transparent"
                                    >
                                        <div
                                            class="w-10 h-10 flex items-center justify-center rounded-[10px] bg-white/5 group-hover:bg-white/10 transition-colors mr-3 shrink-0 text-white/60 group-hover:text-white/90"
                                        >
                                            <Icon name="edit-3" size="20" />
                                        </div>
                                        <div class="flex flex-col text-left">
                                            <span class="text-[14px] mb-[2px]"
                                                >Whiteboard</span
                                            >
                                            <span
                                                class="text-[12px] text-white/50"
                                                >Collaborate visually</span
                                            >
                                        </div>
                                    </button>

                                    <template v-if="meetingStore.isHost">
                                        <div
                                            class="h-px bg-white/10 my-2 mx-2"
                                        ></div>
                                        <button
                                            @click="
                                                meetingStore.showBreakoutManager = true;
                                                showActivitiesMenu = false;
                                            "
                                            class="p-3 rounded-xl flex items-center transition-all cursor-pointer outline-none hover:bg-white/5 text-white/90 group w-full border-none bg-transparent"
                                        >
                                            <div
                                                class="w-10 h-10 flex items-center justify-center rounded-[10px] bg-white/5 group-hover:bg-white/10 transition-colors mr-3 shrink-0 text-white/60 group-hover:text-white/90"
                                            >
                                                <Icon
                                                    name="layout-grid"
                                                    size="20"
                                                />
                                            </div>
                                            <div
                                                class="flex flex-col text-left"
                                            >
                                                <span
                                                    class="text-[14px] mb-[2px]"
                                                    >Breakout Rooms</span
                                                >
                                                <span
                                                    class="text-[12px] text-white/50"
                                                    >Split into smaller
                                                    groups</span
                                                >
                                            </div>
                                        </button>
                                    </template>

                                    <template
                                        v-if="
                                            meetingStore.isModerator &&
                                            meetingStore.waitingParticipants
                                                .length > 0
                                        "
                                    >
                                        <div
                                            class="h-px bg-white/10 mx-2 my-1"
                                        ></div>
                                        <button
                                            @click="
                                                openRequestsPanel();
                                                showMoreMenu = false;
                                                showActivitiesMenu = false;
                                            "
                                            class="menu-action-item px-3 py-2 rounded-xl flex items-center transition-colors cursor-pointer outline-none bg-red-500/10 text-red-400 hover:bg-red-500 hover:text-white w-full border-none"
                                        >
                                            <Icon
                                                name="user-plus"
                                                size="18"
                                                class="mr-3"
                                            />
                                            <span class="font-medium text-sm"
                                                >Waiting Requests ({{
                                                    meetingStore
                                                        .waitingParticipants
                                                        .length
                                                }})</span
                                            >
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <MeetingLayoutSelector />

                    <button
                        class="ctrl-btn ctrl-btn--settings"
                        @click="showSettings = true"
                        title="Settings"
                    >
                        <Icon name="settings" size="20" />
                    </button>

                    <button
                        v-if="meetingStore.isHost && recordingEnabled"
                        class="ctrl-btn ctrl-btn--record-toggle"
                        :class="{ 'ctrl-btn--recording': isRecording }"
                        :disabled="isRecordingStarting || isRecordingStopping"
                        @click="toggleRecording"
                        :title="
                            isRecording ? 'Stop Recording' : 'Start Recording'
                        "
                    >
                        <Icon
                            v-if="!isRecordingStarting && !isRecordingStopping"
                            :name="isRecording ? 'circle-stop' : 'circle-dot'"
                            size="20"
                        />
                        <div v-else class="flex items-center justify-center">
                            <div
                                class="recording-loading-spinner animate-pulse bg-red-500 w-3 h-3 rounded-full"
                            ></div>
                        </div>
                    </button>
                </div>

                <!-- More Menu (Mobile) -->
                <div class="more-menu-wrapper relative lg:hidden">
                    <button
                        class="ctrl-btn ctrl-btn--more"
                        :class="{ 'ctrl-btn--active': showMoreMenu }"
                        @click.stop="showMoreMenu = !showMoreMenu"
                        title="More options"
                    >
                        <Icon name="more-vertical" size="20" />
                    </button>

                    <Transition
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="opacity-0 scale-95 translate-y-4"
                        enter-to-class="opacity-100 scale-100 translate-y-0"
                        leave-active-class="transition duration-150 ease-in"
                        leave-from-class="opacity-100 scale-100 translate-y-0"
                        leave-to-class="opacity-0 scale-95 translate-y-4"
                    >
                        <div
                            v-if="showMoreMenu"
                            ref="moreMenuRef"
                            v-click-outside="() => (showMoreMenu = false)"
                            class="modern-more-menu toolbox-menu shadow-2xl"
                        >
                            <div
                                class="p-4 border-b border-white/10 flex items-center justify-between bg-white/5"
                            >
                                <h3 class="text-sm font-semibold text-white/90">
                                    Meeting options
                                </h3>
                                <button
                                    @click="showMoreMenu = false"
                                    class="text-white/40 hover:text-white transition-colors"
                                >
                                    <Icon name="x" size="18" />
                                </button>
                            </div>

                            <div
                                class="p-2 space-y-1 max-h-[70dvh] overflow-y-auto custom-scrollbar"
                            >
                                <button
                                    @click="
                                        toggleParticipantsPanel();
                                        showMoreMenu = false;
                                    "
                                    class="w-full p-3 rounded-xl flex items-center gap-4 transition-all hover:bg-white/5 active:bg-white/10 group"
                                >
                                    <div
                                        class="w-10 h-10 flex items-center justify-center rounded-xl bg-surface-secondary group-hover:bg-surface-tertiary transition-colors shrink-0"
                                    >
                                        <Icon
                                            name="users"
                                            size="20"
                                            class="text-white/70"
                                        />
                                    </div>
                                    <div class="flex flex-col text-left">
                                        <span
                                            class="font-semibold text-sm text-white/90"
                                            >Participants</span
                                        >
                                        <span class="text-[10px] text-white/40"
                                            >Manage meeting attendees</span
                                        >
                                    </div>
                                    <div
                                        class="ml-auto bg-white/10 px-2 py-0.5 rounded-lg text-[10px] font-bold text-white/60"
                                    >
                                        {{
                                            meetingStore.allParticipants.length
                                        }}
                                    </div>
                                </button>

                                <button
                                    @click="
                                        toggleChatPanel();
                                        showMoreMenu = false;
                                    "
                                    class="w-full p-3 rounded-xl flex items-center gap-4 transition-all hover:bg-white/5 active:bg-white/10 group"
                                >
                                    <div
                                        class="w-10 h-10 flex items-center justify-center rounded-xl bg-surface-secondary group-hover:bg-surface-tertiary transition-colors shrink-0"
                                    >
                                        <Icon
                                            name="message-square"
                                            size="20"
                                            class="text-white/70"
                                        />
                                    </div>
                                    <div class="flex flex-col text-left">
                                        <span
                                            class="font-semibold text-sm text-white/90"
                                            >Chat</span
                                        >
                                        <span class="text-[10px] text-white/40"
                                            >Send messages to everyone</span
                                        >
                                    </div>
                                </button>

                                <button
                                    @click="
                                        togglePollPanel();
                                        showMoreMenu = false;
                                    "
                                    class="w-full p-3 rounded-xl flex items-center gap-4 transition-all hover:bg-white/5 active:bg-white/10 group"
                                >
                                    <div
                                        class="w-10 h-10 flex items-center justify-center rounded-xl bg-surface-secondary group-hover:bg-surface-tertiary transition-colors shrink-0"
                                    >
                                        <Icon
                                            name="bar-chart-2"
                                            size="20"
                                            class="text-white/70"
                                        />
                                    </div>
                                    <div class="flex flex-col text-left">
                                        <span
                                            class="font-semibold text-sm text-white/90"
                                            >Polls</span
                                        >
                                        <span class="text-[10px] text-white/40"
                                            >Create and vote on polls</span
                                        >
                                    </div>
                                </button>

                                <button
                                    @click="
                                        whiteboardStore.isVisible =
                                            !whiteboardStore.isVisible;
                                        showMoreMenu = false;
                                    "
                                    class="w-full p-3 rounded-xl flex items-center gap-4 transition-all hover:bg-white/5 active:bg-white/10 group"
                                >
                                    <div
                                        class="w-10 h-10 flex items-center justify-center rounded-xl bg-surface-secondary group-hover:bg-surface-tertiary transition-colors shrink-0"
                                    >
                                        <Icon
                                            name="edit-3"
                                            size="20"
                                            class="text-white/70"
                                        />
                                    </div>
                                    <div class="flex flex-col text-left">
                                        <span
                                            class="font-semibold text-sm text-white/90"
                                            >Whiteboard</span
                                        >
                                        <span class="text-[10px] text-white/40"
                                            >Collaborate on a canvas</span
                                        >
                                    </div>
                                </button>

                                <!-- Change Layout (Mobile Integrated) -->
                                <button
                                    @click="
                                        if (layoutSelectorRef)
                                            layoutSelectorRef.showMenu = true;
                                        showMoreMenu = false;
                                    "
                                    class="w-full p-3 rounded-xl flex items-center gap-4 transition-all hover:bg-white/5 active:bg-white/10 group"
                                >
                                    <div
                                        class="w-10 h-10 flex items-center justify-center rounded-xl bg-surface-secondary group-hover:bg-surface-tertiary transition-colors shrink-0"
                                    >
                                        <Icon
                                            name="sparkles"
                                            size="20"
                                            class="text-white/70"
                                        />
                                    </div>
                                    <div class="flex flex-col text-left">
                                        <span
                                            class="font-semibold text-sm text-white/90"
                                            >Change layout</span
                                        >
                                        <span class="text-[10px] text-white/40"
                                            >Adjust your view</span
                                        >
                                    </div>
                                </button>

                                <div class="h-px bg-white/10 my-2 mx-2"></div>

                                <button
                                    @click="
                                        showSettings = true;
                                        showMoreMenu = false;
                                    "
                                    class="w-full p-3 rounded-xl flex items-center gap-4 transition-all hover:bg-white/5 active:bg-white/10 group text-white"
                                >
                                    <div
                                        class="w-10 h-10 flex items-center justify-center rounded-xl bg-surface-secondary group-hover:bg-surface-tertiary transition-colors shrink-0"
                                    >
                                        <Icon
                                            name="settings"
                                            size="20"
                                            class="text-white/70"
                                        />
                                    </div>
                                    <div class="flex flex-col text-left">
                                        <span
                                            class="font-semibold text-sm text-white/90"
                                            >Settings</span
                                        >
                                        <span class="text-[10px] text-white/40"
                                            >Audio, video and more</span
                                        >
                                    </div>
                                </button>

                                <template v-if="meetingStore.isHost">
                                    <button
                                        @click="
                                            meetingStore.toggleLock();
                                            showMoreMenu = false;
                                        "
                                        :disabled="meetingStore.isLockToggleBusy"
                                        class="w-full p-3 rounded-xl flex items-center gap-4 transition-all hover:bg-white/5 active:bg-white/10 group text-white disabled:opacity-60 disabled:cursor-not-allowed"
                                    >
                                        <div
                                            class="w-10 h-10 flex items-center justify-center rounded-xl group-hover:bg-surface-tertiary transition-colors shrink-0"
                                            :class="
                                                meetingStore.isLocked
                                                    ? 'bg-red-500/20'
                                                    : 'bg-surface-secondary'
                                            "
                                        >
                                            <Icon
                                                :name="
                                                    meetingStore.isLocked
                                                        ? 'lock'
                                                        : 'unlock'
                                                "
                                                size="20"
                                                :class="
                                                    meetingStore.isLocked
                                                        ? 'text-red-400'
                                                        : 'text-white/70'
                                                "
                                            />
                                        </div>
                                        <div class="flex flex-col text-left">
                                            <span
                                                class="font-semibold text-sm text-white/90"
                                                >{{
                                                    meetingStore.isLocked
                                                        ? "Unlock Room"
                                                        : "Lock Room"
                                                }}</span
                                            >
                                            <span
                                                class="text-[10px] text-white/40"
                                                >{{
                                                    meetingStore.isLocked
                                                        ? "Allow anyone to join"
                                                        : "Require approval to join"
                                                }}</span
                                            >
                                        </div>
                                    </button>

                                    <button
                                        v-if="recordingEnabled"
                                        @click="
                                            toggleRecording();
                                            showMoreMenu = false;
                                        "
                                        :disabled="
                                            isRecordingStarting ||
                                            isRecordingStopping
                                        "
                                        class="w-full p-3 rounded-xl flex items-center gap-4 transition-all hover:bg-white/5 active:bg-white/10 group text-white disabled:opacity-50"
                                    >
                                        <div
                                            class="w-10 h-10 flex items-center justify-center rounded-xl group-hover:bg-surface-tertiary transition-colors shrink-0"
                                            :class="
                                                isRecording
                                                    ? 'bg-red-500/20 animation-pulse'
                                                    : 'bg-surface-secondary'
                                            "
                                        >
                                            <Icon
                                                :name="
                                                    isRecording
                                                        ? 'circle-stop'
                                                        : 'circle-dot'
                                                "
                                                size="20"
                                                :class="
                                                    isRecording
                                                        ? 'text-red-500'
                                                        : 'text-white/70'
                                                "
                                            />
                                        </div>
                                        <div class="flex flex-col text-left">
                                            <span
                                                class="font-semibold text-sm text-white/90"
                                                >{{
                                                    isRecording
                                                        ? "Stop Recording"
                                                        : "Start Recording"
                                                }}</span
                                            >
                                            <span
                                                class="text-[10px] text-white/40"
                                                >{{
                                                    isRecordingStarting
                                                        ? "Starting..."
                                                        : isRecordingStopping
                                                          ? "Stopping..."
                                                          : isRecording
                                                            ? "Save this session"
                                                            : "Record for later"
                                                }}</span
                                            >
                                        </div>
                                    </button>

                                    <button
                                        @click="
                                            meetingStore.showBreakoutManager = true;
                                            showMoreMenu = false;
                                        "
                                        class="w-full p-3 rounded-xl flex items-center gap-4 transition-all hover:bg-white/5 active:bg-white/10 group text-white"
                                    >
                                        <div
                                            class="w-10 h-10 flex items-center justify-center rounded-xl bg-surface-secondary group-hover:bg-surface-tertiary transition-colors shrink-0"
                                        >
                                            <Icon
                                                name="layout-grid"
                                                size="20"
                                                class="text-white/70"
                                            />
                                        </div>
                                        <div class="flex flex-col text-left">
                                            <span
                                                class="font-semibold text-sm text-white/90"
                                                >Breakout Rooms</span
                                            >
                                            <span
                                                class="text-[10px] text-white/40"
                                                >Split into smaller groups</span
                                            >
                                        </div>
                                    </button>
                                </template>

                                <template
                                    v-if="
                                        meetingStore.isModerator &&
                                        meetingStore.waitingParticipants
                                            .length > 0
                                    "
                                >
                                    <div
                                        class="h-px bg-white/10 my-2 mx-2"
                                    ></div>
                                    <button
                                        @click="
                                            openRequestsPanel();
                                            showMoreMenu = false;
                                        "
                                        class="w-full p-3 rounded-xl flex items-center gap-4 transition-all cursor-pointer outline-none bg-red-500/10 text-red-400 hover:bg-red-500 hover:text-white"
                                    >
                                        <div
                                            class="w-10 h-10 flex items-center justify-center rounded-xl bg-red-500/20 group-hover:bg-red-500/30 transition-colors shrink-0"
                                        >
                                            <Icon name="user-plus" size="18" />
                                        </div>
                                        <div class="flex flex-col text-left">
                                            <span
                                                class="font-semibold text-sm text-white/90"
                                                >Waiting Room</span
                                            >
                                            <span
                                                class="text-[10px] text-white/40"
                                                >Handle join requests</span
                                            >
                                        </div>
                                        <span
                                            class="ml-auto bg-red-500 text-white px-2 py-0.5 rounded text-[10px] font-bold"
                                        >
                                            {{
                                                meetingStore.waitingParticipants
                                                    .length
                                            }}
                                        </span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </Transition>
                    <MeetingLayoutSelector
                        ref="layoutSelectorRef"
                        :hide-trigger="true"
                    />
                </div>
            </div>
        </footer>

        <!-- Modals and Dev Tools -->
        <DeviceSettingsModal
            v-model:open="showSettings"
            @close="showSettings = false"
        />
        <BreakoutManagerModal
            v-if="meetingStore.showBreakoutManager"
            @close="meetingStore.showBreakoutManager = false"
        />

        <DevSimulationTool v-if="isDevMode" v-model:show="showDevTool" />
        <MediaViewer />
    </div>
</template>

<script setup lang="ts">
import {
    ref,
    reactive,
    computed,
    onMounted,
    onUnmounted,
    onBeforeUnmount,
    watch,
} from "vue";
import { useRoute, useRouter } from "vue-router";
import { meetingService } from "@/services/meeting.service";
import { useMeetingStore } from "@/stores/meeting";
import { useVideoCallStore } from "@/stores/videocall";
import { useWhiteboardStore } from "@/stores/whiteboard";
import { useBackgroundBlur } from "@/composables/useBackgroundBlur";
import { Icon, Avatar } from "@/components/ui";
import { toast } from "vue-sonner";
import api from "@/lib/api";
import {
    DropdownMenuRoot,
    DropdownMenuTrigger,
    DropdownMenuPortal,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
} from "reka-ui";

import DeviceSettingsModal from "./components/DeviceSettingsModal.vue";
import DevSimulationTool from "./components/DevSimulationTool.vue";
import ParticipantTile from "./components/ParticipantTile.vue";
import NetworkHealthIndicator from "../call/components/NetworkHealthIndicator.vue";
import MeetingChatPanel from "./components/MeetingChatPanel.vue";
import MeetingPollPanel from "./components/MeetingPollPanel.vue";
import MeetingLayoutSelector from "./components/MeetingLayoutSelector.vue";
import ReactionOverlay from "./components/ReactionOverlay.vue";
import ReactionVibeSummary from "./components/ReactionVibeSummary.vue";
import WhiteboardView from "./components/WhiteboardView.vue";
import BreakoutManagerModal from "./components/BreakoutManagerModal.vue";
import BreakoutOverlay from "./components/BreakoutOverlay.vue";
import BreakoutDashboard from "./components/BreakoutDashboard.vue";
import MediaViewer from "@/components/tools/MediaViewer.vue";
import { useRecording as useRecordingComposable } from "@/composables/useRecording";
import { isValidUlid, normalizeUlid } from "@/utils/meetingId";

// Custom v-click-outside directive
const vClickOutside = {
    mounted(el: any, binding: any) {
        el.clickOutsideEvent = (event: any) => {
            if (!(el === event.target || el.contains(event.target))) {
                binding.value(event);
            }
        };
        document.addEventListener("click", el.clickOutsideEvent);
    },
    unmounted(el: any) {
        document.removeEventListener("click", el.clickOutsideEvent);
    },
};

const route = useRoute();
const router = useRouter();
const meetingStore = useMeetingStore();
const videoCallStore = useVideoCallStore();
const whiteboardStore = useWhiteboardStore();

const rawMeetingId = String(route.params.id ?? "");
const meetingId = isValidUlid(rawMeetingId) ? normalizeUlid(rawMeetingId) : "";

const resolvedMeetingPublicId = computed(() => {
    const storeId = String(meetingStore.meeting?.public_id || "").trim();
    if (isValidUlid(storeId)) return normalizeUlid(storeId);
    if (isValidUlid(meetingId)) return normalizeUlid(meetingId);
    return "";
});

const meetingShareUrl = computed(() => {
    if (!resolvedMeetingPublicId.value) return "";
    return `${window.location.origin}/m/${resolvedMeetingPublicId.value}`;
});

const participantFromQuery = Array.isArray(route.query.participant)
    ? route.query.participant[0]
    : route.query.participant;

const normalizedParticipantFromQuery =
    typeof participantFromQuery === "string" && isValidUlid(participantFromQuery)
        ? normalizeUlid(participantFromQuery)
        : "";
const participantId = normalizedParticipantFromQuery;

const isCameraOn = ref(false);
const isMicOn = ref(false);
const backgroundBlur = useBackgroundBlur();
const showSettings = ref(false);
const showActivitiesMenu = ref(false);
const showParticipantsPanel = ref(false);
const showChatPanel = ref(false);
const showPollPanel = ref(false);
const showReactionPicker = ref(false);
const showMeetingDetails = ref(false);
const showMeetingPasscode = ref(false);
const showMoreMenu = ref(false);
const layoutSelectorRef = ref<any>(null);
const showDevTool = ref(false);
const initializing = ref(true);
const activeParticipantTab = ref<"members" | "waiting">("members");

const meetingPasscodeDisplay = computed(() => {
    const passcode = String(meetingStore.meeting?.password || "");
    if (!passcode) return "";
    if (showMeetingPasscode.value) return passcode;
    return "•".repeat(Math.max(passcode.length, 8));
});

function openRequestsPanel() {
    showParticipantsPanel.value = true;
    activeParticipantTab.value = "waiting";
    showChatPanel.value = false;
    showPollPanel.value = false;
}

function toggleParticipantsPanel() {
    showParticipantsPanel.value = !showParticipantsPanel.value;
    if (showParticipantsPanel.value) showChatPanel.value = false;
}

function toggleChatPanel() {
    showChatPanel.value = !showChatPanel.value;
    if (showChatPanel.value) {
        showParticipantsPanel.value = false;
        showPollPanel.value = false;
        // Fetch messages when opening the panel
        meetingStore.fetchMessages();
    }
}

function togglePollPanel() {
    showPollPanel.value = !showPollPanel.value;
    if (showPollPanel.value) {
        showParticipantsPanel.value = false;
        showChatPanel.value = false;
    }
}

watch(showMeetingDetails, (open) => {
    if (!open) {
        showMeetingPasscode.value = false;
    }
});

// Live clock
const currentTime = ref("");
let clockInterval: number;

function updateClock() {
    currentTime.value = new Date().toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
    });
}

// Presence Heartbeat
let heartbeatInterval: any;
async function sendHeartbeat() {
    if (!meetingStore.meeting || !meetingStore.localParticipant) return;
    // Only count as "live" if admitted to the room
    if (meetingStore.localParticipant.status !== "admitted") return;

    try {
        await api.post(
            `/api/meetings/${meetingStore.meeting.public_id}/heartbeat`,
            {
                participant_id: meetingStore.localParticipant.public_id,
            },
        );
    } catch (e) {
        // Silent fail for heartbeats
    }
}

const isWaiting = computed(() => {
    // Safety: Host/Moderator should never see the waiting room overlay
    if (meetingStore.isModerator || meetingStore.isHost) return false;

    // Standard waiting-room status from backend.
    if (meetingStore.localParticipant?.status === "waiting") return true;

    // Live gate for already-admitted participants when host/co-host must be present first.
    const requiresModeratorGate = !!meetingStore.meeting?.settings
        ?.require_host_or_cohost_present;
    if (!requiresModeratorGate) return false;
    if (meetingStore.localParticipant?.status !== "admitted") return false;

    const activeIds = meetingStore.activeParticipantIds;
    if (!activeIds || activeIds.size === 0) return true;

    const hasActiveModerator = (meetingStore.participants || []).some((p: any) => {
        const pid = String(p.public_id || "").toLowerCase();
        const isActive = pid ? activeIds.has(pid) : false;
        return isActive && (p.role === "host" || p.role === "co-host");
    });

    return !hasActiveModerator;
});

const participantCount = computed(() => meetingStore.allParticipants.length);
const micToggleTitle = computed(() =>
    isMicOn.value ? "Mute (Ctrl+D)" : "Unmute (Ctrl+D)",
);
const cameraToggleTitle = computed(() =>
    isCameraOn.value ? "Turn off camera (Ctrl+E)" : "Turn on camera (Ctrl+E)",
);
const screenShareToggleTitle = computed(() =>
    isScreenSharing.value
        ? "Stop sharing"
        : !canStartScreenShare.value && activeScreenSharerId.value
          ? "Another participant is sharing"
          : "Share Screen",
);
const isHandRaised = computed(() =>
    meetingStore.raisedHands.has(
        meetingStore.localParticipant?.public_id || "",
    ),
);
const isDevMode = computed(() => !!import.meta.env.DEV);
const meetingHostName = computed(
    () => meetingStore.meeting?.host?.name || "Authorized Personnel",
);
const isWaitingForModerator = computed(() => {
    if (!isWaiting.value) return false;

    if (
        meetingStore.localParticipant?.metadata?.waiting_reason ===
        "awaiting_moderator"
    ) {
        return true;
    }

    // Fallback for already-admitted users held by live host/co-host-first gate.
    if (
        meetingStore.localParticipant?.status === "admitted" &&
        meetingStore.meeting?.settings?.require_host_or_cohost_present
    ) {
        return true;
    }

    return false;
});
const waitingTitle = computed(() =>
    isWaitingForModerator.value ? "Waiting for host..." : "Please wait...",
);
const waitingDescription = computed(() =>
    isWaitingForModerator.value
        ? "Waiting for a host or co-host to join the meeting."
        : "The meeting host has been notified. They'll let you in soon.",
);

const screenShareHostCohostOnly = computed(
    () => !!meetingStore.meeting?.settings?.screen_share_host_cohost_only,
);

const isScreenShareDeviceSupported = computed(() => {
    if (typeof window !== "undefined" && !window.isSecureContext) return false;
    if (!navigator?.mediaDevices?.getDisplayMedia) return false;

    // Hard-disable on mobile/tablet for now; current mobile path is unstable.
    const ua = navigator.userAgent || "";
    const isTouchMac = /Macintosh/i.test(ua) && navigator.maxTouchPoints > 1;
    const isMobileOrTablet =
        /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(
            ua,
        ) || isTouchMac;
    return !isMobileOrTablet;
});

const canUseScreenShareRole = computed(() => {
    if (!screenShareHostCohostOnly.value) return true;
    return !!meetingStore.isModerator;
});

const localParticipantId = computed(() =>
    String(meetingStore.localParticipant?.public_id || "").toLowerCase(),
);

const activeScreenSharerId = computed(() => {
    const sharers = Array.from(meetingStore.screenShares || []).map((id) =>
        String(id).toLowerCase(),
    );
    return (
        sharers.find((id) => id && id !== localParticipantId.value) || null
    );
});

const showScreenShareControl = computed(() => {
    if (!isScreenShareDeviceSupported.value) return false;
    return canUseScreenShareRole.value || isScreenSharing.value;
});

const canStartScreenShare = computed(() => {
    if (!isScreenShareDeviceSupported.value) return false;
    if (!canUseScreenShareRole.value) return false;
    if (activeScreenSharerId.value && !meetingStore.isModerator) return false;
    return true;
});

/**
 * Smart Self-View Logic:
 * Shows the small PIP self-view only if:
 * 1. Camera is on
 * 2. AND (we are screensharing OR there are other participants)
 * This prevents seeing ourselves twice when alone (main area shows self when alone).
 */
const shouldShowPiPSelfView = computed(() => {
    return (
        isCameraOn.value &&
        (isScreenSharing.value || participantCount.value > 1)
    );
});

function getParticipantInitial(p: any) {
    if (!p) return "Y";
    const name = p.user?.name || p.metadata?.guest_name || "G";
    return name[0].toUpperCase();
}

function getParticipantName(p: any) {
    if (!p) return "You";
    const name = String(p.user?.name || p.metadata?.guest_name || "Guest").trim();
    const hasLinkedUser = Boolean(p.user_id || p.user?.id || p.user?.public_id);
    const isGuest = !hasLinkedUser;
    if (isGuest) {
        if (/\(guest\)$/i.test(name)) return name;
        if (/^guest$/i.test(name)) return "Guest";
        return `${name} (Guest)`;
    }
    return name;
}

function isParticipantMicOn(p: any) {
    if (p.public_id === meetingStore.localParticipant?.public_id) {
        return isMicOn.value;
    }
    const stream = meetingStore.remoteStreams.get(p.public_id);
    return !!(
        stream &&
        stream
            .getAudioTracks()
            .some((t) => t.enabled && t.readyState === "live")
    );
}

function isParticipantVideoOn(p: any) {
    if (p.public_id === meetingStore.localParticipant?.public_id) {
        return isCameraOn.value;
    }
    const stream = meetingStore.remoteStreams.get(p.public_id);
    return !!(
        stream &&
        stream
            .getVideoTracks()
            .some((t) => t.enabled && t.readyState === "live")
    );
}

// ─── Computed / Layout ──────────────────────────────────────────────────────

const GRID_PAGE_SIZE = 12;
const FILMSTRIP_PAGE_SIZE = 6;

const gridPage = ref(0);
const filmstripPage = ref(0);
const STAGE_TRANSITION_SPINNER_MS = 900;
const isStageTransitioning = ref(false);
let stageTransitionSpinnerTimer: ReturnType<typeof window.setTimeout> | null =
    null;

type RenderedTile = {
    id: string;
    participant: any;
    isScreen: boolean;
};

const localCameraTile = computed<RenderedTile | null>(() => {
    if (!meetingStore.localParticipant) return null;
    return {
        id: meetingStore.localParticipant.public_id,
        participant: meetingStore.localParticipant,
        isScreen: false,
    };
});

function getTileSourceType(tile: { isScreen: boolean } | null | undefined) {
    if (!tile) return null;
    return tile.isScreen ? "screen" : "video";
}

function hasLiveVideoTrack(stream: MediaStream | null | undefined) {
    if (!stream) return false;
    return stream
        .getVideoTracks()
        .some((track) => track.readyState === "live" && track.enabled && !track.muted);
}

function getTileMediaStream(tile: RenderedTile | null | undefined) {
    if (!tile) return null;

    const participantId = tile.participant?.public_id;
    if (!participantId) return null;

    if (participantId === meetingStore.localParticipant?.public_id) {
        return tile.isScreen ? screenStream.value : meetingStore.localStream;
    }

    return meetingStore.remoteStreams.get(
        tile.isScreen ? `${participantId}:screen` : participantId,
    );
}

function scheduleStageTransitionSpinner() {
    isStageTransitioning.value = true;
    if (stageTransitionSpinnerTimer) {
        window.clearTimeout(stageTransitionSpinnerTimer);
    }
    stageTransitionSpinnerTimer = window.setTimeout(() => {
        isStageTransitioning.value = false;
        stageTransitionSpinnerTimer = null;
    }, STAGE_TRANSITION_SPINNER_MS);
}

function getTileLoadingLabel(
    tile: RenderedTile | null | undefined,
    surface: "card" | "stage" = "card",
) {
    if (surface === "stage") {
        return tile?.isScreen ? "Loading presentation..." : "Updating stage...";
    }

    return tile?.isScreen ? "Updating share..." : "Updating video...";
}

function isTileLoading(
    tile: RenderedTile | null | undefined,
    surface: "card" | "stage" = "card",
) {
    if (!tile) return surface === "stage" ? isStageTransitioning.value : false;

    const isLocalTile =
        tile.participant?.public_id === meetingStore.localParticipant?.public_id;

    if (surface === "stage" && isStageTransitioning.value) {
        return true;
    }

    if (tile.isScreen) {
        if (isLocalTile && isScreenShareToggleBusy.value) {
            return true;
        }

        return (
            meetingStore.screenShares.has(tile.participant.public_id) &&
            !hasLiveVideoTrack(getTileMediaStream(tile))
        );
    }

    if (isLocalTile && isCameraToggleBusy.value) {
        return true;
    }

    return false;
}

// Map distinct tiles so a single participant can have both Camera and Screen shown at once
const renderedTiles = computed(() => {
    const tiles: RenderedTile[] = [];

    // 1. Add all standard camera tiles EXCEPT local participant
    meetingStore.allParticipants.forEach((p) => {
        if (p.public_id !== meetingStore.localParticipant?.public_id) {
            tiles.push({ id: p.public_id, participant: p, isScreen: false });
        }
    });

    // 2. Add distinct screenshare tiles
    meetingStore.screenShares.forEach((publicId) => {
        const p = meetingStore.allParticipants.find(
            (x) => x.public_id === publicId,
        );
        if (p) {
            tiles.push({
                id: `${publicId}:screen`,
                participant: p,
                isScreen: true,
            });
        }
    });

    return tiles;
});

const spotlightSelection = computed(() => {
    // 1. Pinned participant
    if (meetingStore.pinnedParticipantId) {
        const pinScreen = renderedTiles.value.find(
            (t) => t.id === `${meetingStore.pinnedParticipantId}:screen`,
        );
        if (pinScreen) {
            return { tile: pinScreen, reason: "pinned-screen" as const };
        }
        const pinnedCamera = renderedTiles.value.find(
            (t) => t.id === meetingStore.pinnedParticipantId,
        );
        if (pinnedCamera) {
            return { tile: pinnedCamera, reason: "pinned-camera" as const };
        }
    }

    // 2. Priority: Any active screenshare
    const screenSharer = renderedTiles.value.find((t) => t.isScreen);
    if (screenSharer) {
        return { tile: screenSharer, reason: "active-screen-share" as const };
    }

    // 3. Fallback: Active speaker (camera tile)
    if (meetingStore.activeSpeakerId) {
        const speaker = renderedTiles.value.find(
            (t) => t.id === meetingStore.activeSpeakerId && !t.isScreen,
        );
        if (speaker) {
            return { tile: speaker, reason: "active-speaker" as const };
        }
    }

    // 4. Ultimate Fallback: If user explicitly requested spotlight/sidebar but scene is silent
    if (
        meetingStore.preferredLayout === "spotlight" ||
        meetingStore.preferredLayout === "sidebar"
    ) {
        // Try getting the first remote participant (renderedTiles already excludes local user)
        const firstRemote = renderedTiles.value[0];
        if (firstRemote) {
            return { tile: firstRemote, reason: "layout-first-remote" as const };
        }
        // If entirely alone, just spotlight yourself
        if (localCameraTile.value) {
            return { tile: localCameraTile.value, reason: "layout-self-fallback" as const };
        }
    }

    return { tile: null, reason: null as const };
});

const spotlightTile = computed(() => spotlightSelection.value.tile);

const isSpotlightMode = computed(() => {
    if (meetingStore.preferredLayout === "tiled") return false;
    // Force true if explicitly requested so we don't accidentally fall back
    if (
        meetingStore.preferredLayout === "spotlight" ||
        meetingStore.preferredLayout === "sidebar"
    )
        return true;
    return !!spotlightTile.value;
});

const unspotlightedTiles = computed(() => {
    if (!spotlightTile.value || !isSpotlightMode.value)
        return renderedTiles.value;
    return renderedTiles.value.filter((t) => t.id !== spotlightTile.value?.id);
});

const totalGridPages = computed(
    () => Math.ceil(unspotlightedTiles.value.length / GRID_PAGE_SIZE) || 1,
);
const totalFilmstripPages = computed(
    () => Math.ceil(unspotlightedTiles.value.length / FILMSTRIP_PAGE_SIZE) || 1,
);

watch(totalGridPages, (pages) => {
    if (gridPage.value >= pages) gridPage.value = Math.max(0, pages - 1);
});
watch(totalFilmstripPages, (pages) => {
    if (filmstripPage.value >= pages)
        filmstripPage.value = Math.max(0, pages - 1);
});

const paginatedTiles = computed(() => {
    if (isSpotlightMode.value) {
        const start = filmstripPage.value * FILMSTRIP_PAGE_SIZE;
        return unspotlightedTiles.value.slice(
            start,
            start + FILMSTRIP_PAGE_SIZE,
        );
    } else {
        const start = gridPage.value * GRID_PAGE_SIZE;
        return unspotlightedTiles.value.slice(start, start + GRID_PAGE_SIZE);
    }
});

const lastStageStateKey = ref<string | null>(null);
const lastStageTileId = ref<string | null>(null);
const lastStageSource = ref<string | null>(null);
const lastStageReason = ref<string | null>(null);
const lastStageMode = ref<string | null>(null);

watch(
    [
        isSpotlightMode,
        spotlightSelection,
        paginatedTiles,
        () => meetingStore.preferredLayout,
        () => meetingStore.pinnedParticipantId,
        () => meetingStore.activeSpeakerId,
    ],
    () => {
        const currentTile = spotlightSelection.value.tile;
        const currentSource = getTileSourceType(currentTile);
        const currentReason = spotlightSelection.value.reason;
        const currentMode = isSpotlightMode.value ? "spotlight" : "grid";
        const currentStateKey = [
            currentMode,
            currentTile?.id || "none",
            currentSource || "none",
            currentReason || "none",
            meetingStore.preferredLayout,
        ].join("|");

        if (lastStageStateKey.value !== currentStateKey) {
            if (lastStageStateKey.value !== null) {
                scheduleStageTransitionSpinner();
            }
            console.log("[MeetingStage][State] changed", {
                currentMode,
                previousMode: lastStageMode.value,
                currentTileId: currentTile?.id || null,
                previousTileId: lastStageTileId.value,
                currentParticipantId: currentTile?.participant?.public_id?.toLowerCase() || null,
                previousParticipantId: lastStageTileId.value
                    ? String(lastStageTileId.value).replace(/:screen$/, "")
                    : null,
                currentSource,
                previousSource: lastStageSource.value,
                currentReason,
                previousReason: lastStageReason.value,
                preferredLayout: meetingStore.preferredLayout,
                pinnedParticipantId: meetingStore.pinnedParticipantId || null,
                activeSpeakerId: meetingStore.activeSpeakerId || null,
                paginatedTileIds: paginatedTiles.value.map((tile) => tile.id),
                currentStateKey,
                previousStateKey: lastStageStateKey.value,
            });
            lastStageStateKey.value = currentStateKey;
            lastStageTileId.value = currentTile?.id || null;
            lastStageSource.value = currentSource;
            lastStageReason.value = currentReason;
            lastStageMode.value = currentMode;
        }
    },
    { immediate: true, deep: true },
);

// Google Meet-style grid layout class based on tile count
const gridLayoutClass = computed(() => {
    const count = paginatedTiles.value.length;
    let baseClass = "";
    if (count === 0) baseClass = "grid-0";
    else if (count === 1) baseClass = "grid-1";
    else if (count === 2) baseClass = "grid-2";
    else if (count <= 4) baseClass = "grid-4";
    else if (count <= 6) baseClass = "grid-6";
    else if (count <= 9) baseClass = "grid-9";
    else if (count <= 12) baseClass = "grid-12";
    else baseClass = "grid-16";

    return `${baseClass} count-${count}`;
});

function prevPage() {
    if (isSpotlightMode.value && filmstripPage.value > 0) filmstripPage.value--;
    if (!isSpotlightMode.value && gridPage.value > 0) gridPage.value--;
}

function nextPage() {
    if (
        isSpotlightMode.value &&
        filmstripPage.value < totalFilmstripPages.value - 1
    )
        filmstripPage.value++;
    if (!isSpotlightMode.value && gridPage.value < totalGridPages.value - 1)
        gridPage.value++;
}

// ─── Mount / Unmount ─────────────────────────────────────────────────────────

onMounted(async () => {
    updateClock();

    if (!meetingId) {
        toast.error("Invalid meeting link", {
            description: "Please re-open the meeting from your invitation link.",
        });
        await router.replace("/");
        initializing.value = false;
        return;
    }

    // SFU PRO: Synchronize visible participants for selective media pulling
    // Priority order: spotlight > active speaker > talking > visible tiles > local
    watch(
        [paginatedTiles, spotlightTile],
        ([tiles, spotlight]) => {
            const visibleIds: string[] = [];
            const seen = new Set<string>();

            const addUnique = (id: string) => {
                const lower = id.toLowerCase();
                if (!seen.has(lower)) {
                    seen.add(lower);
                    visibleIds.push(lower);
                }
            };

            // Priority 1: Spotlight (always highest priority)
            if (spotlight) {
                const pid = spotlight.participant.public_id.toLowerCase();
                addUnique(spotlight.isScreen ? `${pid}:screen` : pid);
            }

            // Priority 2: Active speaker
            if (meetingStore.activeSpeakerId) {
                addUnique(meetingStore.activeSpeakerId.toLowerCase());
            }

            // Priority 3: All currently talking participants
            if (meetingStore.talkingParticipants) {
                meetingStore.talkingParticipants.forEach((pid: string) =>
                    addUnique(pid.toLowerCase()),
                );
            }

            // Priority 4: All visible tiles (grid or filmstrip)
            tiles.forEach((tile) => {
                const pid = tile.participant.public_id.toLowerCase();
                addUnique(tile.isScreen ? `${pid}:screen` : pid);
            });

            // Priority 5: Local participant (always include)
            if (meetingStore.localParticipant) {
                addUnique(
                    meetingStore.localParticipant.public_id.toLowerCase(),
                );
            }

            const spotlightPid = spotlight
                ? spotlight.participant.public_id.toLowerCase()
                : null;
            meetingStore.stream?.setVisibleParticipants?.(
                visibleIds,
                spotlightPid,
            );
        },
        { immediate: true, deep: true },
    );
    clockInterval = window.setInterval(updateClock, 10000);

    if (!participantId) {
        toast.error("Your join session is missing", {
            description: "Please rejoin from the lobby.",
        });
        router.push({ name: "meeting-lobby", params: { id: meetingId } });
        return;
    }

    try {
        initializing.value = true;
        await meetingStore.initializeMeeting(meetingId, participantId);

        const shouldInitializeMediaNow =
            meetingStore.localParticipant?.status === "admitted";
        const stream = meetingStore.localStream;

        if (stream) {
            const videoTrack = stream.getVideoTracks()[0];
            const audioTrack = stream.getAudioTracks()[0];
            await capTrackTo720p(videoTrack || null);

            // If the track in the stream is NOT a canvas track but we have an effect set,
            // it means we just came from the lobby and might need to re-bind.
            // But wait, Lobby already applied the effect to the stream in meetingStore.
            // We just need to identify the ORIGINAL track for future swaps.

            isCameraOn.value = videoTrack ? videoTrack.enabled : false;
            isMicOn.value = audioTrack ? audioTrack.enabled : false;

            // If we don't have an originalVideoTrack yet, and the current one is likely a real track
            // (or if we trust the lobby set it)
            if (!meetingStore.originalVideoTrack && videoTrack) {
                meetingStore.originalVideoTrack = videoTrack;
            }
        } else {
            isCameraOn.value = false;
            isMicOn.value = false;
        }

        if (shouldInitializeMediaNow) {
            if (stream) {
                // Lobby blur processor is torn down on route transition; rehydrate before publishing.
                await rehydrateJoinVideoEffectIfNeeded();
                await meetingStore.addLocalStream(meetingStore.localStream);
            } else {
                // Cold start: Join without an initial stream (camera/mic off)
                await meetingStore.addLocalStream(null);
            }

            void meetingService
                .sendSignal(meetingId, {
                    signal_type: "participant-joined",
                    signal_data: {},
                    sender_participant_public_id: participantId,
                })
                .catch((error) => {
                    console.warn("[MeetingRoom] participant-joined signal failed", {
                        status: error?.response?.status,
                        response: error?.response?.data,
                    });
                });
        }

        window.addEventListener("keydown", handleGlobalKeydown);

        // Auto-start screen share if joined via "Present" button
        if (route.query.present === "1") {
            if (!isScreenShareDeviceSupported.value) {
                toast.warning(
                    "Screen sharing is not supported on this device. Opening Whiteboard instead.",
                );
                whiteboardStore.isVisible = true;
            } else {
                toast.info(
                    "Joined in Companion Mode. Audio and video are disabled.",
                    { duration: 5000 },
                );
                setTimeout(() => {
                    toggleScreenShare();
                }, 2000); // Wait for SFU connection to establish
            }
        }

        // Start Heartbeat
        sendHeartbeat();
        heartbeatInterval = window.setInterval(sendHeartbeat, 30000);
    } catch (e) {
        console.error("[MeetingRoom] Failed to initialize:", e);
        toast.error("Something went wrong. Please rejoin from the lobby.");
        router.push({ name: "meeting-lobby", params: { id: meetingId } });
    } finally {
        initializing.value = false;
    }
});

function handleGlobalKeydown(e: KeyboardEvent) {
    if (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === "d") {
        e.preventDefault();
        showDevTool.value = !showDevTool.value;
        if (showDevTool.value) {
            toast.info("Dev Simulator Activated");
        }
    }
}

onUnmounted(() => {
    window.removeEventListener("keydown", handleGlobalKeydown);
    window.clearInterval(clockInterval);
    window.clearInterval(heartbeatInterval);
    backgroundBlur.stopProcessing();
    meetingStore.cleanup();
});

// ─── Controls ────────────────────────────────────────────────────────────────

const isScreenSharing = ref(false);
const screenStream = ref<MediaStream | null>(null);
let videoEffectApplyToken = 0;
const CAMERA_MAX_WIDTH = 1280;
const CAMERA_MAX_HEIGHT = 720;
const CAMERA_MAX_FPS = 30;
const MIC_TOGGLE_DEBOUNCE_MS = 250;
const CAMERA_TOGGLE_DEBOUNCE_MS = 350;
const SCREEN_SHARE_TOGGLE_DEBOUNCE_MS = 500;
const isMicToggleBusy = ref(false);
const isCameraToggleBusy = ref(false);
const isScreenShareToggleBusy = ref(false);
let lastMicToggleAt = 0;
let lastCameraToggleAt = 0;
let lastScreenShareToggleAt = 0;

function buildSelfCameraConstraints(deviceId?: string): MediaTrackConstraints {
    return {
        deviceId: deviceId || undefined,
        width: { ideal: CAMERA_MAX_WIDTH, max: CAMERA_MAX_WIDTH },
        height: { ideal: CAMERA_MAX_HEIGHT, max: CAMERA_MAX_HEIGHT },
        frameRate: { ideal: CAMERA_MAX_FPS, max: CAMERA_MAX_FPS },
    };
}

async function capTrackTo720p(track: MediaStreamTrack | null) {
    if (!track || track.kind !== "video" || !track.applyConstraints) return;

    try {
        await track.applyConstraints({
            width: { ideal: CAMERA_MAX_WIDTH, max: CAMERA_MAX_WIDTH },
            height: { ideal: CAMERA_MAX_HEIGHT, max: CAMERA_MAX_HEIGHT },
            frameRate: { ideal: CAMERA_MAX_FPS, max: CAMERA_MAX_FPS },
        });
    } catch (e) {
        console.warn("[MeetingRoom] Failed to enforce 720p cap on local video track", e);
    }
}

async function toggleMicDebounced() {
    const now = Date.now();
    if (isMicToggleBusy.value || now - lastMicToggleAt < MIC_TOGGLE_DEBOUNCE_MS) {
        return;
    }

    isMicToggleBusy.value = true;
    lastMicToggleAt = now;

    try {
        await toggleMic();
    } finally {
        const elapsed = Date.now() - now;
        const unlockIn = Math.max(0, MIC_TOGGLE_DEBOUNCE_MS - elapsed);
        window.setTimeout(() => {
            isMicToggleBusy.value = false;
        }, unlockIn);
    }
}

async function toggleCameraDebounced() {
    const now = Date.now();
    if (
        isCameraToggleBusy.value ||
        now - lastCameraToggleAt < CAMERA_TOGGLE_DEBOUNCE_MS
    ) {
        return;
    }

    isCameraToggleBusy.value = true;
    lastCameraToggleAt = now;

    try {
        await toggleCamera();
    } finally {
        const elapsed = Date.now() - now;
        const unlockIn = Math.max(0, CAMERA_TOGGLE_DEBOUNCE_MS - elapsed);
        window.setTimeout(() => {
            isCameraToggleBusy.value = false;
        }, unlockIn);
    }
}

async function toggleScreenShareDebounced() {
    const now = Date.now();
    if (
        isScreenShareToggleBusy.value ||
        now - lastScreenShareToggleAt < SCREEN_SHARE_TOGGLE_DEBOUNCE_MS
    ) {
        return;
    }

    isScreenShareToggleBusy.value = true;
    lastScreenShareToggleAt = now;

    try {
        await toggleScreenShare();
    } finally {
        const elapsed = Date.now() - now;
        const unlockIn = Math.max(0, SCREEN_SHARE_TOGGLE_DEBOUNCE_MS - elapsed);
        window.setTimeout(() => {
            isScreenShareToggleBusy.value = false;
        }, unlockIn);
    }
}

async function recoverCameraAfterScreenShareToggle() {
    if (!isCameraOn.value) return;

    try {
        const effect = videoCallStore.videoEffect;
        if (effect === "blur" || effect === "image") {
            await rehydrateJoinVideoEffectIfNeeded();
            meetingStore.sendSignal("camera-toggle", { enabled: true });
            return;
        }

        const currentVideoTrack = meetingStore.localStream?.getVideoTracks()[0];
        if (!currentVideoTrack || currentVideoTrack.readyState !== "live") return;

        await meetingStore.replaceTrack("video", currentVideoTrack);
        meetingStore.sendSignal("camera-toggle", { enabled: true });
    } catch (e) {
        console.warn(
            "[MeetingRoom] Camera recover after screen-share toggle failed",
            e,
        );
    }
}

async function toggleScreenShare() {
    if (isScreenSharing.value) {
        isScreenSharing.value = false;
        screenStream.value = null;
        await meetingStore.unpublishScreenTrack();
        meetingStore.clearSpotlight();
        await recoverCameraAfterScreenShareToggle();
    } else {
        if (!isScreenShareDeviceSupported.value) {
            if (route.query.present === "1") {
                toast.warning(
                    "Screen sharing is not supported on this device. Opening Whiteboard instead.",
                );
                whiteboardStore.isVisible = true;
            } else {
                toast.error("Screen sharing is not available on this device.");
            }
            return;
        }

        if (!canUseScreenShareRole.value) {
            toast.error("Only the host or co-host can share their screen.");
            return;
        }

        if (activeScreenSharerId.value) {
            if (!meetingStore.isModerator) {
                toast.error(
                    "Another participant is already sharing. Please wait for them to stop.",
                );
                return;
            }

            // Host/co-host takeover flow: request current sharer to stop first.
            meetingStore.sendSignal("force-stop-screen-share", {
                targetId: activeScreenSharerId.value,
            });
            await new Promise((resolve) => setTimeout(resolve, 400));
        }

        try {
            // Let the SDK handle the prompt to avoid "double prompt" issue
            const result = await meetingStore.publishScreenTrack();
            if (result && result.stream) {
                screenStream.value = result.stream;
                isScreenSharing.value = true;
                meetingStore.setSpotlight(
                    meetingStore.localParticipant!.public_id,
                );

                const screenTrack = result.stream.getVideoTracks()[0];
                if (screenTrack) {
                    screenTrack.onended = () => {
                        if (isScreenSharing.value) toggleScreenShare();
                    };
                }

                await recoverCameraAfterScreenShareToggle();
            }
        } catch (err) {
            console.error("Screen share failed:", err);

            // Smart Fallback for Mobile/Tablets
            if (route.query.present === "1") {
                toast.warning(
                    "Screen sharing restricted on this device. Opening Whiteboard instead!",
                );
                whiteboardStore.isVisible = true;
            } else {
                toast.error("Failed to share screen");
            }
        }
    }
}

watch(
    [screenShareHostCohostOnly, () => meetingStore.isModerator],
    ([hostOnly, isModerator]) => {
        if (hostOnly && !isModerator && isScreenSharing.value) {
            toast.info(
                "Screen sharing is now restricted to host/co-host. Your share has been stopped.",
            );
            void toggleScreenShare();
        }
    },
);

const toggleCamera = async () => {
    if (
        meetingStore.localParticipant?.is_camera_disabled_by_host &&
        !isCameraOn.value
    ) {
        toast.error("The host has disabled your camera.");
        return;
    }

    let stream = meetingStore.localStream;
    if (!stream) {
        stream = new MediaStream();
        meetingStore.setStream(stream);
    }

    if (!isCameraOn.value) {
        try {
            const newStream = await navigator.mediaDevices.getUserMedia({
                video: buildSelfCameraConstraints(
                    videoCallStore.selectedVideoDeviceId || undefined,
                ),
            });
            const videoTrack = newStream.getVideoTracks()[0];
            await capTrackTo720p(videoTrack || null);
            meetingStore.originalVideoTrack = videoTrack;

            let finalTrack = videoTrack;
            if (
                (videoCallStore.videoEffect === "blur" ||
                    videoCallStore.videoEffect === "image") &&
                videoTrack
            ) {
                finalTrack = await backgroundBlur.startVideoEffect(
                    videoTrack,
                    videoCallStore.videoEffect,
                    videoCallStore.backgroundImage || undefined,
                    videoCallStore.autoFraming,
                    videoCallStore.hasPhysicalGreenScreen,
                    videoCallStore.greenScreenColor,
                    videoCallStore.greenScreenThreshold,
                );
            }

            const updatedStream = new MediaStream([
                ...stream.getAudioTracks(),
                finalTrack,
            ]);
            meetingStore.setStream(updatedStream);
            isCameraOn.value = true;
            await meetingStore.replaceTrack("video", finalTrack);
            meetingStore.sendSignal("camera-toggle", { enabled: true });
        } catch (e) {
            console.error("Failed to start camera", e);
            toast.error("Could not access camera hardware.");
        }
    } else {
        // Unpublish first to guarantee remote clients receive a clean camera-off update
        // before we stop local tracks.
        await meetingStore.replaceTrack("video", null);
        isCameraOn.value = false;
        meetingStore.sendSignal("camera-toggle", { enabled: false });

        stream.getVideoTracks().forEach((t) => {
            t.stop();
        });
        if (meetingStore.originalVideoTrack) {
            meetingStore.originalVideoTrack.stop();
            meetingStore.originalVideoTrack = null;
        }
        backgroundBlur.stopProcessing();
        const updatedStream = new MediaStream(stream.getAudioTracks());
        meetingStore.setStream(updatedStream);
    }
};

const toggleMic = async () => {
    if (meetingStore.localParticipant?.is_muted_by_host && !isMicOn.value) {
        toast.error("The host has muted your microphone.");
        return;
    }

    let stream = meetingStore.localStream;
    if (!stream) {
        stream = new MediaStream();
        meetingStore.setStream(stream);
    }

    if (!isMicOn.value) {
        try {
            const newStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    deviceId: videoCallStore.selectedAudioDeviceId || undefined,
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            });
            const audioTrack = newStream.getAudioTracks()[0];
            const updatedStream = new MediaStream([
                ...stream.getVideoTracks(),
                audioTrack,
            ]);
            meetingStore.setStream(updatedStream);
            isMicOn.value = true;
            await meetingStore.replaceTrack("audio", audioTrack);
        } catch (e) {
            console.error("Failed to start mic", e);
            toast.error("Could not access microphone hardware.");
        }
    } else {
        stream.getAudioTracks().forEach((t) => {
            t.stop();
        });
        const updatedStream = new MediaStream(stream.getVideoTracks());
        meetingStore.setStream(updatedStream);
        isMicOn.value = false;
        await meetingStore.replaceTrack("audio", null);
    }
};

async function applyLocalVideoTrack(
    newTrack: MediaStreamTrack,
    previousTrack: MediaStreamTrack | null = null,
) {
    const currentStream = meetingStore.localStream;
    const audioTracks = currentStream
        ? currentStream.getAudioTracks().filter((t) => t.readyState === "live")
        : [];

    if (previousTrack) {
        newTrack.enabled = previousTrack.enabled;
    }

    const updatedStream = new MediaStream([...audioTracks, newTrack]);
    meetingStore.setStream(updatedStream);
    await meetingStore.replaceTrack("video", newTrack);
}

async function rehydrateJoinVideoEffectIfNeeded() {
    const effect = videoCallStore.videoEffect;
    if (effect !== "blur" && effect !== "image") return;
    if (!isCameraOn.value) return;
    if (!meetingStore.originalVideoTrack || !meetingStore.localStream) return;

    try {
        const refreshedTrack = await backgroundBlur.startVideoEffect(
            meetingStore.originalVideoTrack,
            effect,
            videoCallStore.backgroundImage || undefined,
            videoCallStore.autoFraming,
            videoCallStore.hasPhysicalGreenScreen,
            videoCallStore.greenScreenColor,
            videoCallStore.greenScreenThreshold,
        );

        const oldVideo = meetingStore.localStream.getVideoTracks()[0] || null;
        await applyLocalVideoTrack(refreshedTrack, oldVideo);
        console.info(
            `[MeetingRoom] Rehydrated join-time effect (${effect}) with track ${refreshedTrack.id}`,
        );
    } catch (e) {
        console.warn("[MeetingRoom] Failed to rehydrate join-time video effect", e);
    }
}

watch(
    [
        () => videoCallStore.videoEffect,
        () => videoCallStore.backgroundImage,
        () => videoCallStore.autoFraming,
        () => videoCallStore.hasPhysicalGreenScreen,
        () => videoCallStore.greenScreenColor,
        () => videoCallStore.greenScreenThreshold,
    ],
    async ([
        effect,
        bgImage,
        framing,
        hasGreenScreen,
        greenColor,
        threshold,
    ]) => {
        const applyToken = ++videoEffectApplyToken;
        if (
            !isCameraOn.value ||
            !meetingStore.originalVideoTrack ||
            !meetingStore.localStream
        )
            return;

        try {
            let newTrack: MediaStreamTrack;
            if (effect === "blur" || effect === "image") {
                newTrack = await backgroundBlur.startVideoEffect(
                    meetingStore.originalVideoTrack,
                    effect,
                    bgImage || undefined,
                    framing,
                    hasGreenScreen,
                    greenColor,
                    threshold,
                );
            } else {
                backgroundBlur.stopProcessing();
                newTrack = meetingStore.originalVideoTrack;
            }

            if (
                applyToken !== videoEffectApplyToken ||
                !isCameraOn.value ||
                !meetingStore.localStream
            ) {
                return;
            }

            const oldTrack = meetingStore.localStream.getVideoTracks()[0] || null;
            await applyLocalVideoTrack(newTrack, oldTrack);
            console.info(
                `[MeetingRoom] Applied effect: ${effect}, track: ${newTrack.id}`,
            );
        } catch (e) {
            console.error("[MeetingRoom] Failed to swap effect track", e);
        }
    },
);

watch(
    [
        () => videoCallStore.selectedAudioInput,
        () => videoCallStore.selectedVideoInput,
    ],
    async ([newAudio, newVideo], [oldAudio, oldVideo]) => {
        if (!meetingStore.localStream) return;
        const stream = meetingStore.localStream;

        if (isMicOn.value && newAudio !== oldAudio) {
            stream.getAudioTracks().forEach((t) => {
                t.stop();
                stream.removeTrack(t);
            });
            try {
                const newS = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        deviceId: newAudio || undefined,
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                    },
                });
                const track = newS.getAudioTracks()[0];
                stream.addTrack(track);
                await meetingStore.replaceTrack("audio", track);
            } catch (e) {
                console.error(e);
            }
        }

        if (isCameraOn.value && newVideo !== oldVideo) {
            // Hot swap camera device
            if (meetingStore.originalVideoTrack) {
                meetingStore.originalVideoTrack.stop();
            }
            backgroundBlur.stopProcessing();

            try {
                const newS = await navigator.mediaDevices.getUserMedia({
                    video: buildSelfCameraConstraints(newVideo || undefined),
                });
                const videoTrack = newS.getVideoTracks()[0];
                await capTrackTo720p(videoTrack || null);
                meetingStore.originalVideoTrack = videoTrack;

                let finalTrack = videoTrack;
                if (
                    (videoCallStore.videoEffect === "blur" ||
                        videoCallStore.videoEffect === "image") &&
                    videoTrack
                ) {
                    finalTrack = await backgroundBlur.startVideoEffect(
                        videoTrack,
                        videoCallStore.videoEffect,
                        videoCallStore.backgroundImage || undefined,
                        videoCallStore.autoFraming,
                        videoCallStore.hasPhysicalGreenScreen,
                        videoCallStore.greenScreenColor,
                        videoCallStore.greenScreenThreshold,
                    );
                }

                const oldTrack = stream.getVideoTracks()[0];
                await applyLocalVideoTrack(finalTrack, oldTrack || null);
            } catch (e) {
                console.error(e);
            }
        }
    },
);

watch(
    () => meetingStore.localParticipant?.is_muted_by_host,
    (isMuted) => {
        if (isMuted) {
            toast.error("The host has muted your microphone.");
            if (isMicOn.value) {
                toggleMic();
            }
        } else if (isMuted === false) {
            toast.success(
                "The host has allowed you to unmute your microphone.",
            );
        }
    },
);

watch(
    () => meetingStore.localParticipant?.is_camera_disabled_by_host,
    (isDisabled) => {
        if (isDisabled) {
            toast.error("The host has disabled your camera.");
            if (isCameraOn.value) {
                toggleCamera();
            }
        } else if (isDisabled === false) {
            toast.success("The host has allowed you to turn on your camera.");
        }
    },
);

const showHangupMenu = ref(false);
const lastReactionEmoji = ref("👍");

// ─── PRO Recording ──────────────────────────────────────────────────────────
// isRecording is sourced from the store (set via SignalingManager signals) so
// ALL participants see the REC badge when the host starts/stops recording.
const isRecording = computed(() => meetingStore.isRecording);

// Dev toggle: only show the record button when MEETING_RECORDING_ENABLED=true.
// Once real subscriptions exist, replace this with a billing check.
const recordingEnabled = computed(
    () => !!(meetingStore.meeting as any)?.recording_enabled,
);

const {
    startRecording,
    stopRecording,
    isStarting: isRecordingStarting,
    isStopping: isRecordingStopping,
    formattedDuration,
} = useRecordingComposable(meetingId, () => !!meetingStore.isHost);

async function toggleRecording() {
    if (isRecording.value) {
        await stopRecording();
    } else {
        await startRecording();
    }
}

function sendQuickReaction() {
    meetingStore.sendReaction(lastReactionEmoji.value);
}

// Watch for reactions sent via the picker to update the quick-action emoji
watch(
    () => meetingStore.activeReactions,
    (reactions) => {
        // If the local participant just sent a reaction, update the quick-action button
        if (reactions.length > 0) {
            const localPid = meetingStore.localParticipant?.public_id;
            const myReactions = reactions.filter(
                (r) => r.publicId === localPid,
            );
            if (myReactions.length > 0) {
                lastReactionEmoji.value =
                    myReactions[myReactions.length - 1].emoji;
            }
        }
    },
    { deep: true },
);

// Auto-disable laser pointer if screensharing ends or if enabled without screensharing
watch(
    [() => meetingStore.laserPointerMode, () => meetingStore.screenShares.size],
    ([mode, count]) => {
        if (mode !== "off" && count === 0) {
            console.log(
                "[LASER] Enforcement: No screensharing active. Disabling laser pointer.",
            );
            meetingStore.laserPointerMode = "off";
        }
    },
    { immediate: true },
);

function lowerAllHands() {
    // Clear all raised hands locally and broadcast
    const hands = Array.from(meetingStore.raisedHands);
    hands.forEach((pid) => {
        presence_lowerHand(pid);
    });
}

function presence_lowerHand(pid: string) {
    // Lower individual hand via presence manager
    const store = meetingStore;
    // Use the toggleHandState via the store's internal presence manager
    // Since raisedHands is a Set, we need to remove from it and broadcast
    if (store.raisedHands.has(pid)) {
        store.raisedHands.delete(pid);
        // Force reactivity
        store.raisedHands = new Set(store.raisedHands);
    }
}

const leaveMeeting = () => {
    showHangupMenu.value = false;
    meetingStore.localStream?.getTracks().forEach((t) => t.stop());
    router.push({ name: "meeting-lobby", params: { id: meetingId } });
};

const endMeetingForAll = async () => {
    showHangupMenu.value = false;
    await meetingStore.endMeeting();
};

function formatDate(dateString: string) {
    if (!dateString) return "N/A";
    try {
        return new Date(dateString).toLocaleString(undefined, {
            dateStyle: "medium",
            timeStyle: "short",
        });
    } catch (e) {
        return dateString;
    }
}

async function copyToClipboard(text: string | null | undefined, label: string) {
    const normalizedText = String(text ?? "").trim();
    if (!normalizedText || normalizedText.toLowerCase() === "undefined") {
        toast.error(`${label} is unavailable`);
        return;
    }

    try {
        await navigator.clipboard.writeText(normalizedText);
        toast.success(`${label} copied to clipboard`);
    } catch (error) {
        toast.error(`Failed to copy ${label.toLowerCase()}`);
    }
}

function copyMeetingLink() {
    copyToClipboard(meetingShareUrl.value, "Meeting link");
}

// ── Network Stats Tracking ──────────────────────────────────────────────────
const networkStats = reactive({
    bitrate: 0,
    packetLoss: 0,
    rtt: 0,
    score: 0,
});

let lastBytes = 0;
let lastPacketsLost = 0;
let lastPacketsReceived = 0;
let lastStatsTime = Date.now();
let statsInterval: number | null = null;
const poorConnectionDetected = ref(false);
let poorConnectionTimer = 0;
let rttStaleCount = 0;
let smoothedRtt = 0;
const POOR_CONNECTION_THRESHOLD = 3; // 3 intervals (9 seconds)

async function updateNetworkStats() {
    const pc = meetingStore.sfuPc();
    if (!pc) {
        // SDK Mode Fallback: Sync score from store
        if (meetingStore.meeting?.recording_enabled) {
            networkStats.score = meetingStore.networkScore;
            networkStats.bitrate = meetingStore.networkBitrate || 0;
            networkStats.packetLoss = meetingStore.networkPacketLoss || 0;
            networkStats.rtt = meetingStore.networkRtt || 0;
        }
        return;
    }

    try {
        const stats = await pc.getStats();
        const now = Date.now();
        const delta = (now - lastStatsTime) / 1000;
        lastStatsTime = now;

        let currentBytes = 0;
        let totalPacketsLost = 0;
        let totalPacketsReceived = 0;

        // Find the active transport to pinpoint the correct candidate pair
        let activeCandidatePairId: string | null = null;
        stats.forEach((report) => {
            if (report.type === "transport") {
                activeCandidatePairId = report.selectedCandidatePairId;
            }
            // Bitrate: Outbound + Inbound
            if (report.type === "outbound-rtp") {
                currentBytes += report.bytesSent || 0;
            }
            if (report.type === "inbound-rtp") {
                currentBytes += report.bytesReceived || 0;
                totalPacketsLost += report.packetsLost || 0;
                totalPacketsReceived += report.packetsReceived || 0;
            }
        });

        let rttUpdated = false;
        stats.forEach((report) => {
            // RTT from active candidate-pair
            if (
                report.type === "candidate-pair" &&
                (report.id === activeCandidatePairId ||
                    (report.state === "succeeded" && report.nominated))
            ) {
                let newRtt = 0;
                const rawRtt = report.currentRoundTripTime || 0;
                
                if (rawRtt > 0) {
                    newRtt = rawRtt > 1.0 ? rawRtt : rawRtt * 1000;
                } else if (report.totalRoundTripTime && report.responsesReceived > 0) {
                    const avgRtt = report.totalRoundTripTime / report.responsesReceived;
                    newRtt = avgRtt > 1.0 ? avgRtt : avgRtt * 1000;
                }

                if (newRtt > 0 && newRtt < 10000) {
                    networkStats.rtt = newRtt;
                    rttUpdated = true;
                }
            }
        });

        // If RTT was never updated from stats, don't let a stale value affect scoring
        if (!rttUpdated && networkStats.rtt > 0) {
            rttStaleCount++;
        } else {
            rttStaleCount = 0;
        }
        // After 5 stale readings (~15s), consider RTT unreliable and zero it out
        const effectiveRtt = rttStaleCount >= 5 ? 0 : networkStats.rtt;
        
        // RTT Smoothing
        if (effectiveRtt > 0) {
            if (smoothedRtt === 0) smoothedRtt = effectiveRtt;
            else smoothedRtt = (smoothedRtt * 0.7) + (effectiveRtt * 0.3);
        }

        if (lastBytes > 0 && delta > 0) {
            networkStats.bitrate =
                ((currentBytes - lastBytes) * 8) / (delta * 1000); // kbps
        }
        lastBytes = currentBytes;

        // INTERVAL-BASED LOSS CALCULATION
        let deltaLost = totalPacketsLost - lastPacketsLost;
        let deltaReceived = totalPacketsReceived - lastPacketsReceived;
        
        // Guard against stats reset (e.g. new PC or SFU reconnect)
        if (deltaLost < 0 || deltaReceived < 0) {
            deltaLost = Math.max(0, totalPacketsLost);
            deltaReceived = Math.max(0, totalPacketsReceived);
        }
        
        const deltaTotal = deltaLost + deltaReceived;
        const lossPercent = deltaTotal > 0 ? (deltaLost / deltaTotal) * 100 : 0;
        
        lastPacketsLost = totalPacketsLost;
        lastPacketsReceived = totalPacketsReceived;
        networkStats.packetLoss = lossPercent;

        // ── Scoring (0=Good, 1=Fair, 2=Poor) ──
        // RTT is the PRIMARY signal — it is the ground truth for connection quality.
        // WebRTC's packetsLost counter is unreliable: it reports phantom loss from
        // placeholder transceivers, SFU renegotiation sequence gaps, and idle/muted
        // tracks. A 7ms RTT with "15% loss" means the loss is fake.
        //
        // Rule: If RTT < 100ms, the connection is definitely Good regardless of
        // what the loss counter reports. Loss only matters when RTT confirms congestion.
        let oldScore = networkStats.score;

        if (smoothedRtt > 0 && smoothedRtt < 100) {
            // RTT is excellent — connection is definitively good
            networkStats.score = 0;
            poorConnectionTimer = 0;
            poorConnectionDetected.value = false;
        } else if (smoothedRtt >= 500 || (lossPercent > 10 && smoothedRtt >= 200)) {
            // RTT confirms real congestion
            networkStats.score = 2;
            poorConnectionTimer++;
            if (poorConnectionTimer >= POOR_CONNECTION_THRESHOLD) {
                poorConnectionDetected.value = true;
            }
        } else if (smoothedRtt >= 200 || (lossPercent > 5 && smoothedRtt >= 100)) {
            // Moderate RTT with some loss — Fair
            networkStats.score = 1;
            poorConnectionTimer = 0;
            poorConnectionDetected.value = false;
        } else {
            networkStats.score = 0;
            poorConnectionTimer = 0;
            poorConnectionDetected.value = false;
        }

        if (networkStats.score !== oldScore || networkStats.score >= 1) {
            console.log(
                `[NetworkStats] Score: ${networkStats.score} | RTT: ${smoothedRtt.toFixed(0)}ms${rttStaleCount >= 5 ? " (stale)" : ""} | Loss: ${lossPercent.toFixed(2)}% (${deltaLost}/${deltaTotal} pkts) | Bitrate: ${(networkStats.bitrate || 0).toFixed(0)}kbps | Timer: ${poorConnectionTimer}/${POOR_CONNECTION_THRESHOLD}`,
            );
        }
    } catch (e) {
        // Silent fail for stats
    }
}

onMounted(() => {
    statsInterval = window.setInterval(updateNetworkStats, 3000);
});

onBeforeUnmount(() => {
    if (statsInterval) window.clearInterval(statsInterval);
    if (stageTransitionSpinnerTimer) {
        window.clearTimeout(stageTransitionSpinnerTimer);
    }
});
</script>

<style scoped>
/* ─── Root & Reset ─────────────────────────────────────────────────────────── */
.gmeet-root {
    --ctrl-neutral-bg: linear-gradient(
        145deg,
        rgba(250, 252, 255, 0.96),
        rgba(241, 245, 249, 0.96)
    );
    --ctrl-neutral-border: rgba(148, 163, 184, 0.38);
    --ctrl-neutral-fg: #1f2937;
    --ctrl-neutral-glow: rgba(51, 65, 85, 0.12);
    --ctrl-accent-blue: linear-gradient(
        145deg,
        rgba(239, 244, 251, 0.98),
        rgba(230, 238, 248, 0.96)
    );
    --ctrl-accent-blue-border: rgba(100, 116, 139, 0.34);
    --ctrl-accent-blue-fg: #334155;
    --ctrl-accent-teal: linear-gradient(
        145deg,
        rgba(235, 244, 243, 0.98),
        rgba(227, 239, 237, 0.96)
    );
    --ctrl-accent-teal-border: rgba(107, 114, 128, 0.34);
    --ctrl-accent-teal-fg: #365a56;
    --ctrl-accent-violet: linear-gradient(
        145deg,
        rgba(238, 241, 250, 0.98),
        rgba(229, 233, 246, 0.96)
    );
    --ctrl-accent-violet-border: rgba(99, 102, 241, 0.26);
    --ctrl-accent-violet-fg: #374151;
    --ctrl-accent-amber: linear-gradient(
        145deg,
        rgba(249, 245, 235, 0.98),
        rgba(244, 238, 223, 0.96)
    );
    --ctrl-accent-amber-border: rgba(161, 98, 7, 0.24);
    --ctrl-accent-amber-fg: #6b4f1d;
    --ctrl-accent-coral: linear-gradient(
        145deg,
        rgba(247, 239, 240, 0.98),
        rgba(241, 233, 235, 0.96)
    );
    --ctrl-accent-coral-border: rgba(155, 107, 123, 0.34);
    --ctrl-accent-coral-fg: #6b3949;
    --toolbox-bg: linear-gradient(
        160deg,
        rgba(248, 250, 252, 0.98),
        rgba(241, 245, 249, 0.96)
    );
    --toolbox-border: rgba(148, 163, 184, 0.36);
    --toolbox-text: #1f2937;
    --toolbox-subtext: #64748b;
    --toolbox-chip-bg: rgba(226, 232, 240, 0.72);
    --toolbox-chip-bg-hover: rgba(203, 213, 225, 0.88);
    --toolbox-divider: rgba(148, 163, 184, 0.32);
    display: flex;
    flex-direction: column;
    height: 100vh;
    height: 100dvh;
    background: var(--surface-primary);
    color: var(--text-primary);
    font-family:
        "Google Sans",
        "Roboto",
        "Segoe UI",
        system-ui,
        -apple-system,
        sans-serif;
    overflow: hidden;
    position: relative;
}

.dark .gmeet-root {
    --ctrl-neutral-bg: linear-gradient(
        145deg,
        rgba(31, 41, 55, 0.96),
        rgba(17, 24, 39, 0.96)
    );
    --ctrl-neutral-border: rgba(100, 116, 139, 0.35);
    --ctrl-neutral-fg: #e5e7eb;
    --ctrl-neutral-glow: rgba(96, 165, 250, 0.16);
    --ctrl-accent-blue: linear-gradient(
        145deg,
        rgba(51, 65, 85, 0.95),
        rgba(41, 53, 72, 0.92)
    );
    --ctrl-accent-blue-border: rgba(148, 163, 184, 0.36);
    --ctrl-accent-blue-fg: #cbd5e1;
    --ctrl-accent-teal: linear-gradient(
        145deg,
        rgba(45, 66, 66, 0.92),
        rgba(34, 55, 55, 0.9)
    );
    --ctrl-accent-teal-border: rgba(107, 114, 128, 0.36);
    --ctrl-accent-teal-fg: #c7d8d5;
    --ctrl-accent-violet: linear-gradient(
        145deg,
        rgba(55, 65, 85, 0.92),
        rgba(46, 54, 74, 0.9)
    );
    --ctrl-accent-violet-border: rgba(129, 140, 248, 0.3);
    --ctrl-accent-violet-fg: #d1d5db;
    --ctrl-accent-amber: linear-gradient(
        145deg,
        rgba(76, 62, 37, 0.88),
        rgba(65, 52, 30, 0.86)
    );
    --ctrl-accent-amber-border: rgba(161, 98, 7, 0.35);
    --ctrl-accent-amber-fg: #e6d6b3;
    --ctrl-accent-coral: linear-gradient(
        145deg,
        rgba(84, 53, 61, 0.9),
        rgba(71, 45, 52, 0.88)
    );
    --ctrl-accent-coral-border: rgba(190, 148, 161, 0.35);
    --ctrl-accent-coral-fg: #f0dbe1;
    --toolbox-bg: linear-gradient(
        160deg,
        rgba(17, 24, 39, 0.96),
        rgba(15, 23, 42, 0.94)
    );
    --toolbox-border: rgba(100, 116, 139, 0.34);
    --toolbox-text: #e5e7eb;
    --toolbox-subtext: #94a3b8;
    --toolbox-chip-bg: rgba(30, 41, 59, 0.78);
    --toolbox-chip-bg-hover: rgba(51, 65, 85, 0.9);
    --toolbox-divider: rgba(100, 116, 139, 0.28);
}

/* ─── Bottom-Fixed Control Bar ─────────────────────────────────────────── */
.app-bottom-bar {
    width: 100%;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    background:
        radial-gradient(
            55% 140% at 10% 0%,
            rgba(56, 189, 248, 0.14),
            transparent 78%
        ),
        radial-gradient(
            45% 140% at 92% 100%,
            rgba(99, 102, 241, 0.14),
            transparent 80%
        ),
        var(--surface-elevated);
    border-top: 1px solid rgba(148, 163, 184, 0.32);
    box-shadow: 0 -10px 32px rgba(15, 23, 42, 0.14);
    z-index: 100;
    flex-shrink: 0;
}

.dark .app-bottom-bar {
    border-top-color: rgba(148, 163, 184, 0.2);
    box-shadow: 0 -10px 32px rgba(2, 6, 23, 0.5);
}

.bar-section {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
}

.bar-section--left {
    justify-content: flex-start;
}

.bar-section--center {
    justify-content: center;
    gap: 8px;
}

.bar-section--right {
    justify-content: flex-end;
}

/* Info Section */
.meeting-info-pill {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 16px;
    height: 44px;
    border-radius: 22px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.meeting-info-pill:hover {
    background: var(--surface-tertiary);
}

.info-pill--active {
    background: var(--surface-tertiary);
}

.info-time {
    color: var(--text-primary);
    font-size: 15px;
    font-weight: 500;
}

.info-divider {
    width: 1px;
    height: 16px;
    background: var(--surface-secondary);
}

.info-code {
    color: var(--text-secondary);
    font-size: 14px;
    font-weight: 400;
    letter-spacing: 0.5px;
}

.recording-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 2px 10px;
    background: #ea4335;
    color: #ffffff;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    margin-left: 12px;
    box-shadow: 0 0 12px rgba(234, 67, 53, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.2);
    height: 24px;
}

.recording-dot {
    width: 6px;
    height: 6px;
    background: #ffffff;
    border-radius: 50%;
    animation: flash 1.5s infinite;
}

@keyframes flash {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.3;
    }
}

@keyframes pulse-red {
    0% {
        box-shadow: 0 0 0 0 rgba(234, 67, 53, 0.7);
    }
    70% {
        box-shadow: 0 0 0 6px rgba(234, 67, 53, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(234, 67, 53, 0);
    }
}

/* Control Buttons (Base) */
.ctrl-btn {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
}

.ctrl-btn-inner {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
}

.ctrl-btn-spinner {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 2px solid rgba(100, 116, 139, 0.3);
    border-top-color: currentColor;
    animation: ctrl-btn-spin 0.8s linear infinite;
}

.ctrl-btn:hover {
    background: var(--surface-tertiary);
}

.ctrl-btn--off {
    background: linear-gradient(140deg, #ef4444, #dc2626) !important;
    color: white !important;
    border-color: rgba(248, 113, 113, 0.65) !important;
    box-shadow: 0 10px 24px rgba(220, 38, 38, 0.32);
}

.ctrl-btn--off:hover {
    background: linear-gradient(140deg, #dc2626, #b91c1c) !important;
}

.ctrl-btn--active {
    background: var(--ctrl-accent-blue);
    color: var(--ctrl-accent-blue-fg);
    border-color: var(--ctrl-accent-blue-border);
    box-shadow: 0 8px 18px rgba(51, 65, 85, 0.2);
}

.mic-volume-ring {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    border-radius: 50%;
    background: rgba(71, 85, 105, 0.24);
    pointer-events: none;
    transition:
        transform 0.05s linear,
        opacity 0.1s ease-out;
    z-index: 0;
}

.ctrl-btn--sharing {
    background: linear-gradient(140deg, #16a34a, #15803d) !important;
    color: white !important;
    border-color: rgba(134, 239, 172, 0.62) !important;
    box-shadow: 0 10px 22px rgba(22, 163, 74, 0.35);
}

.ctrl-btn--sharing:hover {
    background: linear-gradient(140deg, #15803d, #166534) !important;
}

.ctrl-btn--hangup {
    background: linear-gradient(140deg, #ef4444, #dc2626);
    color: white;
    width: 56px;
    border-radius: 28px;
    border: 1px solid rgba(248, 113, 113, 0.7);
    box-shadow: 0 10px 22px rgba(220, 38, 38, 0.32);
}

.ctrl-btn--hangup:hover {
    background: linear-gradient(140deg, #dc2626, #b91c1c);
    box-shadow: 0 12px 24px rgba(220, 38, 38, 0.4);
}

/* Grouped Components */
.reaction-group {
    display: flex;
    align-items: center;
    background: var(--surface-tertiary);
    border-radius: 24px;
    padding: 2px;
}

.reaction-main-btn {
    width: 40px;
    height: 40px;
}

.reaction-trigger-btn {
    width: 28px;
    height: 40px;
    border-radius: 0 20px 20px 0;
}

.hangup-wrapper {
    position: relative;
    margin-left: 8px;
}

.modern-hangup-menu {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    margin-bottom: 16px;
    background: var(--toolbox-bg);
    border: 1px solid var(--toolbox-border);
    border-radius: 12px;
    padding: 8px;
    width: 200px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
    display: flex;
    flex-direction: column;
    gap: 4px;
    z-index: 101;
}

.menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    background: transparent;
    border: none;
    color: var(--text-primary);
    font-size: 14px;
    border-radius: 8px;
    cursor: pointer;
    text-align: left;
    transition: background 0.15s;
}

.menu-item:hover {
    background: var(--surface-tertiary);
}

.menu-item--danger {
    color: #f28b82;
}

.menu-item--danger:hover {
    background: rgba(234, 67, 53, 0.15);
}

.menu-action-item--active {
    background: rgba(234, 67, 53, 0.1) !important;
    border-left: 3px solid #ea4335;
}

.locked-status-badge {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: rgba(234, 67, 53, 0.15);
    color: #f28b82;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid rgba(234, 67, 53, 0.3);
}

.badge-count {
    position: absolute;
    top: 4px;
    right: 4px;
    background: #ea4335;
    color: white;
    font-size: 10px;
    font-weight: 700;
    min-width: 16px;
    height: 16px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--surface-primary);
}

/* ─── Main Body ────────────────────────────────────────────────────────────── */
.gmeet-body {
    flex: 1;
    display: flex;
    min-height: 0;
    overflow: hidden;
    transition: padding-right 0.25s ease;
}
.gmeet-body.has-panel {
    /* panel takes 320px */
}

.gmeet-stage {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 0; /* Removed padding for seamless edge-to-edge feel */
    gap: 0;
    position: relative;
    min-width: 0;
}

/* ─── Grid Layout ──────────────────────────────────────────────────────────── */
.grid-container {
    flex: 1;
    display: grid;
    gap: 2px; /* Minimal gap for separation without "boxed" look */
    padding: 0;
    min-height: 0;
    align-content: center;
    justify-content: center;
}

.grid-0 {
    place-items: center;
}

.grid-1 {
    grid-template-columns: 1fr;
    grid-template-rows: 1fr;
    margin: 0;
    width: 100%;
    /* Removed max-width to allow full-screen immersive view */
}

.grid-2 {
    grid-template-columns: repeat(2, 1fr);
    grid-template-rows: 1fr;
    align-items: center;
}

.grid-4 {
    grid-template-columns: repeat(4, 1fr);
    grid-template-rows: repeat(2, 1fr);
}
.grid-4 .grid-tile {
    grid-column: span 2;
}
.grid-container.count-3 .grid-tile:nth-child(3) {
    grid-column: 2 / span 2;
}

.grid-6 {
    grid-template-columns: repeat(6, 1fr);
    grid-template-rows: repeat(2, 1fr);
}
.grid-6 .grid-tile {
    grid-column: span 2;
}
.grid-container.count-5 .grid-tile:nth-child(4) {
    grid-column: 2 / span 2;
}
.grid-container.count-5 .grid-tile:nth-child(5) {
    grid-column: 4 / span 2;
}

.grid-9 {
    grid-template-columns: repeat(6, 1fr);
    grid-template-rows: repeat(3, 1fr);
}
.grid-9 .grid-tile {
    grid-column: span 2;
}
.grid-container.count-7 .grid-tile:nth-child(7) {
    grid-column: 3 / span 2;
}
.grid-container.count-8 .grid-tile:nth-child(7) {
    grid-column: 2 / span 2;
}
.grid-container.count-8 .grid-tile:nth-child(8) {
    grid-column: 4 / span 2;
}

.grid-12 {
    grid-template-columns: repeat(8, 1fr);
    grid-template-rows: repeat(3, 1fr);
}
.grid-12 .grid-tile {
    grid-column: span 2;
}
.grid-container.count-10 .grid-tile:nth-child(9) {
    grid-column: 3 / span 2;
}
.grid-container.count-10 .grid-tile:nth-child(10) {
    grid-column: 5 / span 2;
}
.grid-container.count-11 .grid-tile:nth-child(9) {
    grid-column: 2 / span 2;
}
.grid-container.count-11 .grid-tile:nth-child(10) {
    grid-column: 4 / span 2;
}
.grid-container.count-11 .grid-tile:nth-child(11) {
    grid-column: 6 / span 2;
}

.grid-16 {
    grid-template-columns: repeat(8, 1fr);
    grid-template-rows: repeat(4, 1fr);
}
.grid-16 .grid-tile {
    grid-column: span 2;
}
.grid-container.count-13 .grid-tile:nth-child(13) {
    grid-column: 4 / span 2;
}
.grid-container.count-14 .grid-tile:nth-child(13) {
    grid-column: 3 / span 2;
}
.grid-container.count-14 .grid-tile:nth-child(14) {
    grid-column: 5 / span 2;
}
.grid-container.count-15 .grid-tile:nth-child(13) {
    grid-column: 2 / span 2;
}
.grid-container.count-15 .grid-tile:nth-child(14) {
    grid-column: 4 / span 2;
}
.grid-container.count-15 .grid-tile:nth-child(15) {
    grid-column: 6 / span 2;
}

.grid-tile {
    /* Critical formula for 16:9 boxes inside dynamic grid cells */
    aspect-ratio: 16 / 9;
    height: 100%;
    max-height: 100%;
    max-width: 100%;
    margin: auto;

    background: var(--surface-secondary);
    border-radius: 0; /* Square edges for seamless tile-to-tile look */
    overflow: hidden;
    position: relative;
    display: flex;
    justify-content: center;
    align-items: center;
    border: 1px solid rgba(255, 255, 255, 0.05); /* Minimal separator */
    transition:
        transform 0.2s cubic-bezier(0.16, 1, 0.3, 1),
        box-shadow 0.2s ease;
}

.grid-nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 30;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: none;
    background: var(--surface-overlay);
    color: var(--text-inverse);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition:
        background 0.15s,
        transform 0.15s;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
.grid-nav:hover {
    background: var(--surface-secondary);
    transform: translateY(-50%) scale(1.08);
}
.grid-nav--left {
    left: 16px;
}
.grid-nav--right {
    right: 16px;
}

/* ─── Spotlight Layout ─────────────────────────────────────────────────────── */
.spotlight-main {
    flex: 1;
    background: #000;
    border-radius: 8px;
    overflow: hidden;
    min-height: 0;
}

.filmstrip {
    display: flex;
    align-items: center;
    gap: 8px;
    height: 120px;
    flex-shrink: 0;
    position: relative;
}

.filmstrip-tiles {
    display: flex;
    gap: 8px;
    flex: 1;
    overflow: hidden;
}

.filmstrip-tile {
    width: 180px;
    flex-shrink: 0;
    aspect-ratio: 16/9;
    background: var(--surface-secondary);
    border-radius: 8px;
    padding: 8px 12px;
    border: 1px solid var(--border-default);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.filmstrip-nav {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: none;
    background: var(--surface-overlay);
    color: var(--text-inverse);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    z-index: 5;
}
.filmstrip-nav:hover {
    background: var(--surface-tertiary);
}

/* ─── PiP Self-View ────────────────────────────────────────────────────────── */
.pip-self-view {
    position: absolute;
    bottom: 16px;
    right: 16px;
    width: 200px;
    aspect-ratio: 16/9;
    border-radius: 8px;
    overflow: hidden;
    z-index: 35;
    background: var(--surface-tertiary);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}
.pip-self-view:hover {
    transform: scale(1.04);
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.6);
}

/* ─── Side Panels ──────────────────────────────────────────────────────────── */
.side-panel {
    width: 320px;
    height: 100%;
    flex-shrink: 0;
    background: var(--surface-secondary);
    display: flex;
    flex-direction: column;
    border-left: 1px solid var(--border-default);
    z-index: 25;
}

/* Meeting Details Overlay Refinements */
.meeting-details-overlay {
    position: absolute;
    bottom: 90px;
    left: 24px;
    z-index: 200;
}

.details-panel {
    width: 360px;
    max-width: calc(100vw - 48px);
    background: var(--surface-elevated);
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
    border: 1px solid var(--border-default);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.details-header {
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--border-default);
}

.details-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 500;
    color: var(--text-primary);
}

.details-close {
    background: transparent;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 4px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.details-close:hover {
    background: var(--surface-tertiary);
    color: var(--text-primary);
}

.details-body {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 24px;
    max-height: 500px;
    overflow-y: auto;
}

.details-group {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.details-group label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-secondary);
    font-weight: 700;
}

.detail-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.detail-label {
    font-size: 13px;
    color: var(--text-secondary);
    flex-shrink: 0;
}

.detail-value {
    font-size: 11px;
    color: var(--text-primary);
    text-align: right;
    word-break: break-all;
    flex: 1;
}

.copy-small-btn {
    background: transparent;
    border: none;
    color: #8ab4f8;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.copy-small-btn:hover {
    background: rgba(138, 180, 248, 0.1);
}

.link-box {
    background: var(--surface-tertiary);
    border-radius: 8px;
    padding: 12px;
    border: 1px solid var(--border-default);
}

.copy-full-link-btn {
    width: 100%;
    background: #8ab4f8;
    color: #202124;
    border: none;
    padding: 10px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.2s ease;
}

.copy-full-link-btn:hover {
    background: #aecbfa;
}

.panel-enter-active,
.panel-leave-active {
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.panel-enter-from {
    transform: translateY(20px) scale(0.95);
    opacity: 0;
}
.panel-leave-to {
    transform: translateY(20px) scale(0.95);
    opacity: 0;
}

.side-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-subtle);
    flex-shrink: 0;
}
.side-panel-header h3 {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
    letter-spacing: 0.05em;
    padding-bottom: 8px;
    margin-bottom: -19px; /* Align with header border */
}

/* Custom Tab Styling */
.participant-tabs {
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 0;
    gap: 4px;
    margin-right: 12px;
    padding: 4px;
    background: var(--surface-tertiary);
    border: 1px solid var(--border-default);
    border-radius: 12px;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
}

.tab-item {
    cursor: pointer;
    border: none;
    border-radius: 8px;
    transition: all 0.2s ease;
    text-transform: uppercase;
    flex: 1;
    min-width: 0;
    height: 32px;
    line-height: 32px;
    text-align: center;
    font-size: 11px;
    letter-spacing: 0.08em;
    color: var(--text-secondary);
    user-select: none;
    white-space: nowrap;
}

.tab-item:hover {
    background: var(--surface-secondary);
    color: var(--text-primary);
}

.tab-item--active {
    color: #8ab4f8;
    background: var(--surface-secondary);
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.25),
        inset 0 0 0 1px rgba(138, 180, 248, 0.35);
}

.requests-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #ea4335;
    color: white;
    font-size: 10px;
    font-weight: 700;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    margin-left: 6px;
    vertical-align: middle;
}

.side-panel-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
.side-panel-close:hover {
    background: var(--surface-tertiary);
    color: var(--text-primary);
}

.side-panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}

.ctrl-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--ctrl-neutral-bg);
    color: var(--ctrl-neutral-fg);
    border: 1px solid var(--ctrl-neutral-border);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    padding: 0;
    box-shadow:
        0 2px 6px rgba(15, 23, 42, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 0.32);
}

.ctrl-btn:hover {
    transform: translateY(-1px);
    box-shadow:
        0 10px 24px rgba(15, 23, 42, 0.16),
        0 0 0 4px var(--ctrl-neutral-glow);
}

.ctrl-btn:active {
    transform: translateY(0);
}

.ctrl-btn--media {
    background: var(--ctrl-accent-blue);
    border-color: var(--ctrl-accent-blue-border);
    color: var(--ctrl-accent-blue-fg);
}

.ctrl-btn--screen {
    background: var(--ctrl-accent-teal);
    border-color: var(--ctrl-accent-teal-border);
    color: var(--ctrl-accent-teal-fg);
}

.ctrl-btn--hand {
    background: var(--ctrl-accent-amber);
    border-color: var(--ctrl-accent-amber-border);
    color: var(--ctrl-accent-amber-fg);
}

.ctrl-btn--annotate,
.ctrl-btn--activities,
.ctrl-btn--more {
    background: var(--ctrl-accent-violet);
    border-color: var(--ctrl-accent-violet-border);
    color: var(--ctrl-accent-violet-fg);
}

.ctrl-btn--participants,
.ctrl-btn--chat,
.ctrl-btn--settings {
    background: var(--ctrl-accent-blue);
    border-color: var(--ctrl-accent-blue-border);
    color: var(--ctrl-accent-blue-fg);
}

.ctrl-btn--lock,
.ctrl-btn--record-toggle {
    background: var(--ctrl-accent-coral);
    border-color: var(--ctrl-accent-coral-border);
    color: var(--ctrl-accent-coral-fg);
}

.btn--alert,
.ctrl-btn--requests {
    background: var(--ctrl-accent-amber);
    border-color: var(--ctrl-accent-amber-border);
    color: var(--ctrl-accent-amber-fg);
    box-shadow: 0 8px 18px rgba(120, 113, 108, 0.2);
}

.participant-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 8px;
    transition: background 0.12s;
}
.participant-row:hover {
    background: var(--surface-tertiary);
}

.participant-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--surface-tertiary);
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 600;
    flex-shrink: 0;
}

.participant-info {
    flex: 1;
    min-width: 0;
}

.participant-name {
    font-size: 13px;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    gap: 6px;
}

.you-badge {
    font-size: 10px;
    color: var(--color-info-fg);
    background: var(--color-info-bg);
    padding: 1px 6px;
    border-radius: 4px;
    font-weight: 500;
}

.participant-status-icons {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}

.panel-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 40px 0;
    color: var(--text-secondary);
    font-size: 13px;
}

.request-card {
    background: var(--surface-tertiary);
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 8px;
}

.request-info {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.request-name {
    font-size: 13px;
    color: var(--text-primary);
    font-weight: 500;
    margin: 0;
}
.request-sub {
    font-size: 11px;
    color: var(--text-secondary);
    margin: 0;
}

.request-actions {
    display: flex;
    gap: 8px;
}

.request-btn {
    flex: 1;
    padding: 6px 0;
    border-radius: 20px;
    border: none;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}

.request-btn--approve {
    background: #8ab4f8;
    color: #202124;
}
.request-btn--approve:hover {
    background: #aecbfa;
}

.request-btn--reject {
    background: transparent;
    color: #e8eaed;
    border: 1px solid #5f6368;
}
.request-btn--reject:hover {
    background: rgba(234, 67, 53, 0.15);
    color: #f28b82;
    border-color: #f28b82;
}

@media (max-width: 800px) {
    .app-bottom-bar {
        padding: 0 12px;
        height: 72px;
    }
    .bar-section--left {
        display: none; /* Hide meeting code on small screens */
    }
    .bar-section--center {
        gap: 6px;
    }
    .ctrl-btn {
        width: 40px;
        height: 40px;
    }
    .ctrl-btn--hangup {
        width: 50px;
    }
}

@media (max-width: 500px) {
    .app-bottom-bar {
        padding: 0 8px;
        height: 68px;
    }
    .bar-section--center {
        gap: 4px;
    }
    .ctrl-btn {
        width: 36px;
        height: 36px;
    }
    .ctrl-btn--hangup {
        width: 46px;
    }
    .reaction-split-wrap {
        height: 36px;
    }
    .reaction-quick-btn {
        height: 32px;
        padding: 0 8px;
    }
    .reaction-picker-trigger {
        width: 24px;
        height: 32px;
    }
    .quick-emoji {
        font-size: 16px;
    }
}

@media (max-width: 400px) {
    .bar-section--center {
        gap: 2px;
    }
    .ctrl-btn {
        width: 34px;
        height: 34px;
    }
    .ctrl-btn--hangup {
        width: 42px;
    }
    .reaction-split-wrap {
        padding: 1px;
    }
}

.collapse-btn:hover {
    opacity: 1;
}

.gmeet-controls--collapsed .controls-center {
    padding: 8px 12px;
}

/* Hangup Popup Menu */
.modern-hangup-menu {
    position: absolute;
    bottom: calc(100% + 12px);
    right: 0;
    background: var(--surface-elevated);
    border: 1px solid var(--border-default);
    border-radius: 12px;
    padding: 8px 0;
    min-width: 220px;
    box-shadow: 0 16px 36px rgba(15, 23, 42, 0.3);
    z-index: 1000; /* Ensure it stays above everything */
}

@media (max-width: 600px) {
    .modern-hangup-menu {
        position: fixed;
        bottom: 80px;
        left: 50%;
        transform: translateX(-50%);
        right: auto;
        min-width: calc(100vw - 40px);
        margin: 0 20px;
        z-index: 10000;
    }
}
.menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 10px 16px;
    border: none;
    background: transparent;
    color: var(--toolbox-text);
    font-size: 14px;
    cursor: pointer;
    transition: background-color 0.15s;
    text-align: left;
}
.menu-item:hover {
    background: var(--toolbox-chip-bg-hover);
}
.menu-item--danger {
    color: #ef4444;
}
.menu-item--danger:hover {
    background: rgba(239, 68, 68, 0.18);
}

/* Reaction Split Button */
.reaction-quick-btn {
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
    padding-right: 8px !important;
    min-width: 40px;
}
.reaction-chevron-btn {
    border-top-left-radius: 0 !important;
    border-bottom-left-radius: 0 !important;
    padding-left: 4px !important;
    padding-right: 6px !important;
    min-width: 24px;
    width: 24px;
    border-left: 1px solid rgba(255, 255, 255, 0.1);
}

/* Recording Indicator in Top Bar */
.recording-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.recording-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #ea4335;
    animation: pulse-dot 1.5s ease-in-out infinite;
}
.recording-label {
    font-size: 12px;
    font-weight: 600;
    color: #ea4335;
    letter-spacing: 0.05em;
}
@keyframes pulse-dot {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.3;
    }
}

/* Recording Button */
.ctrl-btn--recording {
    background: linear-gradient(145deg, #b91c1c, #7f1d1d) !important;
    color: #fff7ed !important;
    border-color: rgba(239, 68, 68, 0.5) !important;
    box-shadow: 0 10px 20px rgba(127, 29, 29, 0.32);
}

/* Lock Button & Indicator */
.locked-status-badge {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: rgba(242, 139, 130, 0.15);
    color: #f28b82;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 8px;
    border: 1px solid rgba(242, 139, 130, 0.2);
}

.lock-btn-wrap {
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.ctrl-btn--lock-active {
    background: linear-gradient(145deg, #b45309, #92400e) !important;
    color: #fff7ed !important;
    border-color: rgba(217, 119, 6, 0.52) !important;
    transform: scale(1.1) rotate(5deg);
    box-shadow: 0 12px 22px rgba(146, 64, 14, 0.3);
}

.icon-morph-enter-active,
.icon-morph-leave-active {
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.icon-morph-enter-from {
    transform: scale(0) rotate(-180deg);
    opacity: 0;
}
.icon-morph-leave-to {
    transform: scale(0) rotate(180deg);
    opacity: 0;
}

.morph-icon {
    transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}

/* Split Reaction Button */
.reaction-split-wrap {
    display: flex;
    align-items: center;
    background: var(--ctrl-accent-violet);
    border-radius: 24px;
    padding: 2px;
    height: 44px;
    border: 1px solid var(--ctrl-accent-violet-border);
    transition: all 0.2s ease;
    box-shadow: 0 8px 18px rgba(51, 65, 85, 0.2);
}

.reaction-split-wrap:hover {
    border-color: rgba(100, 116, 139, 0.5);
    box-shadow: 0 10px 20px rgba(51, 65, 85, 0.24);
}

.reaction-quick-btn {
    height: 40px;
    padding: 0 12px;
    display: flex;
    align-items: center;
    border-radius: 20px 0 0 20px;
    background: rgba(255, 255, 255, 0.18);
    border: none;
    cursor: pointer;
    transition: background 0.15s;
}

.reaction-quick-btn:hover {
    background: rgba(255, 255, 255, 0.28);
}

.reaction-picker-trigger {
    width: 28px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0 20px 20px 0;
    background: rgba(255, 255, 255, 0.08);
    border: none;
    cursor: pointer;
    color: var(--ctrl-accent-violet-fg);
    transition: all 0.15s;
    border-left: 1px solid rgba(100, 116, 139, 0.34);
}

.reaction-picker-trigger:hover {
    background: rgba(255, 255, 255, 0.2);
    color: var(--ctrl-accent-violet-fg);
}

.reaction-picker-trigger.picker-open {
    color: #ffffff;
    background: linear-gradient(145deg, #334155, #475569);
}

.quick-emoji {
    font-size: 18px;
    line-height: 1;
}

/* Activities Menu — same styling as .layout-menu in MeetingLayoutSelector */
.layout-menu {
    position: absolute;
    bottom: calc(100% + 12px);
    right: 0;
    width: 280px;
    background: var(--toolbox-bg);
    backdrop-filter: blur(20px) saturate(125%);
    border: 1px solid var(--toolbox-border);
    border-radius: 16px;
    overflow: hidden;
    z-index: 1000;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.24);
}

.layout-menu-header {
    padding: 16px;
    font-size: 14px;
    font-weight: 500;
    color: var(--toolbox-text);
    border-bottom: 1px solid var(--toolbox-divider);
}

.layout-options {
    padding: 8px;
}

.layout-option {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 12px;
    border: none;
    background: transparent;
    cursor: pointer;
    transition: background 0.15s;
    text-align: left;
    color: var(--toolbox-text);
}

.layout-option:hover {
    background: var(--toolbox-chip-bg-hover);
}

.layout-option--active {
    background: var(--ctrl-accent-violet);
    color: var(--ctrl-accent-violet-fg);
}

.layout-option-icon {
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: var(--toolbox-chip-bg);
}

.layout-option--active .layout-option-icon {
    background: rgba(255, 255, 255, 0.24);
    color: inherit;
}

.layout-option-info {
    flex: 1;
    min-width: 0;
}

.layout-option-label {
    font-size: 14px;
    font-weight: 500;
    color: inherit;
}

.layout-option-desc {
    font-size: 11px;
    color: var(--toolbox-subtext);
    margin-top: 1px;
}

.layout-option-check {
    flex-shrink: 0;
    color: var(--ctrl-accent-violet-fg);
}

.menu-action-item {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 12px 16px;
    font-size: 14px;
    border: none;
    background: transparent;
    cursor: pointer;
    text-align: left;
    transition: background 0.15s;
    color: var(--toolbox-text);
}

.toolbox-menu button:not([class*="text-red"]) {
    color: var(--toolbox-text);
}

.toolbox-menu button:not([class*="bg-red"]):hover {
    background: var(--toolbox-chip-bg-hover);
}

.toolbox-menu .h-px {
    background: var(--toolbox-divider) !important;
}

.toolbox-menu [class*="text-white"] {
    color: var(--toolbox-text) !important;
}

.toolbox-menu [class*="text-white/40"],
.toolbox-menu [class*="text-white/50"],
.toolbox-menu [class*="text-white/60"],
.toolbox-menu [class*="text-white/70"] {
    color: var(--toolbox-subtext) !important;
}

.toolbox-menu [class*="bg-white/5"],
.toolbox-menu [class*="bg-surface-secondary"] {
    background: var(--toolbox-chip-bg) !important;
}

.toolbox-menu [class*="group-hover:bg-white/10"],
.toolbox-menu [class*="group-hover:bg-surface-tertiary"] {
    background: var(--toolbox-chip-bg-hover) !important;
}

/* Blended Badge - Premium Glass Style */
.badge-count {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #ea4335;
    color: white;
    font-size: 10px;
    font-weight: 700;
    width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    border: 2px solid var(--surface-primary);
    z-index: 10;
}

.badge-count--secondary {
    background: var(--ctrl-accent-blue) !important;
    backdrop-filter: blur(8px);
    border: 1px solid var(--ctrl-accent-blue-border) !important;
    color: var(--ctrl-accent-blue-fg) !important;
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.24);
}

.pop-enter-active {
    animation: pop-in 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes pop-in {
    0% {
        transform: scale(0);
        opacity: 0;
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}

/* Bounce & Pulse Animations */
.ctrl-btn--active-bounce {
    background: linear-gradient(145deg, #f59e0b, #f97316) !important;
    color: #fff7ed !important;
    border-color: rgba(251, 191, 36, 0.68) !important;
    animation: bounce 0.5s cubic-bezier(0.36, 0, 0.66, -0.56);
}

.recording-badge {
    animation: pulse-soft 2s infinite ease-in-out;
}

@keyframes bounce {
    0%,
    100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.25);
    }
    70% {
        transform: scale(0.9);
    }
}

@keyframes pulse-soft {
    0%,
    100% {
        opacity: 1;
        transform: scale(1);
    }
    50% {
        opacity: 0.8;
        transform: scale(0.98);
    }
}

/* Side Panel Transitions */
.panel-enter-active,
.panel-leave-active {
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
.panel-enter-from,
.panel-leave-to {
    opacity: 0;
    transform: translateX(30px);
}

.details-panel.panel-enter-from,
.details-panel.panel-leave-to {
    transform: translateY(30px);
}

/* Solo / Empty Meeting State */
.solo-empty-state {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    width: 100%;
    overflow: hidden;
    background:
        radial-gradient(
            900px circle at 16% 10%,
            rgba(59, 130, 246, 0.12),
            transparent 58%
        ),
        radial-gradient(
            850px circle at 82% 86%,
            rgba(139, 92, 246, 0.1),
            transparent 60%
        ),
        var(--surface-primary);
}

.dark .solo-empty-state {
    background:
        radial-gradient(
            900px circle at 16% 10%,
            rgba(59, 130, 246, 0.22),
            transparent 58%
        ),
        radial-gradient(
            850px circle at 82% 86%,
            rgba(139, 92, 246, 0.18),
            transparent 60%
        ),
        var(--surface-primary);
}

.ambient-background {
    position: absolute;
    inset: 0;
    overflow: hidden;
    z-index: 0;
    opacity: 0.42;
}

.dark .ambient-background {
    opacity: 0.62;
}

.blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(90px);
    opacity: 0.38;
    animation: floatBlob 20s infinite ease-in-out alternate;
    will-change: transform;
}

.dark .blob {
    opacity: 0.52;
}

.blob-1 {
    width: 500px;
    height: 500px;
    background: #1a73e8;
    top: -100px;
    left: -100px;
    animation-delay: 0s;
}

.blob-2 {
    width: 600px;
    height: 600px;
    background: #8ab4f8;
    bottom: -150px;
    right: -100px;
    animation-delay: -5s;
    animation-duration: 25s;
}

.blob-3 {
    width: 400px;
    height: 400px;
    background: #9333ea;
    bottom: 20%;
    left: 20%;
    animation-delay: -10s;
    animation-duration: 22s;
}

@keyframes floatBlob {
    0% {
        transform: translate(0, 0) scale(1);
    }
    33% {
        transform: translate(50px, -50px) scale(1.1);
    }
    66% {
        transform: translate(-30px, 40px) scale(0.9);
    }
    100% {
        transform: translate(0, 0) scale(1);
    }
}

.solo-content {
    position: relative;
    z-index: 10;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 64px 96px;
    background: rgba(255, 255, 255, 0.68);
    border: 1px solid rgba(255, 255, 255, 0.72);
    border-radius: 24px;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.14);
    backdrop-filter: blur(14px) saturate(110%);
    -webkit-backdrop-filter: blur(14px) saturate(110%);
    animation: soloFadeIn 1s cubic-bezier(0.16, 1, 0.3, 1);
    text-align: center;
}

.dark .solo-content {
    background: rgba(17, 20, 28, 0.56);
    border: 1px solid rgba(255, 255, 255, 0.14);
    box-shadow: 0 24px 56px rgba(0, 0, 0, 0.45);
}

@media (max-width: 768px) {
    .solo-content {
        border-radius: 16px;
        border: 1px solid rgba(255, 255, 255, 0.66);
        padding: 48px 24px;
        box-shadow: 0 16px 36px rgba(15, 23, 42, 0.14);
        width: 100%;
        max-width: calc(100% - 24px);
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(12px) saturate(105%);
        -webkit-backdrop-filter: blur(12px) saturate(105%);
    }

    .dark .solo-content {
        border-color: rgba(255, 255, 255, 0.16);
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.42);
        background: rgba(17, 20, 28, 0.6);
    }
}

@keyframes soloFadeIn {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.solo-avatar {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1a73e8, #8ab4f8);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 56px;
    font-weight: 500;
    color: #ffffff;
    margin-bottom: 32px;
    position: relative;
    box-shadow: 0 12px 36px rgba(26, 115, 232, 0.4);
}
.solo-avatar::after {
    content: "";
    position: absolute;
    inset: -12px;
    border-radius: 50%;
    border: 2px solid #8ab4f8;
    opacity: 0.5;
    animation: soloPulse 2.5s infinite cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes soloPulse {
    0% {
        transform: scale(1);
        opacity: 0.6;
    }
    100% {
        transform: scale(1.4);
        opacity: 0;
    }
}

.solo-name {
    font-size: 28px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
    letter-spacing: -0.5px;
    text-shadow: 0 1px 2px rgba(255, 255, 255, 0.35);
}
.solo-hint {
    font-size: 18px;
    color: var(--text-secondary);
    margin: 12px 0 0 0;
    font-weight: 400;
}
.solo-hint-sub {
    font-size: 15px;
    color: var(--text-muted);
    margin: 8px 0 0 0;
}

.dark .solo-name {
    text-shadow: 0 1px 8px rgba(0, 0, 0, 0.45);
}

/* ─── Waiting Room Overlay ─────────────────────────────────────────────────── */
.waiting-overlay {
    position: fixed;
    inset: 0;
    z-index: 100;
    background:
        radial-gradient(
            75% 65% at 22% 20%,
            rgba(99, 102, 241, 0.22),
            transparent 72%
        ),
        radial-gradient(
            65% 60% at 78% 85%,
            rgba(14, 165, 233, 0.2),
            transparent 72%
        ),
        rgba(245, 247, 252, 0.76);
    backdrop-filter: blur(24px) saturate(120%);
    -webkit-backdrop-filter: blur(24px) saturate(120%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    animation: waitingFadeIn 0.5s ease-out;
}

.dark .waiting-overlay {
    background:
        radial-gradient(
            72% 62% at 20% 18%,
            rgba(79, 70, 229, 0.24),
            transparent 72%
        ),
        radial-gradient(
            62% 58% at 82% 82%,
            rgba(14, 165, 233, 0.2),
            transparent 72%
        ),
        rgba(10, 15, 26, 0.8);
}

@keyframes waitingFadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.waiting-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    max-width: 460px;
    width: min(460px, 100%);
    padding: 32px 24px;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.84);
    border: 1px solid rgba(148, 163, 184, 0.45);
    box-shadow: 0 26px 56px rgba(15, 23, 42, 0.18);
}

.dark .waiting-content {
    background: rgba(10, 15, 26, 0.74);
    border-color: rgba(148, 163, 184, 0.24);
    box-shadow: 0 30px 60px rgba(0, 0, 0, 0.46);
}

.waiting-icon-wrap {
    position: relative;
    margin-bottom: 32px;
}
.waiting-icon-circle {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    background: rgba(99, 102, 241, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    animation: pulse 2s ease-in-out infinite;
}

.dark .waiting-icon-circle {
    background: rgba(99, 102, 241, 0.2);
}
@keyframes pulse {
    0%,
    100% {
        transform: scale(1);
        opacity: 1;
    }
    50% {
        transform: scale(1.05);
        opacity: 0.8;
    }
}

.waiting-ping {
    position: absolute;
    bottom: -4px;
    right: -4px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.92);
    display: flex;
    align-items: center;
    justify-content: center;
}

.dark .waiting-ping {
    background: rgba(15, 23, 42, 0.92);
}
.ping-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #8ab4f8;
    animation: ping 1.5s ease-in-out infinite;
}

@keyframes ping {
    0%,
    100% {
        opacity: 1;
        transform: scale(1);
    }
    50% {
        opacity: 0.6;
        transform: scale(1.3);
    }
}

.waiting-title {
    font-size: 24px;
    font-weight: 600;
    color: #0f172a;
    margin: 0 0 8px;
}
.waiting-desc {
    font-size: 15px;
    color: #334155;
    margin: 0;
    line-height: 1.5;
}

.dark .waiting-title {
    color: #f8fafc;
}

.dark .waiting-desc {
    color: #cbd5e1;
}

.waiting-meta {
    margin-top: 40px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
}

.waiting-host-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: rgba(226, 232, 240, 0.85);
    border-radius: 20px;
    font-size: 13px;
    color: #1e293b;
    border: 1px solid rgba(148, 163, 184, 0.45);
}
.waiting-host-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
}

.waiting-cancel-btn {
    background: rgba(255, 255, 255, 0.78);
    border: 1px solid rgba(148, 163, 184, 0.62);
    color: #1d4ed8;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border-radius: 999px;
    padding: 10px 18px;
    transition:
        border-color 0.2s ease,
        background-color 0.2s ease,
        color 0.2s ease;
}
.waiting-cancel-btn:hover {
    color: #1e40af;
    background: rgba(255, 255, 255, 0.95);
    border-color: rgba(59, 130, 246, 0.46);
}

.dark .waiting-host-badge {
    background: rgba(30, 41, 59, 0.78);
    color: #e2e8f0;
    border-color: rgba(148, 163, 184, 0.28);
}

.dark .waiting-cancel-btn {
    background: rgba(30, 41, 59, 0.78);
    border-color: rgba(148, 163, 184, 0.4);
    color: #bfdbfe;
}

.dark .waiting-cancel-btn:hover {
    color: #dbeafe;
    background: rgba(51, 65, 85, 0.88);
    border-color: rgba(96, 165, 250, 0.52);
}

/* ─── Responsive ───────────────────────────────────────────────────────────── */
/* ─── Mobile Responsiveness ─────────────────────────────────────────── */
@media (max-width: 1024px) {
    .meeting-info-pill {
        padding: 0 8px;
    }
    .info-code {
        display: none;
    }
}

@media (max-width: 768px) {
    /* Seamless stage: remove padding and border-radius on mobile */
    .gmeet-stage {
        padding: 0 !important;
        gap: 0 !important;
    }

    .grid-container {
        padding: 0;
        gap: 0;
    }

    .solo-empty-state {
        border-radius: 0 !important;
    }

    /* remove tile border-radius on mobile */
    .participant-tile,
    .video-tile,
    .solo-camera-wrapper {
        border-radius: 0 !important;
    }

    .app-bottom-bar {
        padding: 0 12px;
        height: 72px;
    }

    .meeting-info-pill {
        display: none;
    }

    .bar-section {
        gap: 6px;
    }

    .ctrl-btn {
        width: 40px;
        height: 40px;
    }

    .ctrl-btn--hangup {
        width: 48px;
    }

    .reaction-split-wrap {
        height: 40px;
    }

    .reaction-quick-btn {
        height: 36px;
        padding: 0 8px;
    }

    .reaction-picker-trigger {
        width: 24px;
        height: 36px;
    }

    .activities-menu-items {
        width: 180px;
    }

    .side-panel {
        width: 100%;
        position: absolute;
        right: 0;
        top: 0;
        bottom: 0;
        border-radius: 0;
        z-index: 1001;
        background: var(--surface-primary);
    }

    .more-menu-wrapper {
        position: relative;
        z-index: 1200;
    }

    .modern-more-menu {
        z-index: 1201 !important;
        right: 0 !important;
        width: min(280px, calc(100vw - 20px)) !important;
    }

    .gmeet-root,
    .app-bottom-bar,
    .meeting-info-pill,
    .reaction-group {
        border-radius: 0 !important;
    }

    .ctrl-btn--hangup {
        border-radius: 9999px !important;
    }

    .meeting-details-overlay {
        bottom: 16px;
        left: 12px;
        right: 12px;
        z-index: 200;
    }

    .details-panel {
        width: 100%;
    }

    .pip-self-view {
        width: 130px;
        bottom: 84px; /* Above control bar */
        right: 12px;
    }
}

@media (max-width: 480px) {
    .app-bottom-bar {
        height: 64px;
        padding: 0 8px;
    }

    .bar-section--center {
        gap: 4px;
        flex: 2;
    }

    .ctrl-btn {
        width: 36px;
        height: 36px;
    }

    .ctrl-btn--hangup {
        width: 44px;
    }

    .reaction-split-wrap {
        height: 36px;
    }

    .reaction-quick-btn {
        height: 32px;
        padding: 0 6px;
    }

    .reaction-picker-trigger {
        width: 20px;
        height: 32px;
    }

    .bar-section--right {
        gap: 4px;
        flex: 1;
        justify-content: flex-end;
    }

    /* Hide less critical items on very small screens to avoid overlap */
    .bar-section--right .MeetingLayoutSelector {
        display: none;
    }

    .modern-more-menu {
        right: 8px !important;
        width: min(260px, calc(100vw - 16px)) !important;
    }
}
.initializing-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: var(--surface-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.initializing-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1.5rem;
}

.loading-ring {
    width: 64px;
    height: 64px;
    border: 4px solid var(--surface-tertiary);
    border-top-color: var(--indigo-500);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes ctrl-btn-spin {
    to {
        transform: rotate(360deg);
    }
}

.initializing-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.initializing-subtitle {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin: 0;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

/* ─── Poor Connection Banner ─────────────────────────────────────────── */
.poor-connection-banner {
    position: absolute;
    top: 16px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 100;
    pointer-events: none;
    width: auto;
    max-width: 90%;
    margin-top: 16px;
}

.poor-connection-content {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 16px;
    background: rgba(220, 38, 38, 0.15);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(220, 38, 38, 0.3);
    border-radius: 12px;
    color: white;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    pointer-events: auto;
}

.poor-connection-text {
    font-size: 13px;
}

@media (max-width: 640px) {
    .poor-connection-content {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
        padding: 12px;
    }

    .poor-connection-text {
        white-space: normal;
        line-height: 1.4;
    }

    .poor-connection-banner {
        width: calc(100% - 32px);
        max-width: none;
    }
}

.poor-connection-action {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    color: white;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.poor-connection-action:hover {
    background: rgba(255, 255, 255, 0.25);
    border-color: rgba(255, 255, 255, 0.4);
}

.poor-connection-action:active {
    transform: scale(0.95);
}

.modern-more-menu {
    position: absolute;
    bottom: calc(100% + 12px);
    right: 0;
    width: 280px;
    background: var(--toolbox-bg);
    backdrop-filter: blur(20px) saturate(125%);
    -webkit-backdrop-filter: blur(20px) saturate(125%);
    border: 1px solid var(--toolbox-border);
    border-radius: 16px;
    overflow: hidden;
    z-index: 1000;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.24);
}

.more-menu-wrapper {
    display: none;
    align-items: center;
}

@media (max-width: 1023px) {
    .more-menu-wrapper {
        display: flex;
    }
}

.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(100, 116, 139, 0.35);
    border-radius: 2px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: rgba(100, 116, 139, 0.5);
}
</style>
