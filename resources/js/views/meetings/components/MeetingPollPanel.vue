<template>
    <div class="poll-panel">
        <!-- Host: Create/Edit Poll Form -->
        <div v-if="meetingStore.isHost" class="poll-create-section">
            <div v-if="!showCreateForm">
                <button class="poll-create-btn" @click="startCreate">
                    <Icon name="plus-circle" size="16" />
                    New Poll
                </button>
            </div>
            <div v-else class="poll-form">
                <div class="poll-form-header">
                    <span class="poll-label">{{ editingId ? 'Edit Poll' : 'New Poll' }}</span>
                </div>
                <input
                    v-model="newQuestion"
                    class="poll-input"
                    placeholder="Ask something..."
                    maxlength="500"
                    @keydown.enter.prevent
                />
                
                <label class="poll-label mt-2">Options</label>
                <div v-for="(opt, i) in newOptions" :key="i" class="poll-option-row">
                    <input
                        v-model="newOptions[i]"
                        class="poll-input"
                        :placeholder="`Option ${i + 1}`"
                        maxlength="200"
                    />
                    <button
                        v-if="newOptions.length > 2"
                        class="poll-remove-option"
                        @click="newOptions.splice(i, 1)"
                        title="Remove"
                    >
                        <Icon name="x" size="14" />
                    </button>
                </div>
                <button
                    v-if="newOptions.length < 6"
                    class="poll-add-option"
                    @click="newOptions.push('')"
                >
                    + Add option
                </button>

                <!-- Settings -->
                <div class="poll-settings">
                    <label class="poll-setting-item">
                        <input type="checkbox" v-model="newAllowMultiple" />
                        <span>Allow multiple select</span>
                    </label>
                    <label class="poll-setting-item">
                        <input type="checkbox" v-model="newAllowChangeVote" />
                        <span>Allow changing vote</span>
                    </label>
                    <label class="poll-setting-item">
                        <input type="checkbox" v-model="newAnonymous" />
                        <span>Anonymous results</span>
                    </label>
                </div>

                <div class="poll-form-actions">
                    <button class="poll-cancel-btn" @click="cancelCreate">Cancel</button>
                    <button class="poll-launch-btn" :disabled="!canLaunch" @click="savePoll">
                        {{ editingId ? 'Update' : 'Launch' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Divider -->
        <hr v-if="meetingStore.isHost && (meetingStore.activePoll || meetingStore.recentPolls.length)" class="poll-divider" />

        <!-- Active Poll -->
        <div v-if="meetingStore.activePoll" class="poll-card poll-card--active">
            <div class="poll-card-header">
                <div>
                    <span class="poll-live-badge">● LIVE</span>
                    <span v-if="meetingStore.activePoll.anonymous" class="poll-type-badge ml-2">Anonymous</span>
                </div>
                <div class="poll-card-actions">
                    <button
                        v-if="meetingStore.isHost && totalVotes === 0 && !editingId"
                        class="poll-action-icon-btn"
                        @click="startEdit(meetingStore.activePoll)"
                        title="Edit poll"
                    >
                        <Icon name="edit-2" size="14" />
                    </button>
                    <button
                        v-if="meetingStore.isHost"
                        class="poll-end-btn"
                        @click="endActivePoll"
                        title="End poll"
                    >
                        End
                    </button>
                </div>
            </div>
            <p class="poll-question">{{ meetingStore.activePoll.question }}</p>
            <p class="poll-sub-question" v-if="meetingStore.activePoll.allow_multiple">Select one or more options</p>
            
            <div class="poll-options">
                <button
                    v-for="(option, i) in meetingStore.activePoll.options"
                    :key="i"
                    class="poll-option-btn"
                    :class="{
                        'poll-option-btn--selected': selectedIndices.has(i),
                        'poll-option-btn--voted': hasVoted && (meetingStore.activePoll.my_votes || []).includes(i),
                        'poll-option-btn--disabled': hasVoted && !meetingStore.activePoll.allow_change_vote,
                    }"
                    :disabled="hasVoted && !meetingStore.activePoll.allow_change_vote"
                    @click="toggleOption(i)"
                >
                    <div class="poll-option-main">
                        <div class="poll-option-check" v-if="!hasVoted || meetingStore.activePoll.allow_change_vote">
                            <Icon v-if="selectedIndices.has(i)" :name="meetingStore.activePoll.allow_multiple ? 'check-square' : 'check-circle'" size="16" />
                            <Icon v-else :name="meetingStore.activePoll.allow_multiple ? 'square' : 'circle'" size="16" />
                        </div>
                        <span class="poll-option-text">{{ option }}</span>
                    </div>

                    <span v-if="hasVoted" class="poll-option-bar">
                        <span
                            class="poll-option-fill"
                            :style="{ width: votePercent(i) + '%' }"
                        />
                        <span class="poll-result-info">
                             <span v-if="(meetingStore.activePoll.my_votes || []).includes(i)" class="voted-label">Your vote</span>
                             <span class="poll-option-pct">{{ votePercent(i) }}%</span>
                        </span>
                    </span>
                </button>
            </div>

            <div class="poll-card-footer">
                <p class="poll-total-votes">
                    {{ totalVotes }} vote{{ totalVotes !== 1 ? 's' : '' }}
                </p>
                <div v-if="!hasVoted || meetingStore.activePoll.allow_change_vote">
                    <button v-if="hasVoted" class="poll-change-btn" @click="resetSelected">
                        Change Vote
                    </button>
                    <button v-else-if="selectedIndices.size > 0" class="poll-vote-btn" @click="submitVote">
                        Vote
                    </button>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-else-if="!meetingStore.isHost" class="poll-empty">
            <Icon name="bar-chart-2" size="32" class="poll-empty-icon" />
            <p>No active poll right now.</p>
        </div>

        <!-- Past polls -->
        <div v-if="meetingStore.recentPolls.filter(p => !p.is_active).length > 0" class="poll-past">
            <p class="poll-past-header">Past Polls</p>
            <div
                v-for="poll in meetingStore.recentPolls.filter(p => !p.is_active)"
                :key="poll.public_id"
                class="poll-card poll-card--ended"
            >
                <div class="poll-card-header">
                    <span class="poll-question poll-question--sm">{{ poll.question }}</span>
                    <button v-if="meetingStore.isHost" class="poll-delete-btn" @click="deletePoll(poll.public_id)">
                        <Icon name="trash-2" size="12" />
                    </button>
                </div>
                <div class="poll-options">
                    <div v-for="(opt, i) in poll.options" :key="i" class="poll-result-row">
                        <span class="poll-option-text poll-option-text--sm">{{ opt }}</span>
                        <div class="poll-result-bar-wrap">
                            <div class="poll-result-bar" :style="{ width: pollPercent(poll, i) + '%' }" />
                        </div>
                        <span class="poll-result-pct">{{ pollPercent(poll, i) }}%</span>
                    </div>
                </div>
                <p class="poll-total-votes poll-total-votes--sm">
                    {{ poll.vote_counts.reduce((a, b) => a + b, 0) }} votes
                </p>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useMeetingStore } from '@/stores/meeting';
import { toast } from 'vue-sonner';
import api from '@/lib/api';
import { Icon } from '@/components/ui';

const meetingStore = useMeetingStore();

// Form State
const showCreateForm = ref(false);
const editingId = ref<string | null>(null);
const newQuestion = ref('');
const newOptions = ref(['', '']);
const newAllowMultiple = ref(false);
const newAllowChangeVote = ref(false);
const newAnonymous = ref(false);

// Voting State
const selectedIndices = ref<Set<number>>(new Set());
const hasVoted = computed(() => {
    const votes = meetingStore.activePoll?.my_votes || [];
    return votes.length > 0;
});

// Watch for poll changes to clear selections
watch(() => meetingStore.activePoll?.public_id, () => {
    selectedIndices.value.clear();
});

// Sync selectedIndices with store if needed (for changing vote)
function resetSelected() {
    selectedIndices.value.clear();
    meetingStore.activePoll!.my_votes = [];
}

const canLaunch = computed(() =>
    newQuestion.value.trim().length > 0 &&
    newOptions.value.filter(o => o.trim().length > 0).length >= 2
);

function startCreate() {
    editingId.value = null;
    newQuestion.value = '';
    newOptions.value = ['', ''];
    newAllowMultiple.value = false;
    newAllowChangeVote.value = false;
    newAnonymous.value = false;
    showCreateForm.value = true;
}

function startEdit(poll: any) {
    editingId.value = poll.public_id;
    newQuestion.value = poll.question;
    newOptions.value = [...poll.options];
    newAllowMultiple.value = poll.allow_multiple;
    newAllowChangeVote.value = poll.allow_change_vote;
    newAnonymous.value = poll.anonymous;
    showCreateForm.value = true;
}

function cancelCreate() {
    showCreateForm.value = false;
    editingId.value = null;
}

async function savePoll() {
    if (!canLaunch.value || !meetingStore.meeting) return;
    try {
        const payload = {
            question: newQuestion.value.trim(),
            options: newOptions.value.filter(o => o.trim().length > 0),
            allow_multiple: newAllowMultiple.value,
            allow_change_vote: newAllowChangeVote.value,
            anonymous: newAnonymous.value,
        };

        if (editingId.value) {
            await api.patch(`/api/meetings/${meetingStore.meeting.public_id}/polls/${editingId.value}`, payload);
            toast.success('Poll updated.');
        } else {
            await api.post(`/api/meetings/${meetingStore.meeting.public_id}/polls`, payload);
            toast.success('Poll launched.');
        }
        cancelCreate();
    } catch (e: any) {
        toast.error(e.response?.data?.message || 'Failed to save poll.');
    }
}

function toggleOption(i: number) {
    const poll = meetingStore.activePoll;
    if (!poll || (hasVoted.value && !poll.allow_change_vote)) return;

    if (poll.allow_multiple) {
        if (selectedIndices.value.has(i)) {
            selectedIndices.value.delete(i);
        } else {
            selectedIndices.value.add(i);
        }
    } else {
        selectedIndices.value.clear();
        selectedIndices.value.add(i);
    }
}

async function submitVote() {
    const poll = meetingStore.activePoll;
    if (!poll || !meetingStore.meeting || selectedIndices.size === 0) return;
    try {
        await api.post(`/api/meetings/${meetingStore.meeting.public_id}/polls/${poll.public_id}/vote`, {
            option_indexes: Array.from(selectedIndices.value),
        });
        poll.my_votes = Array.from(selectedIndices.value);
        toast.success('Vote submitted.');
    } catch (e: any) {
        toast.error(e.response?.data?.message || 'Failed to submit vote.');
    }
}

async function endActivePoll() {
    const poll = meetingStore.activePoll;
    if (!poll || !meetingStore.meeting) return;
    try {
        await api.post(`/api/meetings/${meetingStore.meeting.public_id}/polls/${poll.public_id}/end`);
    } catch {
        toast.error('Failed to end poll.');
    }
}

async function deletePoll(pollId: string) {
    if (!meetingStore.meeting) return;
    if (!confirm('Delete this poll?')) return;
    try {
        await api.delete(`/api/meetings/${meetingStore.meeting.public_id}/polls/${pollId}`);
        meetingStore.handlePollDeleted(pollId);
        toast.success('Poll deleted.');
    } catch {
        toast.error('Failed to delete poll.');
    }
}

const totalVotes = computed(() => {
    const counts = meetingStore.activePoll?.vote_counts || [];
    if (!Array.isArray(counts)) return Object.values(counts).reduce((s, n) => s + (n as number), 0);
    return counts.reduce((sum, n) => sum + n, 0);
});

function votePercent(i: number): number {
    let counts = meetingStore.activePoll?.vote_counts ?? [];
    if (!Array.isArray(counts)) counts = Object.values(counts);
    const total = counts.reduce((s, n) => s + (n as number), 0);
    if (total === 0) return 0;
    return Math.round(((counts[i] as number) / total) * 100);
}

function pollPercent(poll: any, i: number): number {
    let counts = poll.vote_counts || [];
    if (!Array.isArray(counts)) counts = Object.values(counts);
    const total = counts.reduce((s: number, n: number) => s + n, 0);
    if (total === 0) return 0;
    return Math.round(((counts[i] as number) / total) * 100);
}
</script>

<style scoped>
.poll-panel {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 16px;
    height: 100%;
    overflow-y: auto;
}

.poll-create-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #8ab4f8;
    color: #202124;
    border: none;
    border-radius: 20px;
    padding: 8px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
    justify-content: center;
    transition: background 0.15s;
}
.poll-create-btn:hover { background: #aecbfa; }

.poll-form { display: flex; flex-direction: column; gap: 8px; }
.poll-form-header { display: flex; align-items: center; justify-content: space-between; }

.poll-label { font-size: 11px; color: #9aa0a6; font-weight: 600; text-transform: uppercase; }

.poll-input {
    background: #3c4043;
    border: 1px solid #5f6368;
    border-radius: 8px;
    color: #e8eaed;
    padding: 8px 12px;
    font-size: 13px;
    outline: none;
    transition: border-color 0.15s;
    width: 100%;
    box-sizing: border-box;
}
.poll-input:focus { border-color: #8ab4f8; }

.poll-option-row { display: flex; align-items: center; gap: 6px; }
.poll-remove-option {
    background: none; border: none; cursor: pointer; color: #9aa0a6;
    padding: 4px; display: flex; align-items: center; flex-shrink: 0;
}
.poll-remove-option:hover { color: #e8eaed; }

.poll-add-option {
    background: none; border: 1px dashed #5f6368; border-radius: 8px;
    color: #9aa0a6; padding: 7px; font-size: 12px; cursor: pointer;
    text-align: center; transition: border-color 0.15s, color 0.15s;
}
.poll-add-option:hover { border-color: #8ab4f8; color: #8ab4f8; }

.poll-settings {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 4px;
    background: rgba(0,0,0,0.2);
    padding: 8px;
    border-radius: 8px;
}
.poll-setting-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #e8eaed;
    cursor: pointer;
}
.poll-setting-item input { cursor: pointer; }

.poll-form-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
.poll-cancel-btn {
    background: none; border: 1px solid #5f6368; color: #9aa0a6;
    border-radius: 20px; padding: 6px 16px; font-size: 13px; cursor: pointer;
}
.poll-cancel-btn:hover { background: #3c4043; }
.poll-launch-btn {
    background: #8ab4f8; color: #202124; border: none;
    border-radius: 20px; padding: 6px 18px; font-size: 13px;
    font-weight: 600; cursor: pointer; transition: background 0.15s;
}
.poll-launch-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.poll-launch-btn:not(:disabled):hover { background: #aecbfa; }

.poll-divider { border: none; border-top: 1px solid #3c4043; margin: 0; }

.poll-card {
    background: #3c4043;
    border-radius: 12px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.poll-card--active { border-left: 3px solid #8ab4f8; }
.poll-card--ended { opacity: 0.8; }

.poll-card-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.poll-card-actions { display: flex; align-items: center; gap: 6px; }

.poll-live-badge {
    font-size: 11px; font-weight: 700; color: #81c995;
    text-transform: uppercase; letter-spacing: 0.05em;
}
.poll-type-badge {
    font-size: 9px; background: rgba(255,255,255,0.1); color: #9aa0a6;
    padding: 2px 4px; border-radius: 4px; text-transform: uppercase;
}

.poll-action-icon-btn {
    background: none; border: none; color: #9aa0a6; cursor: pointer; padding: 4px; display: flex;
}
.poll-action-icon-btn:hover { color: #8ab4f8; }

.poll-end-btn {
    font-size: 11px; background: none; border: 1px solid #5f6368;
    color: #9aa0a6; border-radius: 12px; padding: 3px 10px; cursor: pointer;
    transition: all 0.15s;
}
.poll-end-btn:hover { background: #5f6368; color: #e8eaed; }

.poll-question {
    font-size: 14px; color: #e8eaed; font-weight: 600; line-height: 1.4; margin: 0;
}
.poll-sub-question { font-size: 11px; color: #9aa0a6; margin: -4px 0 0; }
.poll-question--sm { font-size: 13px; flex: 1; }

.poll-options { display: flex; flex-direction: column; gap: 6px; }

.poll-option-btn {
    background: #2d2f31; border: 1px solid #5f6368; border-radius: 8px;
    color: #e8eaed; padding: 10px 12px; font-size: 13px; cursor: pointer;
    text-align: left; position: relative; overflow: hidden; transition: background 0.15s, border-color 0.15s;
    display: flex; flex-direction: column; gap: 6px; width: 100%;
}
.poll-option-btn:not(.poll-option-btn--disabled):hover {
    background: #3c4043; border-color: #8ab4f8;
}
.poll-option-btn--selected { border-color: #8ab4f8; background: #353b48; }
.poll-option-btn--voted { border-color: #5f6368; background: #2d2f31; }
.poll-option-btn--disabled { cursor: default; }

.poll-option-main { display: flex; align-items: center; gap: 8px; }
.poll-option-check { color: #8ab4f8; display: flex; }

.poll-option-bar {
    display: flex; flex-direction: column; gap: 4px; width: 100%; margin-top: 2px;
}
.poll-option-fill {
    display: block; height: 6px; background: #8ab4f8;
    border-radius: 3px; transition: width 0.5s ease;
}
.poll-result-info { display: flex; justify-content: space-between; align-items: center; width: 100%; }
.voted-label { font-size: 10px; font-weight: 700; color: #8ab4f8; text-transform: uppercase; }
.poll-option-pct { font-size: 11px; color: #9aa0a6; }

.poll-card-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 4px; }
.poll-total-votes { font-size: 11px; color: #9aa0a6; margin: 0; }
.poll-total-votes--sm { font-size: 10px; margin-top: 4px; text-align: right; }

.poll-vote-btn {
    background: #8ab4f8; color: #202124; border: none; border-radius: 20px;
    padding: 4px 16px; font-size: 12px; font-weight: 700; cursor: pointer;
}
.poll-change-btn {
    background: none; border: 1px solid #5f6368; color: #8ab4f8; border-radius: 20px;
    padding: 4px 12px; font-size: 11px; cursor: pointer;
}

/* Past Polls */
.poll-delete-btn { background: none; border: none; color: #5f6368; cursor: pointer; padding: 4px; flex-shrink: 0; }
.poll-delete-btn:hover { color: #f28b82; }

.poll-result-row { display: flex; align-items: center; gap: 8px; }
.poll-result-bar-wrap { flex: 1; background: #2d2f31; border-radius: 3px; height: 5px; overflow: hidden; }
.poll-result-bar { background: #5f6368; height: 100%; border-radius: 3px; transition: width 0.5s ease; }
.poll-result-pct { font-size: 11px; color: #9aa0a6; width: 30px; text-align: right; }

.poll-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 40px 0; color: #9aa0a6; text-align: center; }
.poll-past-header { font-size: 11px; color: #9aa0a6; font-weight: 600; text-transform: uppercase; margin: 0 0 6px; }

.mt-2 { margin-top: 8px; }
.ml-2 { margin-left:8px; }
</style>
