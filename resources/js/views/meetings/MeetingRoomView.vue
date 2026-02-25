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
                    <h1 class="waiting-title">You're in the Waiting Room</h1>
                    <p class="waiting-desc">
                        The meeting host has been notified. They'll let you in
                        shortly.
                    </p>
                    <div class="waiting-meta">
                        <div class="waiting-host-badge">
                            <img
                                v-if="meetingStore.meeting?.host?.avatar_url"
                                :src="meetingStore.meeting.host.avatar_url"
                                class="waiting-host-avatar"
                            />
                            <span>Host: {{ meetingHostName }}</span>
                        </div>
                        <button
                            @click="router.push({ name: 'home' })"
                            class="waiting-cancel-btn"
                        >
                            Cancel and return home
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Top Bar -->
        <header class="gmeet-topbar">
            <div class="topbar-left">
                <span class="topbar-title">{{ meetingTitle }}</span>
                <span
                    v-if="meetingStore.isLocked"
                    class="ml-3 text-[#f28b82] flex items-center"
                    title="This meeting is locked"
                >
                    <Icon name="lock" size="16" />
                </span>
                <span
                    v-if="isRecording"
                    class="recording-indicator ml-3"
                    title="Recording in progress"
                >
                    <span class="recording-dot"></span>
                    <span class="recording-label">REC</span>
                </span>
            </div>
            <div class="topbar-center">
                <span class="topbar-clock">{{ currentTime }}</span>
            </div>
            <div class="topbar-right">
                <button
                    v-if="
                        meetingStore.isHost &&
                        meetingStore.waitingParticipants.length > 0
                    "
                    class="topbar-btn topbar-btn--alert"
                    @click="showAdmissionPanel = !showAdmissionPanel"
                    title="Waiting Room"
                >
                    <Icon name="user-plus" size="18" />
                    <span class="topbar-badge">{{
                        meetingStore.waitingParticipants.length
                    }}</span>
                </button>
                <button
                    class="topbar-btn"
                    :class="{ 'topbar-btn--active': showParticipantsPanel }"
                    @click="toggleParticipantsPanel"
                    title="Participants"
                >
                    <Icon name="users" size="18" />
                    <span class="topbar-count">{{ participantCount }}</span>
                </button>
                <button
                    class="topbar-btn"
                    :class="{ 'topbar-btn--active': showChatPanel }"
                    @click="toggleChatPanel"
                    title="Chat"
                >
                    <Icon name="message-square" size="18" />
                </button>
                <button
                    class="topbar-btn"
                    :class="{ 'topbar-btn--active': showPollPanel }"
                    @click="togglePollPanel"
                    title="Polls"
                >
                    <Icon name="bar-chart-2" size="18" />
                    <span v-if="meetingStore.activePoll?.is_active" class="topbar-badge topbar-badge--poll">●</span>
                </button>
                <!-- Host-only laser pointer toggle (only shown when screensharing is active) -->
                <button
                    v-if="meetingStore.isHost && meetingStore.screenShares.size > 0"
                    class="topbar-btn"
                    :class="{ 'topbar-btn--active': meetingStore.laserPointerMode !== 'off' }"
                    :title="laserPointerLabel"
                    @click="cycleLaserMode"
                >
                    <Icon name="mouse-pointer-2" size="18" />
                    <span v-if="meetingStore.laserPointerMode === 'global'" class="topbar-badge topbar-badge--laser">ALL</span>
                    <span v-if="meetingStore.laserPointerMode === 'targeted'" class="topbar-badge topbar-badge--laser">1</span>
                </button>
            </div>
        </header>

        <!-- Main Content Area -->
        <div
            class="gmeet-body"
            :class="{
                'has-panel':
                    showParticipantsPanel ||
                    showChatPanel ||
                    showPollPanel ||
                    showAdmissionPanel,
            }"
        >
            <div class="gmeet-stage" style="position: relative;">
                <!-- Laser Pointer Overlay: transparent, sits above video grid -->

                <template v-if="isSpotlightMode && spotlightTile">
                    <div class="spotlight-main">
                        <ParticipantTile
                            :participant="spotlightTile.participant"
                            :is-spotlight="true"
                            :is-screen-share="spotlightTile.isScreen"
                            :local-camera-on="isCameraOn"
                            :local-mic-on="isMicOn"
                            :local-stream-override="screenStream"
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
                                    :local-stream-override="screenStream"
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
                                :local-stream-override="screenStream"
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
                            />
                        </div>
                        <div v-else class="solo-content glass-panel">
                            <div class="solo-avatar-wrap">
                                <div class="solo-avatar">
                                    {{
                                        getParticipantInitial(
                                            meetingStore.localParticipant,
                                        )
                                    }}
                                </div>
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
                    <div v-if="localCameraTile" class="pip-self-view">
                        <ParticipantTile
                            :participant="localCameraTile.participant"
                            :is-screen-share="false"
                            :local-camera-on="isCameraOn"
                            :local-mic-on="isMicOn"
                            :is-local="true"
                        />
                    </div>
                </Transition>
            </div>

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
                        <h3>People ({{ participantCount }})</h3>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="
                                    meetingStore.isHost &&
                                    meetingStore.raisedHands.size > 0
                                "
                                @click="lowerAllHands"
                                class="text-xs px-3 py-1.5 bg-[#3c4043] hover:bg-[#4c4d50] text-[#e8eaed] rounded-full transition-colors border-none cursor-pointer whitespace-nowrap"
                                title="Lower all raised hands"
                            >
                                ✋ Lower all
                            </button>
                            <button
                                @click="showParticipantsPanel = false"
                                class="side-panel-close"
                            >
                                <Icon name="x" size="18" />
                            </button>
                        </div>
                    </div>
                    <div class="side-panel-body">
                        <div
                            v-for="p in meetingStore.allParticipants"
                            :key="p.public_id"
                            class="participant-row"
                        >
                            <div class="participant-avatar">
                                {{ getParticipantInitial(p) }}
                            </div>
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
                                        class="ml-2 text-xs text-blue-400 bg-blue-400/10 px-1.5 py-0.5 rounded"
                                        >Host</span
                                    >
                                    <span
                                        v-if="p.role === 'co-host'"
                                        class="ml-2 text-xs text-purple-400 bg-purple-400/10 px-1.5 py-0.5 rounded"
                                        >Co-host</span
                                    >
                                </span>
                            </div>
                            <div
                                class="participant-status-icons flex items-center"
                            >
                                <Icon
                                    v-if="
                                        meetingStore.raisedHands.has(
                                            p.public_id,
                                        )
                                    "
                                    name="hand"
                                    size="14"
                                    class="text-amber-400"
                                />
                                <Icon
                                    v-if="p.is_muted_by_host"
                                    name="mic-off"
                                    size="14"
                                    class="text-red-500 ml-1"
                                />
                                <Icon
                                    v-if="p.is_camera_disabled_by_host"
                                    name="video-off"
                                    size="14"
                                    class="text-red-500 ml-1"
                                />
                                <!-- Moderators can moderate participants, but only the true Host can promote/demote/kick other moderators/co-hosts. -->
                                <Menu
                                    as="div"
                                    class="relative inline-block text-left ml-2"
                                    v-if="
                                        meetingStore.isModerator &&
                                        p.public_id !==
                                            meetingStore.localParticipant
                                                ?.public_id &&
                                        p.role !== 'host'
                                    "
                                >
                                    <MenuButton
                                        class="p-1 hover:bg-[#3c4043] rounded-full text-[#9aa0a6]"
                                    >
                                        <Icon name="more-vertical" size="16" />
                                    </MenuButton>
                                    <transition
                                        enter-active-class="transition ease-out duration-100"
                                        enter-from-class="transform opacity-0 scale-95"
                                        enter-to-class="transform opacity-100 scale-100"
                                        leave-active-class="transition ease-in duration-75"
                                        leave-from-class="transform opacity-100 scale-100"
                                        leave-to-class="transform opacity-0 scale-95"
                                    >
                                        <MenuItems
                                            class="absolute right-0 mt-1 w-48 origin-top-right bg-[#28292c] rounded-md shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none z-50 py-1 border border-[#3c4043]"
                                        >
                                            <MenuItem v-slot="{ active }">
                                                <div>
                                                    <button
                                                        @click="
                                                            p.is_muted_by_host
                                                                ? meetingStore.unmuteParticipant(
                                                                      p.public_id,
                                                                  )
                                                                : meetingStore.muteParticipant(
                                                                      p.public_id,
                                                                  )
                                                        "
                                                        :class="[
                                                            active
                                                                ? 'bg-[#3c4043] text-white'
                                                                : 'text-[#e8eaed]',
                                                            'group flex items-center w-full px-4 py-2 text-sm',
                                                        ]"
                                                    >
                                                        <Icon
                                                            :name="
                                                                p.is_muted_by_host
                                                                    ? 'mic'
                                                                    : 'mic-off'
                                                            "
                                                            size="16"
                                                            class="mr-3 text-[#9aa0a6]"
                                                        />
                                                        {{
                                                            p.is_muted_by_host
                                                                ? "Allow Unmute"
                                                                : "Mute Microphone"
                                                        }}
                                                    </button>
                                                </div>
                                            </MenuItem>
                                            <MenuItem v-slot="{ active }">
                                                <div>
                                                    <button
                                                        @click="
                                                            p.is_camera_disabled_by_host
                                                                ? meetingStore.allowCamera(
                                                                      p.public_id,
                                                                  )
                                                                : meetingStore.disableCamera(
                                                                      p.public_id,
                                                                  )
                                                        "
                                                        :class="[
                                                            active
                                                                ? 'bg-[#3c4043] text-white'
                                                                : 'text-[#e8eaed]',
                                                            'group flex items-center w-full px-4 py-2 text-sm',
                                                        ]"
                                                    >
                                                        <Icon
                                                            :name="
                                                                p.is_camera_disabled_by_host
                                                                    ? 'video'
                                                                    : 'video-off'
                                                            "
                                                            size="16"
                                                            class="mr-3 text-[#9aa0a6]"
                                                        />
                                                        {{
                                                            p.is_camera_disabled_by_host
                                                                ? "Allow Camera"
                                                                : "Turn Off Camera"
                                                        }}
                                                    </button>
                                                </div>
                                            </MenuItem>

                                            <!-- Only True Host can promote/demote -->
                                            <template
                                                v-if="meetingStore.isHost"
                                            >
                                                <div
                                                    class="my-1 border-t border-[#3c4043]"
                                                ></div>
                                                <MenuItem v-slot="{ active }">
                                                    <div>
                                                        <button
                                                            v-if="
                                                                p.role ===
                                                                'participant'
                                                            "
                                                            @click="
                                                                meetingStore.promoteParticipant(
                                                                    p.public_id,
                                                                )
                                                            "
                                                            :class="[
                                                                active
                                                                    ? 'bg-[#3c4043] text-white'
                                                                    : 'text-[#e8eaed]',
                                                                'group flex items-center w-full px-4 py-2 text-sm',
                                                            ]"
                                                        >
                                                            <Icon
                                                                name="shield"
                                                                size="16"
                                                                class="mr-3 text-[#9aa0a6]"
                                                            />
                                                            Make Co-host
                                                        </button>
                                                        <button
                                                            v-else-if="
                                                                p.role ===
                                                                'co-host'
                                                            "
                                                            @click="
                                                                meetingStore.demoteParticipant(
                                                                    p.public_id,
                                                                )
                                                            "
                                                            :class="[
                                                                active
                                                                    ? 'bg-[#3c4043] text-white'
                                                                    : 'text-[#e8eaed]',
                                                                'group flex items-center w-full px-4 py-2 text-sm',
                                                            ]"
                                                        >
                                                            <Icon
                                                                name="shield-off"
                                                                size="16"
                                                                class="mr-3 text-[#9aa0a6]"
                                                            />
                                                            Remove Co-host
                                                        </button>
                                                    </div>
                                                </MenuItem>
                                            </template>

                                            <div
                                                class="my-1 border-t border-[#3c4043]"
                                            ></div>
                                            <MenuItem v-slot="{ active }">
                                                <div>
                                                    <!-- Co-hosts cannot kick other Co-hosts (enforced by backend, but hidden here for UX) -->
                                                    <button
                                                        v-if="
                                                            meetingStore.isHost ||
                                                            p.role ===
                                                                'participant'
                                                        "
                                                        @click="
                                                            meetingStore.kickParticipant(
                                                                p.public_id,
                                                            )
                                                        "
                                                        :class="[
                                                            active
                                                                ? 'bg-[#3c4043] text-red-500'
                                                                : 'text-red-400',
                                                            'group flex items-center w-full px-4 py-2 text-sm',
                                                        ]"
                                                    >
                                                        <Icon
                                                            name="minus-circle"
                                                            size="16"
                                                            class="mr-3 border-red-500"
                                                        />
                                                        Remove from call
                                                    </button>
                                                </div>
                                            </MenuItem>
                                        </MenuItems>
                                    </transition>
                                </Menu>
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
                        <button @click="showPollPanel = false" class="side-panel-close">
                            <Icon name="x" size="18" />
                        </button>
                    </div>
                    <div class="side-panel-body" style="padding: 0; overflow: hidden;">
                        <MeetingPollPanel />
                    </div>
                </aside>
            </Transition>

            <!-- Admission Side Panel (Moderator Only) -->
            <Transition
                enter-active-class="transition duration-250 ease-out"
                enter-from-class="translate-x-full opacity-0"
                enter-to-class="translate-x-0 opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="translate-x-0 opacity-100"
                leave-to-class="translate-x-full opacity-0"
            >
                <aside
                    v-if="showAdmissionPanel && meetingStore.isModerator"
                    class="side-panel"
                >
                    <div class="side-panel-header">
                        <h3>Waiting Room</h3>
                        <button
                            @click="showAdmissionPanel = false"
                            class="side-panel-close"
                        >
                            <Icon name="x" size="18" />
                        </button>
                    </div>
                    <div class="side-panel-body">
                        <div
                            v-if="meetingStore.waitingParticipants.length === 0"
                            class="panel-empty"
                        >
                            <Icon
                                name="users"
                                size="28"
                                class="text-[#5f6368]"
                            />
                            <p>No one is waiting</p>
                        </div>
                        <div
                            v-for="p in meetingStore.waitingParticipants"
                            :key="p.public_id"
                            class="admission-card"
                        >
                            <div class="admission-info">
                                <div class="participant-avatar">
                                    {{ getParticipantInitial(p) }}
                                </div>
                                <div>
                                    <p class="admission-name">
                                        {{ getParticipantName(p) }}
                                    </p>
                                    <p class="admission-sub">Wants to join</p>
                                </div>
                            </div>
                            <div class="admission-actions">
                                <button
                                    @click="
                                        meetingStore.rejectParticipant(
                                            p.public_id,
                                        )
                                    "
                                    class="admission-btn admission-btn--reject"
                                >
                                    Deny
                                </button>
                                <button
                                    @click="
                                        meetingStore.admitParticipant(
                                            p.public_id,
                                        )
                                    "
                                    class="admission-btn admission-btn--admit"
                                >
                                    Admit
                                </button>
                            </div>
                        </div>
                    </div>
                </aside>
            </Transition>
        </div>

        <!-- Floating Reactions Component -->
        <ReactionOverlay
            :showPicker="showReactionPicker"
            @reaction-sent="showReactionPicker = false"
        />

        <!-- Bottom Control Bar -->
        <footer 
            class="gmeet-controls"
            :class="{ 
                'gmeet-controls--collapsed': isControlsCollapsed,
                'gmeet-controls--dragging': isDraggingControls
            }"
        >
            <div class="controls-center" ref="controlsRef" :style="controlsStyle">
                <!-- Drag Grip -->
                <div 
                    class="drag-grip"
                    @mousedown="startDragControls"
                    @touchstart="startDragControls"
                >
                    <Icon name="grip-vertical" size="18" />
                </div>

                <template v-if="!isControlsCollapsed">
                    <button
                        class="ctrl-btn"
                        :class="{ 'ctrl-btn--off': !isMicOn }"
                        @click="toggleMic"
                        :title="micToggleTitle"
                    >
                        <Icon :name="isMicOn ? 'mic' : 'mic-off'" size="22" />
                    </button>
                    <button
                        class="ctrl-btn"
                        :class="{ 'ctrl-btn--off': !isCameraOn }"
                        @click="toggleCamera"
                        :title="cameraToggleTitle"
                    >
                        <Icon
                            :name="isCameraOn ? 'video' : 'video-off'"
                            size="22"
                        />
                    </button>
                    <button
                        class="ctrl-btn"
                        :class="{ 'ctrl-btn--active': isScreenSharing }"
                        @click="toggleScreenShare"
                        :title="screenShareToggleTitle"
                    >
                        <Icon name="monitor" size="22" />
                    </button>
                    <!-- Annotation Toggle (Only for local presenter) -->
                    <button
                        v-if="isScreenSharing"
                        class="ctrl-btn"
                        :class="{ 'ctrl-btn--active': meetingStore.isAnnotating }"
                        @click="meetingStore.isAnnotating = !meetingStore.isAnnotating"
                        title="Annotate Screen"
                    >
                        <Icon name="pen-tool" size="22" />
                    </button>
                    <div class="relative flex items-center">
                        <button
                            class="ctrl-btn reaction-quick-btn"
                            @click="sendQuickReaction"
                            :title="`Send ${lastReactionEmoji}`"
                        >
                            <span class="text-lg">{{ lastReactionEmoji }}</span>
                        </button>
                        <button
                            class="ctrl-btn reaction-chevron-btn"
                            :class="{ 'ctrl-btn--active': showReactionPicker }"
                            @click="showReactionPicker = !showReactionPicker"
                            title="Change reaction"
                        >
                            <Icon name="chevron-up" size="14" />
                        </button>
                    </div>
                    <button
                        class="ctrl-btn"
                        :class="{ 'ctrl-btn--active': isHandRaised }"
                        @click="meetingStore.toggleHand()"
                        title="Raise Hand"
                    >
                        <Icon name="hand" size="22" />
                    </button>
                    <button
                        v-if="meetingStore.isHost"
                        class="ctrl-btn ctrl-btn--lock"
                        :class="{ 'ctrl-btn--lock-active': meetingStore.isLocked }"
                        @click="meetingStore.toggleLock()"
                        :title="
                            meetingStore.isLocked
                                ? 'Unlock Meeting'
                                : 'Lock Meeting'
                        "
                    >
                        <Icon
                            :name="meetingStore.isLocked ? 'lock' : 'unlock'"
                            size="22"
                        />
                    </button>
                    <button
                        class="ctrl-btn"
                        @click="showSettings = true"
                        title="Settings"
                    >
                        <Icon name="settings" size="22" />
                    </button>
                    <button
                        v-if="meetingStore.isHost"
                        class="ctrl-btn"
                        :class="{ 'ctrl-btn--recording': isRecording }"
                        @click="toggleRecording"
                        :title="isRecording ? 'Stop Recording' : 'Start Recording'"
                    >
                        <Icon
                            :name="isRecording ? 'circle-stop' : 'circle-dot'"
                            size="22"
                        />
                    </button>
                    <div class="relative">
                        <button
                            class="ctrl-btn ctrl-btn--hangup"
                            @click="showHangupMenu = !showHangupMenu"
                            title="Leave or End"
                        >
                            <Icon name="phone-off" size="22" />
                        </button>
                        <!-- Hangup Popup Menu -->
                        <div v-if="showHangupMenu" class="hangup-menu">
                            <button class="hangup-menu-item" @click="leaveMeeting">
                                <Icon name="log-out" size="18" />
                                <span>Leave meeting</span>
                            </button>
                            <button
                                v-if="meetingStore.isHost"
                                class="hangup-menu-item hangup-menu-item--end"
                                @click="endMeetingForAll"
                            >
                                <Icon name="phone-off" size="18" />
                                <span>End meeting for all</span>
                            </button>
                        </div>
                    </div>
                </template>

                <!-- Toggle Collapse -->
                <button class="ctrl-btn collapse-btn" @click="isControlsCollapsed = !isControlsCollapsed" :title="isControlsCollapsed ? 'Expand' : 'Collapse'">
                    <Icon :name="isControlsCollapsed ? 'chevron-up' : 'chevron-down'" size="20" />
                </button>
            </div>
        </footer>

        <!-- Modals and Dev Tools -->
        <DeviceSettingsModal
            v-model:open="showSettings"
            @close="showSettings = false"
        />
        <DevSimulationTool v-if="isDevMode" v-model:show="showDevTool" />
    </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { meetingService } from "@/services/meeting.service";
import { useMeetingStore } from "@/stores/meeting";
import { useVideoCallStore } from "@/stores/videocall";
import { useBackgroundBlur } from "@/composables/useBackgroundBlur";
import DeviceSettingsModal from "./components/DeviceSettingsModal.vue";
import DevSimulationTool from "./components/DevSimulationTool.vue";
import ParticipantTile from "./components/ParticipantTile.vue";
import MeetingChatPanel from "./components/MeetingChatPanel.vue";
import MeetingPollPanel from "./components/MeetingPollPanel.vue";

import ReactionOverlay from "./components/ReactionOverlay.vue";
import { Icon } from "@/components/ui";
import { toast } from "vue-sonner";
import api from "@/lib/api";
import { Menu, MenuButton, MenuItems, MenuItem } from "@headlessui/vue";

const route = useRoute();
const router = useRouter();
const meetingStore = useMeetingStore();
const videoCallStore = useVideoCallStore();

const meetingId = route.params.id as string;
const participantId = route.query.participant as string;

const isCameraOn = ref(false);
const isMicOn = ref(false);
const backgroundBlur = useBackgroundBlur();
const showSettings = ref(false);
const showAdmissionPanel = ref(false);
const showParticipantsPanel = ref(false);
const showChatPanel = ref(false);
const showPollPanel = ref(false);
const showReactionPicker = ref(false);
const showDevTool = ref(false);

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

// Laser Pointer
const laserPointerLabel = computed(() => {
    const mode = meetingStore.laserPointerMode;
    if (mode === 'off') return 'Enable Laser Pointer (everyone)';
    if (mode === 'global') return 'Laser Pointer ON (everyone) — click to turn off';
    return 'Laser Pointer targeted — click to turn off';
});

async function cycleLaserMode() {
    if (!meetingStore.meeting) return;
    const next = meetingStore.laserPointerMode === 'off' ? 'global' : 'off';
    meetingStore.laserPointerMode = next;
    try {
        await api.patch(`/api/meetings/${meetingStore.meeting.public_id}/settings`, {
            settings: { laser_pointer_mode: next },
        });
        // Sync to all participants
        meetingStore.sendSignal('laser-mode-changed', { mode: next });
    } catch {
        // Revert on failure
        meetingStore.laserPointerMode = next === 'global' ? 'off' : 'global';
    }
}

// Live clock
const currentTime = ref("");
let clockInterval: number;

function updateClock() {
    currentTime.value = new Date().toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
    });
}

const isWaiting = computed(
    () => meetingStore.localParticipant?.status === "waiting",
);

const meetingTitle = computed(() => meetingStore.meeting?.title || "Meeting");
const participantCount = computed(() => meetingStore.allParticipants.length);
const micToggleTitle = computed(() =>
    isMicOn.value ? "Mute (Ctrl+D)" : "Unmute (Ctrl+D)",
);
const cameraToggleTitle = computed(() =>
    isCameraOn.value ? "Turn off camera (Ctrl+E)" : "Turn on camera (Ctrl+E)",
);
const screenShareToggleTitle = computed(() =>
    isScreenSharing.value ? "Stop sharing" : "Share Screen",
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

function getParticipantInitial(p: any) {
    if (!p) return "Y";
    const name = p.user?.name || p.metadata?.guest_name || "G";
    return name[0].toUpperCase();
}

function getParticipantName(p: any) {
    if (!p) return "You";
    return p.user?.name || p.metadata?.guest_name || "Guest";
}

// ─── Computed / Layout ──────────────────────────────────────────────────────

const GRID_PAGE_SIZE = 12;
const FILMSTRIP_PAGE_SIZE = 6;

const gridPage = ref(0);
const filmstripPage = ref(0);

const localCameraTile = computed(() => {
    if (!meetingStore.localParticipant) return null;
    return {
        id: meetingStore.localParticipant.public_id,
        participant: meetingStore.localParticipant,
        isScreen: false,
    };
});

// Map distinct tiles so a single participant can have both Camera and Screen shown at once
const renderedTiles = computed(() => {
    const tiles: { id: string; participant: any; isScreen: boolean }[] = [];

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

const spotlightTile = computed(() => {
    // 1. Pinned participant
    if (meetingStore.pinnedParticipantId) {
        const pinScreen = renderedTiles.value.find(
            (t) => t.id === `${meetingStore.pinnedParticipantId}:screen`,
        );
        if (pinScreen) return pinScreen;
        return renderedTiles.value.find(
            (t) => t.id === meetingStore.pinnedParticipantId,
        );
    }

    // 2. Priority: Any active screenshare
    const screenSharer = renderedTiles.value.find((t) => t.isScreen);
    if (screenSharer) return screenSharer;

    // 3. Fallback: Active speaker (camera tile)
    if (meetingStore.activeSpeakerId) {
        return renderedTiles.value.find(
            (t) => t.id === meetingStore.activeSpeakerId && !t.isScreen,
        );
    }

    return null;
});

const isSpotlightMode = computed(() => !!spotlightTile.value);

const unspotlightedTiles = computed(() => {
    if (!spotlightTile.value) return renderedTiles.value;
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
    clockInterval = window.setInterval(updateClock, 10000);

    if (!participantId) {
        toast.error("No participant ID — returning to lobby.");
        router.push({ name: "meeting-lobby", params: { id: meetingId } });
        return;
    }

    try {
        await meetingStore.initializeMeeting(meetingId, participantId);

        const stream = meetingStore.localStream;
        if (stream) {
            const videoTrack = stream.getVideoTracks()[0];
            const audioTrack = stream.getAudioTracks()[0];
            
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

            await meetingStore.addLocalStream(stream);
        } else {
            // Cold start: Join without an initial stream (camera/mic off)
            await meetingStore.addLocalStream(null);
            isCameraOn.value = false;
            isMicOn.value = false;
        }

        meetingService.sendSignal(meetingId, {
            signal_type: "participant-joined",
            signal_data: {},
            sender_participant_public_id: participantId,
        });

        window.addEventListener("keydown", handleGlobalKeydown);

        // Auto-start screen share if joined via "Present" button
        if (route.query.present === "1") {
            setTimeout(() => {
                toggleScreenShare();
            }, 2000); // Wait for SFU connection to establish
        }
    } catch (e) {
        console.error("[MeetingRoom] Failed to initialize:", e);
        toast.error("Failed to initialize meeting room.");
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
    backgroundBlur.stopProcessing();
    meetingStore.cleanup();
});

// ─── Controls ────────────────────────────────────────────────────────────────

const isScreenSharing = ref(false);
const screenStream = ref<MediaStream | null>(null);

async function toggleScreenShare() {
    if (isScreenSharing.value) {
        if (screenStream.value) {
            screenStream.value.getTracks().forEach((t) => t.stop());
            screenStream.value = null;
        }
        isScreenSharing.value = false;

        await meetingStore.unpublishScreenTrack();
        meetingStore.clearSpotlight();
    } else {
        try {
            const stream = await navigator.mediaDevices.getDisplayMedia({
                video: true,
                audio: false,
            });
            screenStream.value = stream;
            isScreenSharing.value = true;

            await meetingStore.publishScreenTrack(stream);

            meetingStore.setSpotlight(meetingStore.localParticipant!.public_id);

            const screenTrack = stream.getVideoTracks()[0];
            screenTrack.onended = () => {
                if (isScreenSharing.value) toggleScreenShare();
            };
        } catch (err) {
            toast.error("Failed to share screen");
            console.error(err);
        }
    }
}

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
                video: videoCallStore.selectedVideoDeviceId
                    ? { deviceId: videoCallStore.selectedVideoDeviceId }
                    : true,
            });
            const videoTrack = newStream.getVideoTracks()[0];
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
                );
            }

            const updatedStream = new MediaStream([
                ...stream.getAudioTracks(),
                finalTrack,
            ]);
            meetingStore.setStream(updatedStream);
            isCameraOn.value = true;
            meetingStore.replaceTrack("video", finalTrack);
        } catch (e) {
            console.error("Failed to start camera", e);
            toast.error("Could not access camera hardware.");
        }
    } else {
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
        isCameraOn.value = false;
        meetingStore.replaceTrack("video", null);
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
                audio: videoCallStore.selectedAudioDeviceId
                    ? { deviceId: videoCallStore.selectedAudioDeviceId }
                    : true,
            });
            const audioTrack = newStream.getAudioTracks()[0];
            const updatedStream = new MediaStream([
                ...stream.getVideoTracks(),
                audioTrack
            ]);
            meetingStore.setStream(updatedStream);
            isMicOn.value = true;
            meetingStore.replaceTrack("audio", audioTrack);
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
        meetingStore.replaceTrack("audio", null);
    }
};

watch(
    [
        () => videoCallStore.videoEffect,
        () => videoCallStore.backgroundImage,
        () => videoCallStore.autoFraming,
    ],
    async ([effect, bgImage, framing]) => {
        if (!isCameraOn.value || !meetingStore.originalVideoTrack || !meetingStore.localStream)
            return;

        try {
            let newTrack: MediaStreamTrack;
            if (effect === "blur" || effect === "image") {
                newTrack = await backgroundBlur.startVideoEffect(
                    meetingStore.originalVideoTrack,
                    effect,
                    bgImage || undefined,
                    framing,
                );
            } else {
                backgroundBlur.stopProcessing();
                newTrack = meetingStore.originalVideoTrack;
            }

            const stream = meetingStore.localStream;
            const oldTrack = stream.getVideoTracks()[0];
            
            if (oldTrack && oldTrack.id !== newTrack.id) {
                stream.removeTrack(oldTrack);
                stream.addTrack(newTrack);
                
                // Keep enabled state synced
                newTrack.enabled = oldTrack.enabled;
                
                meetingStore.replaceTrack("video", newTrack);
                console.info(
                    `[MeetingRoom] Swapped camera track from ${oldTrack.id} to ${newTrack.id}`,
                );
            }
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
                    audio: newAudio ? { deviceId: newAudio } : true,
                });
                const track = newS.getAudioTracks()[0];
                stream.addTrack(track);
                meetingStore.replaceTrack("audio", track);
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
                    video: newVideo ? { deviceId: newVideo } : true,
                });
                const videoTrack = newS.getVideoTracks()[0];
                meetingStore.originalVideoTrack = videoTrack;

                let finalTrack = videoTrack;
                if ((videoCallStore.videoEffect === 'blur' || videoCallStore.videoEffect === 'image') && videoTrack) {
                    finalTrack = await backgroundBlur.startVideoEffect(
                        videoTrack,
                        videoCallStore.videoEffect,
                        videoCallStore.backgroundImage || undefined,
                        videoCallStore.autoFraming
                    );
                }

                const oldTrack = stream.getVideoTracks()[0];
                if (oldTrack) stream.removeTrack(oldTrack);
                stream.addTrack(finalTrack);
                meetingStore.replaceTrack("video", finalTrack);
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
const isRecording = ref(false);

function toggleRecording() {
    isRecording.value = !isRecording.value;
    if (isRecording.value) {
        toast.success("Recording started (simulated)");
    } else {
        toast.info("Recording stopped (simulated)");
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
            console.log("[LASER] Enforcement: No screensharing active. Disabling laser pointer.");
            meetingStore.laserPointerMode = "off";
        }
    },
    { immediate: true }
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

const isControlsCollapsed = ref(false);

// Draggability Logic for Controls
const controlsPosition = ref({ x: 0, y: 0 }); // Offset from default bottom center
const isDraggingControls = ref(false);
let startX = 0;
let startY = 0;

const controlsStyle = computed(() => {
    const isMobile = window.innerWidth <= 600;
    const scale = isMobile ? 0.65 : 1;
    return {
        transform: `translate(${controlsPosition.value.x}px, ${controlsPosition.value.y}px) scale(${scale})`,
        transformOrigin: 'bottom center',
        transition: isDraggingControls.value ? 'none' : 'transform 0.2s ease'
    };
});

function startDragControls(e: MouseEvent | TouchEvent) {
    isDraggingControls.value = true;
    const clientX = 'touches' in e ? e.touches[0].clientX : e.clientX;
    const clientY = 'touches' in e ? e.touches[0].clientY : e.clientY;
    
    startX = clientX - controlsPosition.value.x;
    startY = clientY - controlsPosition.value.y;

    window.addEventListener('mousemove', onDragControls);
    window.addEventListener('touchmove', onDragControls);
    window.addEventListener('mouseup', stopDragControls);
    window.addEventListener('touchend', stopDragControls);
}

const controlsRef = ref<HTMLElement | null>(null);

function onDragControls(e: MouseEvent | TouchEvent) {
    if (!isDraggingControls.value || !controlsRef.value) return;
    const clientX = 'touches' in e ? e.touches[0].clientX : e.clientX;
    const clientY = 'touches' in e ? e.touches[0].clientY : e.clientY;
    
    const nextX = clientX - startX;
    const nextY = clientY - startY;

    // Boundary constraints
    const el = controlsRef.value;
    const rect = el.getBoundingClientRect();
    const deltaX = nextX - controlsPosition.value.x;
    const deltaY = nextY - controlsPosition.value.y;

    let finalX = nextX;
    let finalY = nextY;

    // Clamp within viewport with 8px padding
    if (rect.left + deltaX < 8) {
        finalX = controlsPosition.value.x - rect.left + 8;
    } else if (rect.right + deltaX > window.innerWidth - 8) {
        finalX = controlsPosition.value.x + (window.innerWidth - rect.right - 8);
    }

    if (rect.top + deltaY < 8) {
        finalY = controlsPosition.value.y - rect.top + 8;
    } else if (rect.bottom + deltaY > window.innerHeight - 8) {
        finalY = controlsPosition.value.y + (window.innerHeight - rect.bottom - 8);
    }

    controlsPosition.value = { x: finalX, y: finalY };
}

function stopDragControls() {
    isDraggingControls.value = false;
    window.removeEventListener('mousemove', onDragControls);
    window.removeEventListener('touchmove', onDragControls);
    window.removeEventListener('mouseup', stopDragControls);
    window.removeEventListener('touchend', stopDragControls);
}

onUnmounted(stopDragControls);
</script>

<style scoped>
/* ─── Root & Reset ─────────────────────────────────────────────────────────── */
.gmeet-root {
    display: flex;
    flex-direction: column;
    height: 100vh;
    height: 100dvh;
    background: radial-gradient(circle at 50% 10%, #292a2d 0%, #111111 100%);
    color: #e8eaed;
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

/* ─── Top Bar ──────────────────────────────────────────────────────────────── */
.gmeet-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 64px;
    padding: 0 24px;
    background: rgba(32, 33, 36, 0.7);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    z-index: 40;
    flex-shrink: 0;
}

.topbar-left {
    display: flex;
    align-items: center;
    min-width: 0;
    flex: 1;
}

.topbar-title {
    font-size: 18px;
    font-weight: 500;
    color: #ffffff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: -0.2px;
}

.topbar-center {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    padding: 0 20px;
}

.topbar-clock {
    font-size: 14px;
    color: #9aa0a6;
    font-weight: 500;
    letter-spacing: 0.25px;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    justify-content: flex-end;
}

.topbar-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border: 1px solid transparent;
    background: rgba(255, 255, 255, 0.03);
    color: #e8eaed;
    border-radius: 24px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
}
.topbar-btn:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.05);
}
.topbar-btn--active {
    background: rgba(138, 180, 248, 0.15);
    color: #8ab4f8;
    border-color: rgba(138, 180, 248, 0.3);
}
.topbar-btn--alert {
    position: relative;
}

.topbar-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    min-width: 16px;
    height: 16px;
    background: #ea4335;
    color: white;
    font-size: 10px;
    font-weight: 700;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    animation: pulse-badge 2s infinite;
}

@keyframes pulse-badge {
    0%,
    100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.15);
    }
}

.topbar-count {
    font-weight: 500;
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
    padding: 16px 16px 90px 16px; /* Extra padding at bottom for absolute floating controls */
    gap: 8px;
    position: relative;
    min-width: 0;
}

/* ─── Grid Layout ──────────────────────────────────────────────────────────── */
.grid-container {
    flex: 1;
    display: grid;
    gap: 8px;
    padding: 8px;
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
    max-width: 1080px;
    margin: 0 auto;
    width: 100%;
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

    background: #303134;
    border-radius: 12px;
    overflow: hidden;
    position: relative;
    display: flex;
    justify-content: center;
    align-items: center;
    border: 1px solid rgba(255, 255, 255, 0.08); /* Premium outline */
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25); /* Subtle lift */
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
    background: rgba(32, 33, 36, 0.85);
    color: #e8eaed;
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
    background: rgba(60, 64, 67, 0.95);
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
    background: #303134;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.filmstrip-nav {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: none;
    background: rgba(32, 33, 36, 0.9);
    color: #e8eaed;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    z-index: 5;
}
.filmstrip-nav:hover {
    background: #3c4043;
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
    background: #3c4043;
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
    flex-shrink: 0;
    background: #292a2d;
    display: flex;
    flex-direction: column;
    border-left: 1px solid #3c4043;
    z-index: 25;
}

.side-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 20px;
    border-bottom: 1px solid #3c4043;
    flex-shrink: 0;
}
.side-panel-header h3 {
    font-size: 16px;
    font-weight: 600;
    color: #e8eaed;
    margin: 0;
    letter-spacing: -0.01em;
}

.side-panel-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: #9aa0a6;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
.side-panel-close:hover {
    background: rgba(255, 255, 255, 0.08);
    color: #e8eaed;
}

.side-panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
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
    background: rgba(255, 255, 255, 0.05);
}

.participant-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #5f6368;
    color: #e8eaed;
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
    color: #e8eaed;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    gap: 6px;
}

.you-badge {
    font-size: 10px;
    color: #8ab4f8;
    background: rgba(138, 180, 248, 0.12);
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
    color: #9aa0a6;
    font-size: 13px;
}

.admission-card {
    background: #3c4043;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 8px;
}

.admission-info {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.admission-name {
    font-size: 13px;
    color: #e8eaed;
    font-weight: 500;
    margin: 0;
}
.admission-sub {
    font-size: 11px;
    color: #9aa0a6;
    margin: 0;
}

.admission-actions {
    display: flex;
    gap: 8px;
}

.admission-btn {
    flex: 1;
    padding: 6px 0;
    border-radius: 20px;
    border: none;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}

.admission-btn--admit {
    background: #8ab4f8;
    color: #202124;
}
.admission-btn--admit:hover {
    background: #aecbfa;
}

.admission-btn--reject {
    background: transparent;
    color: #e8eaed;
    border: 1px solid #5f6368;
}
.admission-btn--reject:hover {
    background: rgba(234, 67, 53, 0.15);
    color: #f28b82;
    border-color: #f28b82;
}

/* ─── Bottom Control Bar ───────────────────────────────────────────────────── */
.gmeet-controls {
    display: flex;
    justify-content: center;
    padding: 16px 0 max(24px, env(safe-area-inset-bottom));
    background: linear-gradient(
        0deg,
        rgba(20, 20, 22, 0.95) 0%,
        rgba(20, 20, 22, 0) 100%
    );
    z-index: 40;
    flex-shrink: 0;
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    pointer-events: none; /* Allows clicking through the gradient */
}

.controls-center {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 24px;
    background: rgba(48, 49, 52, 0.85);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 36px;
    box-shadow:
        0 12px 32px rgba(0, 0, 0, 0.4),
        inset 0 1px 0 rgba(255, 255, 255, 0.05);
    pointer-events: auto; /* Re-enable clicks for the actual pill menu */
}

.ctrl-btn {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: none;
    background: #3c4043;
    color: #e8eaed;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition:
        background 0.15s,
        transform 0.1s,
        box-shadow 0.15s;
    position: relative;
}
.ctrl-btn:hover {
    background: #4a4d51;
    transform: scale(1.05);
}
.ctrl-btn:active {
    transform: scale(0.95);
}

.ctrl-btn--off {
    background: #ea4335;
    color: white;
}
.ctrl-btn--off:hover {
    background: #d93025;
}

.ctrl-btn--active {
    background: #8ab4f8;
    color: #202124;
}
.ctrl-btn--active:hover {
    background: #aecbfa;
}

.ctrl-btn--hangup {
    background: #ea4335;
    color: white;
    width: 56px;
    border-radius: 28px;
}
.ctrl-btn--hangup:hover {
    background: #d93025;
    box-shadow: 0 0 16px rgba(234, 67, 53, 0.4);
}

@media (max-width: 600px) {
    .gmeet-controls {
        padding-bottom: max(48px, env(safe-area-inset-bottom));
    }
    .controls-center {
        gap: 3px;
        padding: 4px 14px 4px 8px; /* More padding on right for chevron */
        max-width: none !important;
        width: max-content !important;
        border-radius: 18px;
    }
    .ctrl-btn {
        width: 30px !important;
        height: 30px !important;
        flex: 0 0 30px !important;
        min-width: 30px !important;
        padding: 0 !important;
    }
    .ctrl-btn :deep(svg) {
        width: 14px;
        height: 14px;
    }
    .ctrl-btn--hangup {
        width: 30px !important;
        height: 30px !important;
        border-radius: 50% !important;
        flex: 0 0 30px !important;
    }
    .reaction-quick-btn {
        min-width: 32px !important;
        padding-right: 4px !important;
    }
    .reaction-chevron-btn {
        width: 20px !important;
        min-width: 20px !important;
        padding: 0 !important;
    }
    .collapse-btn {
        margin-left: -2px !important; /* Pull it slightly inside the gap */
        opacity: 0.9 !important;
        padding: 0 4px !important;
    }
    .solo-content {
        border-radius: 0 !important;
        padding: 40px 20px !important;
        width: 100% !important;
        height: 100% !important;
        border: none !important;
    }
    .drag-grip {
        display: none; /* Hide grip on small screens to save space */
    }
    .gmeet-controls--collapsed .controls-center {
        padding: 2px 8px;
    }
}

.drag-grip {
    padding: 8px 4px;
    cursor: grab;
    color: #5f6368;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.2s;
}

.drag-grip:hover {
    color: #bdc1c6;
}

.drag-grip:active {
    cursor: grabbing;
}

.collapse-btn {
    opacity: 0.6;
    margin-left: 4px;
}

.collapse-btn:hover {
    opacity: 1;
}

.gmeet-controls--collapsed .controls-center {
    padding: 8px 12px;
}

/* Hangup Popup Menu */
.hangup-menu {
    position: absolute;
    bottom: calc(100% + 12px);
    right: 0;
    background: #2d2e30;
    border: 1px solid #3c4043;
    border-radius: 12px;
    padding: 8px 0;
    min-width: 220px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
    z-index: 1000; /* Ensure it stays above everything */
}

@media (max-width: 600px) {
    .hangup-menu {
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
.hangup-menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 10px 16px;
    border: none;
    background: transparent;
    color: #e8eaed;
    font-size: 14px;
    cursor: pointer;
    transition: background-color 0.15s;
    text-align: left;
}
.hangup-menu-item:hover {
    background: #3c4043;
}
.hangup-menu-item--end {
    color: #f28b82;
}
.hangup-menu-item--end:hover {
    background: rgba(234, 67, 53, 0.15);
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
    background: rgba(234, 67, 53, 0.2) !important;
    color: #ea4335 !important;
}

/* Lock Button */
.ctrl-btn--lock-active {
    background: rgba(242, 139, 130, 0.2) !important;
    color: #f28b82 !important;
}
.ctrl-btn--lock-active:hover {
    background: rgba(242, 139, 130, 0.3) !important;
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
    background-color: #111111;
}

.ambient-background {
    position: absolute;
    inset: 0;
    overflow: hidden;
    z-index: 0;
    opacity: 0.6;
}

.blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(90px);
    opacity: 0.5;
    animation: floatBlob 20s infinite ease-in-out alternate;
    will-change: transform;
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
    background: rgba(32, 33, 36, 0.5);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 32px;
    box-shadow:
        0 24px 48px rgba(0, 0, 0, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.1);
    animation: soloFadeIn 1s cubic-bezier(0.16, 1, 0.3, 1);
    text-align: center;
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
    color: #ffffff;
    margin: 0;
    letter-spacing: -0.5px;
}
.solo-hint {
    font-size: 18px;
    color: #e8eaed;
    margin: 12px 0 0 0;
    font-weight: 400;
}
.solo-hint-sub {
    font-size: 15px;
    color: #9aa0a6;
    margin: 8px 0 0 0;
}

/* ─── Waiting Room Overlay ─────────────────────────────────────────────────── */
.waiting-overlay {
    position: fixed;
    inset: 0;
    z-index: 100;
    background: #202124;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}

.waiting-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    max-width: 420px;
}

.waiting-icon-wrap {
    position: relative;
    margin-bottom: 32px;
}
.waiting-icon-circle {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    background: rgba(138, 180, 248, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    animation: pulse 2s ease-in-out infinite;
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
    background: #202124;
    display: flex;
    align-items: center;
    justify-content: center;
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
    font-weight: 400;
    color: #e8eaed;
    margin: 0 0 8px;
}
.waiting-desc {
    font-size: 14px;
    color: #9aa0a6;
    margin: 0;
    line-height: 1.5;
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
    background: #303134;
    border-radius: 20px;
    font-size: 13px;
    color: #9aa0a6;
}
.waiting-host-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
}

.waiting-cancel-btn {
    background: none;
    border: none;
    color: #8ab4f8;
    font-size: 13px;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 4px;
}
.waiting-cancel-btn:hover {
    color: #aecbfa;
}

/* ─── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .gmeet-topbar {
        padding: 0 12px;
        height: 48px;
    }
    .topbar-center {
        display: none;
    }
    .side-panel {
        width: 100%;
        position: absolute;
        right: 0;
        top: 0;
        bottom: 0;
    }
    .pip-self-view {
        width: 140px;
        bottom: 12px;
        right: 12px;
    }
    .ctrl-btn {
        width: 42px;
        height: 42px;
    }
    .ctrl-btn--hangup {
        width: 50px;
    }
    .controls-center {
        gap: 8px;
        padding: 6px 14px;
    }
    .filmstrip {
        height: 90px;
    }
    .filmstrip-tile {
        width: 140px;
    }
}
</style>
