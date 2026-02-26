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
                                v-if="meetingStore?.meeting?.host?.avatar_url"
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
                                <div class="detail-label">Password</div>
                                <div class="detail-value">
                                    {{ meetingStore.meeting.password }}
                                </div>
                                <button
                                    @click="
                                        copyToClipboard(
                                            meetingStore.meeting.password,
                                            'Password',
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
                                <p class="text-xs text-[#9aa0a6] mb-2 truncate">
                                    {{ meetingStore.meetingLink }}
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

        <!-- Admission Overlay (Moderator Only) -->
        <aside v-if="meetingStore.isHost" class="admission-overlay">
            <Transition name="panel">
                <div
                    v-if="showAdmissionPanel"
                    class="admission-panel"
                    v-click-outside="() => (showAdmissionPanel = false)"
                >
                    <div class="admission-header">
                        <h3>Waiting Room</h3>
                        <div class="badge-pill">
                            {{ meetingStore.waitingParticipants.length }}
                        </div>
                    </div>
                    <div class="admission-list">
                        <div
                            v-for="p in meetingStore.waitingParticipants"
                            :key="p.public_id"
                            class="admission-item"
                        >
                            <div class="admission-user">
                                <Avatar
                                    :user="p.user"
                                    :fallback="p.metadata?.guest_name || 'G'"
                                    size="32"
                                />
                                <div class="user-meta">
                                    <span class="user-name">{{
                                        p.user?.name ||
                                        p.metadata?.guest_name ||
                                        "Guest"
                                    }}</span>
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
                </div>
            </Transition>
        </aside>

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
            <div class="gmeet-stage" style="position: relative">
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
                        meetingStore.meeting?.title || meetingId
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
                    title="Recording in progress"
                >
                    <span class="recording-dot"></span>
                    <span>REC</span>
                </div>
                
                <NetworkHealthIndicator 
                    v-if="meetingStore.sfuPc()"
                    v-bind="networkStats"
                    compact
                    class="ml-2"
                />
            </div>

            <!-- Center: Media Controls -->
            <div class="bar-section bar-section--center">
                <button
                    class="ctrl-btn"
                    :class="{ 'ctrl-btn--off': !isMicOn }"
                    @click="toggleMic"
                    :title="micToggleTitle"
                >
                    <Icon :name="isMicOn ? 'mic' : 'mic-off'" size="20" />
                </button>
                <button
                    class="ctrl-btn"
                    :class="{ 'ctrl-btn--off': !isCameraOn }"
                    @click="toggleCamera"
                    :title="cameraToggleTitle"
                >
                    <Icon
                        :name="isCameraOn ? 'video' : 'video-off'"
                        size="20"
                    />
                </button>
                <button
                    class="ctrl-btn"
                    :class="{ 'ctrl-btn--active-bounce': isHandRaised }"
                    @click="meetingStore.toggleHand()"
                    title="Raise Hand"
                >
                    <Icon name="hand" size="20" />
                </button>
                <button
                    class="ctrl-btn reaction-btn-unified"
                    :class="{ 'ctrl-btn--active': showReactionPicker }"
                    @click="showReactionPicker = !showReactionPicker"
                    title="Reactions"
                >
                    <Transition name="pop">
                        <span
                            v-if="lastReactionEmoji"
                            class="quick-emoji-preview"
                            >{{ lastReactionEmoji }}</span
                        >
                    </Transition>
                    <span
                        v-if="lastReactionEmoji"
                        class="unified-reaction-divider"
                    ></span>
                    <Icon name="smile" size="20" />
                </button>
                <button
                    class="ctrl-btn"
                    :class="{ 'ctrl-btn--active': isScreenSharing }"
                    @click="toggleScreenShare"
                    :title="screenShareToggleTitle"
                >
                    <Icon name="monitor" size="20" />
                </button>
                <!-- Annotation (Presenter only) -->
                <button
                    v-if="isScreenSharing"
                    class="ctrl-btn"
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
                <button
                    v-if="meetingStore.isHost"
                    class="ctrl-btn lock-btn-wrap"
                    :class="{ 'ctrl-btn--lock-active': meetingStore.isLocked }"
                    @click="meetingStore.toggleLock()"
                    :title="
                        meetingStore.isLocked
                            ? 'Unlock Meeting'
                            : 'Lock Meeting'
                    "
                >
                    <Transition name="icon-morph" mode="out-in">
                        <Icon
                            :key="meetingStore.isLocked ? 'lock' : 'unlock'"
                            :name="meetingStore.isLocked ? 'lock' : 'unlock'"
                            size="20"
                            class="morph-icon"
                        />
                    </Transition>
                </button>

                <button
                    v-if="
                        meetingStore.isHost &&
                        meetingStore.waitingParticipants.length > 0
                    "
                    class="ctrl-btn btn--alert"
                    @click="showAdmissionPanel = !showAdmissionPanel"
                    title="Waiting Room"
                >
                    <Icon name="user-plus" size="20" />
                    <span class="badge-count">{{
                        meetingStore.waitingParticipants.length
                    }}</span>
                </button>

                <button
                    class="ctrl-btn"
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
                    class="ctrl-btn"
                    :class="{ 'ctrl-btn--active': showChatPanel }"
                    @click="toggleChatPanel"
                    title="Chat"
                >
                    <Icon name="message-square" size="20" />
                </button>
                <button
                    class="ctrl-btn"
                    :class="{ 'ctrl-btn--active': showPollPanel }"
                    @click="togglePollPanel"
                    title="Polls"
                >
                    <Icon name="bar-chart-2" size="20" />
                </button>

                <MeetingLayoutSelector />

                <button
                    class="ctrl-btn"
                    @click="showSettings = true"
                    title="Settings"
                >
                    <Icon name="settings" size="20" />
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
                        size="20"
                    />
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
import { ref, reactive, computed, onMounted, onUnmounted, onBeforeUnmount, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { meetingService } from "@/services/meeting.service";
import { useMeetingStore } from "@/stores/meeting";
import { useVideoCallStore } from "@/stores/videocall";
import { useBackgroundBlur } from "@/composables/useBackgroundBlur";
import { Icon, Avatar } from "@/components/ui";
import { toast } from "vue-sonner";
import api from "@/lib/api";
import { Menu, MenuButton, MenuItems, MenuItem } from "@headlessui/vue";

import DeviceSettingsModal from "./components/DeviceSettingsModal.vue";
import DevSimulationTool from "./components/DevSimulationTool.vue";
import ParticipantTile from "./components/ParticipantTile.vue";
import NetworkHealthIndicator from '../call/components/NetworkHealthIndicator.vue';
import MeetingChatPanel from "./components/MeetingChatPanel.vue";
import MeetingPollPanel from "./components/MeetingPollPanel.vue";
import MeetingLayoutSelector from "./components/MeetingLayoutSelector.vue";
import ReactionOverlay from "./components/ReactionOverlay.vue";
import ReactionVibeSummary from "./components/ReactionVibeSummary.vue";

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
const showMeetingDetails = ref(false);
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
    if (mode === "off") return "Enable Laser Pointer (everyone)";
    if (mode === "global")
        return "Laser Pointer ON (everyone) — click to turn off";
    return "Laser Pointer targeted — click to turn off";
});

async function cycleLaserMode() {
    if (!meetingStore.meeting) return;
    const next = meetingStore.laserPointerMode === "off" ? "global" : "off";
    meetingStore.laserPointerMode = next;
    try {
        await api.patch(
            `/api/meetings/${meetingStore.meeting.public_id}/settings`,
            {
                settings: { laser_pointer_mode: next },
            },
        );
        // Sync to all participants
        meetingStore.sendSignal("laser-mode-changed", { mode: next });
    } catch {
        // Revert on failure
        meetingStore.laserPointerMode = next === "global" ? "off" : "global";
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

const isWaiting = computed(() => {
    // Safety: Host/Moderator should never see the waiting room overlay
    if (meetingStore.isModerator || meetingStore.isHost) return false;
    return meetingStore.localParticipant?.status === "waiting";
});

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

const isSpotlightMode = computed(() => {
    if (meetingStore.preferredLayout === "tiled") return false;
    return !!spotlightTile.value;
});

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
    if (meetingStore.preferredLayout === "spotlight") return [];

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
                audioTrack,
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

watch(isRecording, (val) => {
    if (val) {
        toast.success("Meeting is being recorded", {
            description:
                "By staying in the meeting, you consent to being recorded. Please review our privacy policy.",
            duration: 6000,
        });
    } else {
        toast.info("Recording has stopped", {
            duration: 4000,
        });
    }
});

function toggleRecording() {
    isRecording.value = !isRecording.value;
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

function copyToClipboard(text: string, label: string) {
    navigator.clipboard.writeText(text);
    toast.success(`${label} copied to clipboard`);
}

function copyMeetingLink() {
    copyToClipboard(meetingStore.meetingLink, "Meeting link");
}

// ── Network Stats Tracking ──────────────────────────────────────────────────
const networkStats = reactive({
    bitrate: 0,
    packetLoss: 0,
    rtt: 0,
    score: 0,
});

let lastBytes = 0;
let lastStatsTime = Date.now();
let statsInterval: number | null = null;

async function updateNetworkStats() {
    const pc = meetingStore.sfuPc();
    if (!pc) return;

    try {
        const stats = await pc.getStats();
        const now = Date.now();
        const delta = (now - lastStatsTime) / 1000;
        lastStatsTime = now;

        let currentBytes = 0;
        let totalPacketsLost = 0;
        let totalPacketsReceived = 0;

        stats.forEach((report) => {
            // RTT from candidate-pair
            if (report.type === "candidate-pair" && report.state === "succeeded") {
                networkStats.rtt = (report.currentRoundTripTime || 0) * 1000;
            }
            // Outbound bitrate (bytesSent) or Inbound (bytesReceived)
            // For general health, we track both but bitrate usually refers to outbound local
            if (report.type === "outbound-rtp") {
                currentBytes += report.bytesSent || 0;
            }
            // Inbound packet loss
            if (report.type === "inbound-rtp") {
                totalPacketsLost += report.packetsLost || 0;
                totalPacketsReceived += report.packetsReceived || 0;
            }
        });

        if (lastBytes > 0 && delta > 0) {
            networkStats.bitrate = ((currentBytes - lastBytes) * 8) / (delta * 1000); // kbps
        }
        lastBytes = currentBytes;

        const totalPackets = totalPacketsLost + totalPacketsReceived;
        const lossPercent = totalPackets > 0 ? (totalPacketsLost / totalPackets) * 100 : 0;
        networkStats.packetLoss = lossPercent;

        // Scoring (0=Good, 1=Fair, 2=Poor)
        if (lossPercent > 10 || networkStats.rtt > 400) networkStats.score = 2;
        else if (lossPercent > 3 || networkStats.rtt > 200) networkStats.score = 1;
        else networkStats.score = 0;

    } catch (e) {
        // Silent fail for stats
    }
}

onMounted(() => {
    statsInterval = window.setInterval(updateNetworkStats, 3000);
});

onBeforeUnmount(() => {
    if (statsInterval) window.clearInterval(statsInterval);
});
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

/* ─── Bottom-Fixed Control Bar ─────────────────────────────────────────── */
.app-bottom-bar {
    width: 100%;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    background: #202124;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    z-index: 100;
    flex-shrink: 0;
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
    background: rgba(255, 255, 255, 0.05);
}

.info-pill--active {
    background: rgba(138, 180, 248, 0.1) !important;
}

.info-time {
    color: #ffffff;
    font-size: 15px;
    font-weight: 500;
}

.info-divider {
    width: 1px;
    height: 16px;
    background: rgba(255, 255, 255, 0.2);
}

.info-code {
    color: #e8eaed;
    font-size: 14px;
    font-weight: 400;
    letter-spacing: 0.5px;
}

.recording-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: rgba(234, 67, 53, 0.15);
    color: #f28b82;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    margin-left: 8px;
}

.recording-dot {
    width: 8px;
    height: 8px;
    background: #ea4335;
    border-radius: 50%;
    animation: pulse-red 2s infinite;
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
    color: #e8eaed;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
}

.ctrl-btn:hover {
    background: rgba(255, 255, 255, 0.08);
}

.ctrl-btn--off {
    background: #ea4335 !important;
    color: white !important;
}

.ctrl-btn--off:hover {
    background: #d93025 !important;
}

.ctrl-btn--active {
    background: rgba(138, 180, 248, 0.15);
    color: #8ab4f8;
}

.ctrl-btn--hangup {
    background: #ea4335;
    color: white;
    width: 56px;
    border-radius: 28px;
}

.ctrl-btn--hangup:hover {
    background: #d93025;
    box-shadow: 0 4px 16px rgba(234, 67, 53, 0.4);
}

/* Grouped Components */
.reaction-group {
    display: flex;
    align-items: center;
    background: rgba(255, 255, 255, 0.05);
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
    background: #202124;
    border: 1px solid #3c4043;
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
    color: #e8eaed;
    font-size: 14px;
    border-radius: 8px;
    cursor: pointer;
    text-align: left;
    transition: background 0.15s;
}

.menu-item:hover {
    background: rgba(255, 255, 255, 0.08);
}

.menu-item--danger {
    color: #f28b82;
}

.menu-item--danger:hover {
    background: rgba(234, 67, 53, 0.15);
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
    border: 2px solid #202124;
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
    height: 100%;
    flex-shrink: 0;
    background: #292a2d;
    display: flex;
    flex-direction: column;
    border-left: 1px solid #3c4043;
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
    background: #202124;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.1);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.details-header {
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.details-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 500;
    color: #e8eaed;
}

.details-close {
    background: transparent;
    border: none;
    color: #9aa0a6;
    cursor: pointer;
    padding: 4px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.details-close:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #ffffff;
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
    color: #9aa0a6;
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
    color: #bdc1c6;
    flex-shrink: 0;
}

.detail-value {
    font-size: 11px;
    color: #e8eaed;
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
    background: rgba(255, 255, 255, 0.04);
    border-radius: 8px;
    padding: 12px;
    border: 1px solid rgba(255, 255, 255, 0.08);
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

.ctrl-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.1);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    padding: 0;
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

@media (max-width: 800px) {
    .app-bottom-bar {
        padding: 0 12px;
        height: 72px;
    }
    .bar-section--left {
        display: none; /* Hide meeting code on small screens */
    }
    .ctrl-btn {
        width: 38px;
        height: 38px;
    }
    .ctrl-btn--hangup {
        width: 48px;
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
    background: #2d2e30;
    border: 1px solid #3c4043;
    border-radius: 12px;
    padding: 8px 0;
    min-width: 220px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
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
    color: #e8eaed;
    font-size: 14px;
    cursor: pointer;
    transition: background-color 0.15s;
    text-align: left;
}
.menu-item:hover {
    background: #3c4043;
}
.menu-item--danger {
    color: #f28b82;
}
.menu-item--danger:hover {
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
    background: rgba(242, 139, 130, 0.2) !important;
    color: #f28b82 !important;
    transform: scale(1.1) rotate(5deg);
    box-shadow: 0 0 15px rgba(242, 139, 130, 0.2);
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

/* Reaction Button Unified Pill */
.reaction-btn-unified {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 16px !important;
    width: auto !important;
    min-width: 48px;
    border-radius: 24px !important;
    background: rgba(255, 255, 255, 0.08) !important;
    transition: all 0.2s ease;
}
.reaction-btn-unified:hover {
    background: rgba(255, 255, 255, 0.12) !important;
    transform: scale(1.02);
}
.unified-reaction-divider {
    width: 1px;
    height: 16px;
    background: rgba(255, 255, 255, 0.2);
}
.quick-emoji-preview {
    font-size: 18px;
    line-height: 1;
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
    border: 2px solid #202124;
    z-index: 10;
}

.badge-count--secondary {
    background: rgba(255, 255, 255, 0.12) !important;
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.12) !important;
    color: #e8eaed !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
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
    background: rgba(251, 188, 5, 0.2) !important;
    color: #fbbc05 !important;
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
