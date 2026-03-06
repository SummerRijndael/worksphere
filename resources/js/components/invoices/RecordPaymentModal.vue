<script setup lang="ts">
import { ref, reactive } from "vue";
import { Modal, Button, Input, Checkbox } from "@/components/ui";
import { DollarSign } from "lucide-vue-next";
import axios from "axios";
import { toast } from "vue-sonner";

const props = defineProps({
    open: Boolean,
    invoice: {
        type: Object,
        required: true,
    },
    teamId: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(["update:open", "success"]);

const isProcessing = ref(false);
const paymentForm = reactive({
    date: new Date().toISOString().split("T")[0],
    note: "",
    proof: null as File | null,
    send_receipt: true,
});

const handleFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        if (target.files[0].size > 10 * 1024 * 1024) {
            toast.error("File size exceeds 10MB limit");
            target.value = "";
            return;
        }
        paymentForm.proof = target.files[0];
    }
};

const submitPayment = async () => {
    try {
        isProcessing.value = true;
        const formData = new FormData();
        formData.append("date", paymentForm.date);
        if (paymentForm.note) formData.append("note", paymentForm.note);
        if (paymentForm.proof) formData.append("proof", paymentForm.proof);
        formData.append("send_receipt", paymentForm.send_receipt ? "1" : "0");

        const response = await axios.post(
            `/api/teams/${props.teamId}/invoices/${props.invoice.public_id}/record-payment`,
            formData,
            {
                headers: {
                    "Content-Type": "multipart/form-data",
                },
            },
        );

        toast.success(
            paymentForm.send_receipt
                ? "Payment recorded and receipt sent to client"
                : "Payment recorded successfully",
        );

        emit("success", response.data.data);
        closeModal();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to record payment");
    } finally {
        isProcessing.value = false;
    }
};

const closeModal = () => {
    emit("update:open", false);
    // Reset form after a short delay to avoid flicker during close animation
    setTimeout(() => {
        paymentForm.note = "";
        paymentForm.proof = null;
        paymentForm.date = new Date().toISOString().split("T")[0];
        paymentForm.send_receipt = true;
    }, 300);
};
</script>

<template>
    <Modal
        :open="open"
        @update:open="$emit('update:open', $event)"
        title="Record Payment"
        description="Record a manual payment for this invoice."
        size="md"
    >
        <div class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-1">
                    <label
                        class="text-sm font-medium text-(--text-secondary)"
                    >
                        Payment Date
                    </label>
                    <Input v-model="paymentForm.date" type="date" required />
                </div>

                <div class="space-y-1">
                    <label
                        class="text-sm font-medium text-(--text-secondary)"
                    >
                        Amount
                    </label>
                    <div class="relative">
                        <Input
                            :model-value="invoice.total"
                            disabled
                            class="bg-(--surface-secondary)"
                        >
                            <template #prefix>
                                <span
                                    class="text-xs font-semibold text-(--text-muted)"
                                    >{{ invoice.currency }}</span
                                >
                            </template>
                        </Input>
                    </div>
                </div>
            </div>

            <div class="space-y-1">
                <label class="text-sm font-medium text-(--text-secondary)">
                    Proof of Payment (Optional)
                </label>
                <div class="flex items-center justify-center w-full">
                    <label
                        class="flex flex-col items-center justify-center w-full h-32 border-2 border-(--border-default) border-dashed rounded-lg cursor-pointer bg-(--surface-primary) hover:bg-(--surface-secondary) transition-colors"
                    >
                        <div
                            class="flex flex-col items-center justify-center pt-5 pb-6"
                        >
                            <svg
                                class="w-8 h-8 mb-4 text-(--text-muted)"
                                aria-hidden="true"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 20 16"
                            >
                                <path
                                    stroke="currentColor"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M13 13h3a3 3 0 0 0 0-6h-.025A5.56 5.56 0 0 0 16 6.5 5.5 5.5 0 0 0 5.207 5.021C5.137 5.017 5.071 5 5 5a4 4 0 0 0 0 8h2.167M10 15V6m0 0L8 8m2-2 2 2"
                                />
                            </svg>
                            <p
                                class="mb-2 text-sm text-(--text-secondary)"
                            >
                                <span class="font-semibold"
                                    >Click to upload</span
                                >
                                or drag and drop
                            </p>
                            <p class="text-xs text-(--text-muted)">
                                PDF, JPG, PNG (Max 10MB)
                            </p>
                        </div>
                        <input
                            type="file"
                            class="hidden"
                            accept=".pdf,.jpg,.jpeg,.png"
                            @change="handleFileChange"
                        />
                    </label>
                </div>
                <div
                    v-if="paymentForm.proof"
                    class="flex items-center gap-2 mt-2 p-2 bg-(--surface-secondary) rounded-md"
                >
                    <span
                        class="text-xs text-(--text-primary) truncate flex-1"
                        >{{ paymentForm.proof.name }}</span
                    >
                    <Button
                        variant="ghost"
                        size="xs"
                        @click="paymentForm.proof = null"
                        >Remove</Button
                    >
                </div>
            </div>

            <div class="space-y-1">
                <label class="text-sm font-medium text-(--text-secondary)">
                    Note (Optional)
                </label>
                <textarea
                    v-model="paymentForm.note"
                    rows="3"
                    class="flex w-full rounded-md border border-(--border-default) bg-(--surface-primary) px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-(--interactive-primary) placeholder:text-(--text-muted)"
                    placeholder="Enter payment details (e.g., Check #1234)"
                ></textarea>
            </div>

            <div class="flex items-center space-x-2 py-2">
                <Checkbox
                    id="send_receipt"
                    v-model="paymentForm.send_receipt"
                    label="Send payment receipt to client"
                    description="The client will receive a PDF receipt via email"
                />
            </div>
        </div>

        <template #footer>
            <Button
                variant="ghost"
                @click="closeModal"
                :disabled="isProcessing"
            >
                Cancel
            </Button>
            <Button
                @click="submitPayment"
                :loading="isProcessing"
                variant="primary"
            >
                <DollarSign class="w-4 h-4 mr-2" />
                Record Payment
            </Button>
        </template>
    </Modal>
</template>
