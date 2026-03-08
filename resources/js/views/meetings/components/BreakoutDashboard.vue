<template>
    <div
        v-if="meetingStore.activeBreakoutSession && meetingStore.isHost"
        class="fixed z-100 w-[400px] select-none"
        :style="{
            right: !hasBeenDragged ? '24px' : 'auto',
            bottom: !hasBeenDragged ? '96px' : 'auto',
            left: hasBeenDragged ? `${position.x}px` : 'auto',
            top: hasBeenDragged ? `${position.y}px` : 'auto',
        }"
    >
        <div
            class="bg-(--surface-primary)/85 backdrop-blur-2xl border border-(--border-muted) rounded-3xl shadow-[0_20px_50px_rgba(0,0,0,0.3)] flex flex-col overflow-visible max-h-[600px] transition-all duration-300"
        >
            <!-- Header -->
            <div
                class="p-4 border-b border-(--border-muted) flex items-center justify-between bg-(--surface-tertiary)/50 cursor-move"
                @pointerdown="startDragging"
            >
                <!-- Drag Grip Handle -->
                <div
                    class="absolute top-1 left-1/2 -translate-x-1/2 flex gap-1 opacity-20 group-hover:opacity-100 transition-opacity"
                >
                    <div class="w-1 h-1 rounded-full bg-(--text-muted)"></div>
                    <div class="w-1 h-1 rounded-full bg-(--text-muted)"></div>
                    <div class="w-1 h-1 rounded-full bg-(--text-muted)"></div>
                </div>

                <div class="flex items-center gap-2">
                    <div class="flex h-2 w-2 relative">
                        <span
                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"
                        ></span>
                        <span
                            class="relative inline-flex rounded-full h-2 w-2 bg-green-500"
                        ></span>
                    </div>
                    <div>
                        <span
                            class="text-xs font-bold uppercase tracking-wider text-(--text-muted)"
                            >Breakout Control</span
                        >
                        <div
                            v-if="meetingStore.activeBreakoutSession"
                            class="flex items-center gap-1.5 mt-0.5"
                        >
                            <span
                                class="text-[10px] text-(--text-muted) font-mono"
                                >{{ meetingStore.formatBreakoutTime }}</span
                            >
                            <!-- Timer Adjustments -->
                            <div
                                v-if="meetingStore.breakoutTimer > 0"
                                class="flex gap-1"
                            >
                                <button
                                    @click="adjustTimer(1)"
                                    class="w-4 h-4 rounded bg-(--surface-muted) hover:bg-(--surface-highlight) text-(--text-muted) border border-(--border-muted) flex items-center justify-center transition-all"
                                    title="Add 1 minute"
                                >
                                    <Icon name="plus" size="8" />
                                </button>
                                <button
                                    @click="adjustTimer(-1)"
                                    class="w-4 h-4 rounded bg-(--surface-muted) hover:bg-(--surface-highlight) text-(--text-muted) border border-(--border-muted) flex items-center justify-center transition-all"
                                    title="Remove 1 minute"
                                >
                                    <Icon name="minus" size="8" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        v-if="meetingStore.isInBreakout"
                        @click="returnToMain"
                        :disabled="meetingStore.isTransitioningRoom"
                        class="p-1 px-2 flex items-center gap-1.5 border border-(--color-primary-500)/20 rounded-lg text-[10px] font-bold transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        :class="[
                            meetingStore.isTransitioningRoom
                                ? 'bg-(--surface-muted) text-(--text-muted)'
                                : 'bg-(--color-primary-600)/10 hover:bg-(--color-primary-600) text-(--color-primary-600) hover:text-white',
                        ]"
                        title="Leave breakout and return to main meeting"
                    >
                        <Icon
                            v-if="meetingStore.isTransitioningRoom"
                            name="loader"
                            size="12"
                            class="animate-spin"
                        />
                        <Icon v-else name="log-out" size="12" />
                        Main Room
                    </button>
                    <button
                        @click="isMinimized = !isMinimized"
                        class="p-1 hover:bg-(--surface-muted) rounded-md transition-colors"
                    >
                        <Icon
                            :name="isMinimized ? 'chevron-up' : 'chevron-down'"
                            size="16"
                        />
                    </button>
                </div>
            </div>

            <!-- Content -->
            <div
                v-show="!isMinimized"
                class="flex-1 overflow-visible p-5 space-y-5"
            >
                <div
                    class="h-full overflow-y-auto pr-2 -mr-2 custom-scrollbar overflow-x-visible pb-32"
                    style="max-height: calc(600px - 140px)"
                >
                    <!-- Unassigned Participants -->
                    <div
                        v-if="unassignedParticipants.length > 0"
                        class="p-3 bg-amber-500/5 border border-amber-500/20 rounded-xl space-y-3"
                    >
                        <div
                            class="flex items-center justify-between text-amber-600"
                        >
                            <span
                                class="text-xs font-bold uppercase tracking-wider"
                                >Unassigned</span
                            >
                            <span
                                class="text-[10px] px-2 py-0.5 bg-amber-500/10 rounded-full"
                            >
                                {{ unassignedParticipants.length }}
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <div
                                v-for="p in unassignedParticipants"
                                :key="p.public_id"
                                class="group relative"
                            >
                                <DropdownMenuRoot>
                                    <DropdownMenuTrigger as-child>
                                        <button class="outline-none group">
                                            <Avatar
                                                :src="
                                                    p.user?.avatar_url ||
                                                    p.metadata?.avatar_url
                                                "
                                                :fallback="
                                                    getDisplayName(p).charAt(0)
                                                "
                                                :color="p.user?.color"
                                                size="xs"
                                                class="w-7 h-7 border border-amber-500/30 group-hover:border-amber-500 transition-all"
                                            />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuPortal>
                                        <DropdownMenuContent
                                            side="top"
                                            :side-offset="4"
                                            align="start"
                                            class="w-60 bg-(--surface-primary)/95 backdrop-blur-xl border border-(--border-muted) rounded-2xl shadow-[0_20px_50px_rgba(0,0,0,0.3)] z-1060 overflow-hidden outline-none ring-1 ring-(--border-muted)/50 animate-in fade-in zoom-in-95 duration-100"
                                        >
                                            <div
                                                class="px-3 py-2 border-b border-(--border-muted) bg-(--surface-tertiary)/50"
                                            >
                                                <p
                                                    class="text-[10px] font-bold uppercase text-(--text-muted) truncate"
                                                >
                                                    {{ getDisplayName(p) }}
                                                </p>
                                            </div>
                                            <div class="p-1">
                                                <DropdownMenuItem
                                                    v-for="room in meetingStore
                                                        .activeBreakoutSession
                                                        ?.rooms || []"
                                                    :key="room.id"
                                                    @select="
                                                        assignParticipant(
                                                            p,
                                                            room,
                                                        )
                                                    "
                                                    class="flex w-full items-center px-3 py-2 text-xs rounded-lg transition-colors text-(--text-primary) hover:bg-(--color-primary-600) hover:text-white outline-none cursor-pointer"
                                                >
                                                    <Icon
                                                        name="plus"
                                                        size="12"
                                                        class="mr-2 opacity-50"
                                                    />
                                                    Move to {{ room.name }}
                                                </DropdownMenuItem>
                                            </div>
                                        </DropdownMenuContent>
                                    </DropdownMenuPortal>
                                </DropdownMenuRoot>
                            </div>
                        </div>
                        <p class="text-[10px] text-amber-600/70 italic">
                            Join a room to move these participants with you.
                        </p>
                    </div>

                    <!-- Active Rooms -->
                    <div
                        v-for="room in meetingStore.activeBreakoutSession
                            ?.rooms || []"
                        :key="room.id"
                        class="p-3 bg-(--surface-tertiary) border border-(--border-muted) rounded-xl space-y-3"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold">{{
                                room.name
                            }}</span>
                            <div class="flex items-center gap-2">
                                <span
                                    class="text-[10px] px-2 py-0.5 bg-(--surface-muted) rounded-full text-(--text-muted)"
                                >
                                    {{ (room.participants || []).length }}
                                </span>
                                <button
                                    v-if="unassignedParticipants.length > 0"
                                    @click="pullAllUnassigned(room)"
                                    class="p-1 hover:bg-(--surface-muted) rounded flex items-center gap-1 text-[10px] text-(--color-primary-500)"
                                    title="Assign all unassigned to this room"
                                >
                                    <Icon name="user-plus" size="12" />
                                    Add All
                                </button>
                            </div>
                        </div>

                        <!-- Participant Mini-List -->
                        <div class="flex flex-wrap gap-1.5">
                            <div
                                v-for="p in room.participants || []"
                                :key="p.public_id"
                                class="group relative"
                            >
                                <DropdownMenuRoot>
                                    <DropdownMenuTrigger as-child>
                                        <button
                                            class="outline-none group"
                                            :title="getDisplayName(p)"
                                        >
                                            <Avatar
                                                :src="
                                                    p.user?.avatar_url ||
                                                    p.metadata?.avatar_url
                                                "
                                                :fallback="
                                                    getDisplayName(p).charAt(0)
                                                "
                                                :color="p.user?.color"
                                                size="xs"
                                                class="w-6 h-6 border border-(--color-primary-500)/30 group-hover:border-(--color-primary-500) transition-all"
                                            />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuPortal>
                                        <DropdownMenuContent
                                            side="top"
                                            :side-offset="4"
                                            align="start"
                                            class="w-60 bg-(--surface-primary)/95 backdrop-blur-xl border border-(--border-muted) rounded-2xl shadow-[0_20px_50px_rgba(0,0,0,0.3)] z-1060 overflow-hidden outline-none ring-1 ring-(--border-muted)/50 animate-in fade-in zoom-in-95 duration-100"
                                        >
                                            <div
                                                class="px-3 py-2 border-b border-(--border-muted) bg-(--surface-tertiary)/50"
                                            >
                                                <p
                                                    class="text-[10px] font-bold uppercase text-(--text-muted) truncate"
                                                >
                                                    {{ getDisplayName(p) }}
                                                </p>
                                            </div>
                                            <div class="p-1">
                                                <!-- Move to other rooms -->
                                                <template
                                                    v-for="targetRoom in meetingStore
                                                        .activeBreakoutSession
                                                        ?.rooms || []"
                                                    :key="targetRoom.id"
                                                >
                                                    <DropdownMenuItem
                                                        v-if="
                                                            String(
                                                                targetRoom.id,
                                                            ) !==
                                                            String(room.id)
                                                        "
                                                        @select="
                                                            assignParticipant(
                                                                p,
                                                                targetRoom,
                                                            )
                                                        "
                                                        class="flex w-full items-center px-3 py-2 text-xs rounded-lg transition-colors text-(--text-primary) hover:bg-(--color-primary-600) hover:text-white outline-none cursor-pointer"
                                                    >
                                                        <Icon
                                                            name="shuffle"
                                                            size="12"
                                                            class="mr-2 opacity-50"
                                                        />
                                                        Move to
                                                        {{ targetRoom.name }}
                                                    </DropdownMenuItem>
                                                </template>

                                                <!-- Pull back to main -->
                                                <div
                                                    class="my-1 border-t border-(--border-muted)"
                                                ></div>
                                                <DropdownMenuItem
                                                    @select="pullBack(p, room)"
                                                    class="flex w-full items-center px-3 py-2 text-xs rounded-lg transition-colors text-red-500 hover:bg-red-600 hover:text-white outline-none cursor-pointer"
                                                >
                                                    <Icon
                                                        name="arrow-down-left"
                                                        size="12"
                                                        class="mr-2 opacity-50"
                                                    />
                                                    Pull to Main Room
                                                </DropdownMenuItem>
                                            </div>
                                        </DropdownMenuContent>
                                    </DropdownMenuPortal>
                                </DropdownMenuRoot>
                            </div>
                        </div>

                        <!-- Room Actions -->
                        <div
                            class="flex gap-2 pt-1 border-t border-(--border-muted)/50"
                        >
                            <button
                                @click="joinRoom(room)"
                                :disabled="meetingStore.isTransitioningRoom"
                                class="flex-1 h-8 flex items-center justify-center gap-2 text-[10px] font-bold rounded-lg transition-all"
                                :class="[
                                    String(meetingStore.currentRoomId) ===
                                    String(room.id)
                                        ? 'bg-green-500/10 text-green-500 border border-green-500/20 cursor-default'
                                        : 'bg-(--color-primary-600) hover:bg-(--color-primary-500) text-white',
                                ]"
                            >
                                <Icon
                                    v-if="meetingStore.isTransitioningRoom"
                                    name="loader"
                                    size="12"
                                    class="animate-spin"
                                />
                                <Icon
                                    v-else
                                    :name="
                                        String(meetingStore.currentRoomId) ===
                                        String(room.id)
                                            ? 'check'
                                            : 'log-in'
                                    "
                                    size="12"
                                />
                                {{
                                    String(meetingStore.currentRoomId) ===
                                    String(room.id)
                                        ? "Currently Here"
                                        : meetingStore.isInBreakout
                                          ? "Move Here"
                                          : "Join Room"
                                }}
                            </button>
                            <button
                                @click="broadcastToRoom(room)"
                                class="w-8 h-8 flex items-center justify-center bg-(--surface-muted) hover:bg-(--surface-primary) text-(--text-muted) hover:text-(--text-primary) border border-(--border-muted) rounded-lg transition-all"
                                title="Broadcast message"
                            >
                                <Icon name="megaphone" size="12" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-show="!isMinimized"
                class="p-3 bg-(--surface-tertiary)/30 border-t border-(--border-muted) flex items-center justify-between text-[10px] text-(--text-muted)"
            >
                <span>Active for {{ formattedTime }}</span>
                <span class="font-mono">{{
                    meetingStore.formatBreakoutTime
                }}</span>
            </div>
        </div>

        <!-- Modals -->
        <BreakoutBroadcastModal
            v-model:open="showBroadcastModal"
            :room-name="targetBroadcastRoom?.name || 'All Rooms'"
            @send="handleBroadcastSend"
        />
    </div>
</template>

<script setup lang="ts">
import { ref, computed } from "vue";
import { useMeetingStore } from "@/stores/meeting";
import { Icon, Avatar } from "@/components/ui";
import { toast } from "vue-sonner";
import {
    DropdownMenuRoot,
    DropdownMenuTrigger,
    DropdownMenuPortal,
    DropdownMenuContent,
    DropdownMenuItem,
} from "reka-ui";
import BreakoutBroadcastModal from "./BreakoutBroadcastModal.vue";

const meetingStore = useMeetingStore();
const isMinimized = ref(false);
const showBroadcastModal = ref(false);
const targetBroadcastRoom = ref<any>(null);

// --- Draggable Logic ---
const isDragging = ref(false);
const hasBeenDragged = ref(false);
const position = ref({ x: 0, y: 0 });
let startPos = { x: 0, y: 0 };

function startDragging(e: PointerEvent) {
    if ((e.target as HTMLElement).closest("button")) return;

    isDragging.value = true;
    hasBeenDragged.value = true;
    const rect = (e.currentTarget as HTMLElement)
        .closest(".fixed")
        ?.getBoundingClientRect();
    if (rect) {
        position.value = { x: rect.left, y: rect.top };
        startPos = { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    window.addEventListener("pointermove", onDragging);
    window.addEventListener("pointerup", stopDragging);

    // Set pointer capture to ensure we get events even if mouse leaves the element
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
}

function onDragging(e: PointerEvent) {
    if (!isDragging.value) return;
    position.value = {
        x: e.clientX - startPos.x,
        y: e.clientY - startPos.y,
    };
}

function stopDragging() {
    isDragging.value = false;
    window.removeEventListener("pointermove", onDragging);
    window.removeEventListener("pointerup", stopDragging);
}

const unassignedParticipants = computed(() => {
    if (!meetingStore.activeBreakoutSession) return [];

    // IDs of everyone assigned to a room
    const assignedIds = new Set(
        (meetingStore.activeBreakoutSession?.rooms || []).flatMap((r: any) =>
            (r.participants || [])
                .map((p: any) => p.public_id?.toLowerCase())
                .filter(Boolean),
        ),
    );

    // Everyone in the meeting who isn't assigned (and isn't the host themselves)
    return meetingStore.rawParticipants.filter(
        (p) =>
            !assignedIds.has(p.public_id.toLowerCase()) &&
            p.public_id.toLowerCase() !==
                meetingStore.localParticipant?.public_id?.toLowerCase(),
    );
});

const formattedTime = computed(() => {
    if (meetingStore.breakoutTimer > 0) return "Timer active";
    return "No timer set";
});

function getDisplayName(p: any) {
    return (
        p.display_name ||
        p.user?.name ||
        p.metadata?.guest_name ||
        "Participant"
    );
}

async function joinRoom(room: any) {
    try {
        await meetingStore.joinBreakoutRoom(room.id, room.name);
    } catch (e: any) {
        const errorMsg =
            e.response?.data?.message || e.message || "Unknown error";
        toast.error(`Failed to move to ${room.name}`, {
            description: errorMsg,
        });
    }
}

async function returnToMain() {
    try {
        await meetingStore.joinBreakoutRoom(null, "Main Room");
        toast.success("Joined main room");
    } catch (e: any) {
        const errorMsg =
            e.response?.data?.message || e.message || "Unknown error";
        toast.error("Failed to join main room", { description: errorMsg });
    }
}

async function assignParticipant(participant: any, room: any) {
    const name = getDisplayName(participant);
    try {
        await meetingStore.moveParticipant(participant.public_id, room.id);
        toast.success(`${name} moved to ${room.name}`);
    } catch (e: any) {
        const errorMsg =
            e.response?.data?.message || e.message || "Unknown error";
        toast.error(`Could not move ${name} to ${room.name}`, {
            description: errorMsg,
        });
    }
}

async function pullBack(participant: any, room: any) {
    const name = getDisplayName(participant);
    try {
        await meetingStore.moveParticipant(participant.public_id, null);
        toast.success(`${name} pulled to main room`);
    } catch (e: any) {
        const errorMsg =
            e.response?.data?.message || e.message || "Unknown error";
        toast.error(`Could not pull ${name} to main room`, {
            description: errorMsg,
        });
    }
}

async function pullAllUnassigned(room: any) {
    const pids = unassignedParticipants.value;
    if (pids.length === 0) return;

    let successCount = 0;
    let failCount = 0;

    toast.promise(
        Promise.all(
            pids.map(async (p) => {
                try {
                    await meetingStore.moveParticipant(p.public_id, room.id);
                    successCount++;
                } catch (e) {
                    failCount++;
                    throw e; // Promise.all will reject if any fails, but we want to track counts
                }
            }),
        ).catch(() => {}),
        {
            loading: `Moving ${pids.length} participants to ${room.name}...`,
            success: () =>
                `Successfully moved ${successCount} participants to ${room.name}.`,
            error: () =>
                `Moved ${successCount} participants, but ${failCount} failed to move.`,
        },
    );
}

async function adjustTimer(minutes: number) {
    try {
        await meetingStore.updateBreakoutTimer(minutes);
        toast.success(
            `${minutes > 0 ? "Added" : "Removed"} ${Math.abs(minutes)} minute(s).`,
        );
    } catch (e) {
        toast.error("Failed to adjust timer");
    }
}

async function broadcastToRoom(room: any) {
    targetBroadcastRoom.value = room;
    showBroadcastModal.value = true;
}

async function handleBroadcastSend(msg: string) {
    if (!targetBroadcastRoom.value) return;
    try {
        await meetingStore.notifyBreakoutActivity(
            `Host message: ${msg}`,
            targetBroadcastRoom.value.id,
        );
        toast.info(`Message broadcast to ${targetBroadcastRoom.value.name}`);
    } catch (e) {
        toast.error("Failed to send broadcast");
    }
}
</script>
