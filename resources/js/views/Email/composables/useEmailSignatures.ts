import { ref } from 'vue';

export interface Signature {
    id: string;
    name: string;
    content: string; // HTML allowed
}

const defaultSignatures: Signature[] = [
    {
        id: 'sig-1',
        name: 'Default',
        content: '<p>Best regards,<br><strong>Alice Smith</strong><br>Product Manager<br>CoreSync Inc.</p>'
    },
    {
        id: 'sig-2',
        name: 'Short',
        content: '<p>Thanks,<br>Alice</p>'
    }
];

const signatures = ref<Signature[]>([...defaultSignatures]);
const selectedSignatureId = ref<string>('sig-1');

function getSignatureById(id: string): Signature | undefined {
    return signatures.value.find(s => s.id === id);
}

export function useEmailSignatures() {
    function addSignature(signature: { name: string; content: string }) {
        const newSig = {
            id: Math.random().toString(36).substr(2, 9),
            ...signature,
        };
        signatures.value.push(newSig);
        return newSig;
    }

    function updateSignature(id: string, updates: { name: string; content: string }) {
        const index = signatures.value.findIndex((s) => s.id === id);
        if (index !== -1) {
            signatures.value[index] = { ...signatures.value[index], ...updates };
        }
    }

    function deleteSignature(id: string) {
        signatures.value = signatures.value.filter((s) => s.id !== id);
    }

    return {
        signatures,
        selectedSignatureId,
        getSignatureById,
        addSignature,
        updateSignature,
        deleteSignature,
    };
}
