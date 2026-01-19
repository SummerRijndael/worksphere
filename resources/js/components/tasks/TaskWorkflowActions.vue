<script setup lang="ts">
import { ref, computed } from "vue";
import { useAuthStore } from "@/stores/auth";
import { Button, Modal, Textarea } from "@/components/ui";
import {
    CheckCircle,
    XCircle,
    ArrowRight,
    Play,
    Archive,
    Pause,
    RefreshCw,
} from "lucide-vue-next";
import axios from "axios";

const props = defineProps<{
    task: any;
}>();

const emit = defineEmits(["task-updated", "error"]);

const authStore = useAuthStore();
const noteModalOpen = ref(false);
const submitting = ref(false);
const actionNote = ref("");
const currentAction = ref<string | null>(null);

// Permission/State helpers
const canSubmitForQa = computed(() => {
    return (
        props.task.status === "in_progress" || props.task.status === "on_hold"
    );
});

const canQaReview = computed(() => {
    return props.task.status === "submitted" || props.task.status === "in_qa";
});

const canPmReview = computed(() => {
    return props.task.status === "pm_review";
});

const canClientReview = computed(() => {
    return props.task.status === "sent_to_client";
});

// Computed list of actions based on status
const availableActions = computed(() => {
    const actions = [];
    // Status can be a string OR an object with {value, label, color}
    const rawStatus =
        typeof props.task.status === "object" && props.task.status?.value
            ? props.task.status.value
            : props.task.status;

    // Operator Actions
    if (rawStatus === "open" || rawStatus === "draft") {
        actions.push({
            id: "start",
            label: "Start Task",
            icon: Play,
            variant: "primary",
        });
    }

    if (rawStatus === "in_progress") {
        // Check if checklist is complete
        const checklist = props.task?.checklist || [];
        const checklistComplete =
            checklist.length === 0 ||
            checklist.every(
                (item: any) =>
                    item.status === "done" ||
                    item.completed ||
                    item.is_completed,
            );

        actions.push({
            id: "submit_qa",
            label: "Submit for QA",
            icon: ArrowRight,
            variant: "primary",
            disabled: !checklistComplete,
            tooltip: !checklistComplete
                ? "Complete all checklist items first"
                : undefined,
        });
        actions.push({
            id: "hold",
            label: "Put on Hold",
            icon: Pause,
            variant: "secondary",
        });
    }

    if (rawStatus === "on_hold") {
        actions.push({
            id: "start",
            label: "Resume",
            icon: Play,
            variant: "primary",
        });
    }

    // QA Actions
    if (rawStatus === "submitted") {
        actions.push({
            id: "start_qa",
            label: "Start QA Review",
            icon: Play,
            variant: "primary",
        });
    }

    if (rawStatus === "in_qa") {
        actions.push({
            id: "qa_approve",
            label: "Approve (To PM)",
            icon: CheckCircle,
            variant: "success",
        });
        actions.push({
            id: "qa_reject",
            label: "Reject (To Operator)",
            icon: XCircle,
            variant: "danger",
        });
    }

    // PM Actions
    if (rawStatus === "pm_review") {
        actions.push({
            id: "pm_approve",
            label: "Approve (To Client)",
            icon: CheckCircle,
            variant: "success",
        });
        actions.push({
            id: "pm_reject",
            label: "Reject (To QA)",
            icon: XCircle,
            variant: "danger",
        });
    }

    // Client/Final Actions
    if (rawStatus === "sent_to_client") {
        actions.push({
            id: "client_approve",
            label: "Client Approved",
            icon: CheckCircle,
            variant: "success",
        });
        actions.push({
            id: "client_reject",
            label: "Client Rejected",
            icon: XCircle,
            variant: "danger",
        });
    }

    if (rawStatus === "client_approved") {
        actions.push({
            id: "complete",
            label: "Mark Completed",
            icon: CheckCircle,
            variant: "primary",
        });
    }

    if (rawStatus === "rejected" || rawStatus === "client_rejected") {
        actions.push({
            id: "restart",
            label: "Restart Task",
            icon: RefreshCw,
            variant: "primary",
        });
    }

    return actions;
});

const requiresNote = (actionId: string) => {
    return [
        "qa_reject",
        "pm_reject",
        "client_reject",
        "client_approve",
        "submit_qa",
        "pm_approve",
        "hold",
    ].includes(actionId);
};

// Check if all checklist items are completed
const isChecklistComplete = computed(() => {
    const checklist = props.task?.checklist || [];
    if (checklist.length === 0) return true; // No checklist = can submit
    return checklist.every(
        (item: any) =>
            item.status === "done" || item.completed || item.is_completed,
    );
});

const initiateAction = (actionId: string) => {
    // Check checklist completion for submit_qa
    if (actionId === "submit_qa" && !isChecklistComplete.value) {
        emit(
            "error",
            "All checklist items must be completed before submitting for QA",
        );
        return;
    }

    if (requiresNote(actionId)) {
        currentAction.value = actionId;
        actionNote.value = "";
        noteModalOpen.value = true;
    } else {
        performAction(actionId);
    }
};

const confirmModalAction = () => {
    if (currentAction.value) {
        performAction(currentAction.value, actionNote.value);
        noteModalOpen.value = false;
        currentAction.value = null;
    }
};

const performAction = async (actionId: string, note?: string) => {
    submitting.value = true;
    try {
        const teamId =
            props.task.project?.team_id || props.task.project?.team?.id;
        const projectId = props.task.project_id || props.task.project?.id;
        const taskId = props.task.public_id || props.task.id;

        if (!teamId || !projectId || !taskId) {
            console.error("Missing context IDs", props.task);
            throw new Error("Missing context IDs");
        }

        const baseUrl = `/api/teams/${teamId}/projects/${projectId}/tasks/${taskId}`;
        let endpoint = "";
        let body: any = { notes: note };

        switch (actionId) {
            case "start":
                endpoint = "/start";
                break;
            case "hold":
                endpoint = "/toggle-hold";
                break;
            case "submit_qa":
                endpoint = "/submit-qa";
                break;
            case "start_qa":
                endpoint = "/start-qa-review";
                break;
            case "qa_approve":
                endpoint = "/complete-qa-review";
                body = { approved: true, notes: note };
                break;
            case "qa_reject":
                endpoint = "/complete-qa-review";
                body = { approved: false, notes: note };
                break;
            case "pm_approve":
                endpoint = "/send-to-client"; // Transition PmReview -> SentToClient
                // OR if PM just approves without client? Requirements say PM -> Client.
                // Backend endpoint sendToClient handles PM approval implicitly.
                body = { message: note };
                break;
            case "pm_reject":
                // PM Reject usually sends back to QA or Operator.
                // Backend might have generic returnToProgress or we interpret sendToClient failure?
                // Wait, TaskController doesn't have explicit 'pmReject'.
                // We can use 'return-to-progress' which handles rejections.
                endpoint = "/return-to-progress";
                break;
            case "client_approve":
                endpoint = "/client-approve";
                break;
            case "client_reject":
                endpoint = "/client-reject";
                body = { reason: note };
                break;
            case "complete":
                endpoint = "/complete";
                break;
            case "restart":
                endpoint = "/return-to-progress";
                break;
        }

        if (endpoint) {
            const res = await axios.post(`${baseUrl}${endpoint}`, body);
            emit("task-updated", res.data.task);
        }
    } catch (error: any) {
        console.error("Action failed", error);
        emit("error", error.response?.data?.message || "Action failed");
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <div v-if="availableActions.length > 0" class="flex flex-wrap gap-2">
        <Button
            v-for="action in availableActions"
            :key="action.id"
            :variant="
                action.variant === 'primary'
                    ? 'primary'
                    : action.variant === 'danger'
                      ? 'danger'
                      : action.variant === 'secondary'
                        ? 'secondary'
                        : 'outline'
            "
            :class="[
                action.variant === 'success'
                    ? 'bg-emerald-600 hover:bg-emerald-700 text-white border-emerald-600'
                    : '',
            ]"
            size="sm"
            @click="initiateAction(action.id)"
            :disabled="submitting || action.disabled"
            :title="action.tooltip"
        >
            <component :is="action.icon" class="w-4 h-4 mr-2" />
            {{ action.label }}
        </Button>

        <Modal
            :open="noteModalOpen"
            @update:open="noteModalOpen = $event"
            :title="
                currentAction === 'hold'
                    ? 'Put Task on Hold'
                    : 'Add Note / Comment'
            "
            :description="
                currentAction === 'hold'
                    ? 'Please provide a reason for putting this task on hold.'
                    : 'Please add a note or reason for this action.'
            "
            size="sm"
        >
            <Textarea
                v-model="actionNote"
                :placeholder="
                    currentAction === 'hold'
                        ? 'Enter reason for hold...'
                        : 'Enter details here...'
                "
                rows="3"
                class="w-full"
            />
            <template #footer>
                <Button variant="ghost" @click="noteModalOpen = false"
                    >Cancel</Button
                >
                <Button
                    @click="confirmModalAction"
                    :disabled="submitting || !actionNote"
                >
                    {{ currentAction === "hold" ? "Put on Hold" : "Confirm" }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
