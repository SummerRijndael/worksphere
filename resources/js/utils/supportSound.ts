let supportMessageAudio: HTMLAudioElement | null = null;
let inboundOfferAudio: HTMLAudioElement | null = null;
let lastSupportSoundAt = 0;

const SUPPORT_SOUND_PATH = '/static/sounds/chat.mp3';
const SUPPORT_OFFER_SOUND_PATH = '/static/sounds/inbound_chat.mp3';
const SUPPORT_SOUND_MIN_INTERVAL_MS = 300;

function getSupportMessageAudio(): HTMLAudioElement | null {
    if (typeof window === 'undefined') {
        return null;
    }

    if (!supportMessageAudio) {
        supportMessageAudio = new Audio(SUPPORT_SOUND_PATH);
        supportMessageAudio.preload = 'auto';
    }

    return supportMessageAudio;
}

function getInboundOfferAudio(): HTMLAudioElement | null {
    if (typeof window === 'undefined') {
        return null;
    }

    if (!inboundOfferAudio) {
        inboundOfferAudio = new Audio(SUPPORT_OFFER_SOUND_PATH);
        inboundOfferAudio.preload = 'auto';
        inboundOfferAudio.loop = true;
    }

    return inboundOfferAudio;
}

export function playSupportMessageSound(): boolean {
    const now = Date.now();
    if (now - lastSupportSoundAt < SUPPORT_SOUND_MIN_INTERVAL_MS) {
        return false;
    }

    const audio = getSupportMessageAudio();
    if (!audio) {
        return false;
    }

    lastSupportSoundAt = now;
    audio.currentTime = 0;

    audio.play().catch((error) => {
        console.debug('[SupportChat][Sound] Message sound playback was blocked or failed.', error);
    });

    return true;
}

export function startSupportOfferLoop(): boolean {
    const audio = getInboundOfferAudio();
    if (!audio) {
        return false;
    }

    audio.loop = true;
    audio.currentTime = 0;
    audio.play().catch((error) => {
        console.debug('[SupportChat][Sound] Offer loop playback was blocked or failed.', error);
    });

    return true;
}

export function stopSupportOfferLoop(): void {
    const audio = getInboundOfferAudio();
    if (!audio) {
        return;
    }

    audio.pause();
    audio.currentTime = 0;
}
