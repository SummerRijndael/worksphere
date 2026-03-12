import type { LastMessage } from "@/types/models/chat";

export function buildChatListPreview(
    message: LastMessage | null | undefined,
    emptyFallback = "",
): string {
    if (!message) return emptyFallback;

    const backendPreview =
        typeof message.preview === "string" ? message.preview.trim() : "";
    if (backendPreview) return backendPreview;

    const content = (message.content ?? "").trim();
    if (content) return content;

    return message.has_media ? "📎 Attachment" : emptyFallback;
}

export function truncateChatPreview(text: string, max = 40): string {
    if (text.length <= max) return text;
    return `${text.slice(0, max)}...`;
}
