/**
 * Soft two-note chime for new assistant messages, synthesised with the Web
 * Audio API so no asset is shipped. The preference persists per browser.
 */

const STORAGE_KEY = 'arabut-chat-sound';

type AudioContextCtor = new () => AudioContext;

function audioContextCtor(): AudioContextCtor | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const w = window as Window & {
        AudioContext?: AudioContextCtor;
        webkitAudioContext?: AudioContextCtor;
    };

    return w.AudioContext ?? w.webkitAudioContext ?? null;
}

let sharedContext: AudioContext | null = null;

export function isChatSoundEnabled(): boolean {
    try {
        return window.localStorage.getItem(STORAGE_KEY) !== 'off';
    } catch {
        return true;
    }
}

export function setChatSoundEnabled(enabled: boolean): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, enabled ? 'on' : 'off');
    } catch {
        // Private mode or storage quota: the in-memory state still applies.
    }
}

/**
 * Play the chime. Returns false when audio is unavailable (no Web Audio, or
 * the browser blocked playback before a user gesture).
 */
export function playChatNotification(): boolean {
    const Ctor = audioContextCtor();

    if (Ctor === null) {
        return false;
    }

    try {
        sharedContext ??= new Ctor();
        const context = sharedContext;

        if (context.state === 'suspended') {
            void context.resume();
        }

        const now = context.currentTime;
        const master = context.createGain();
        master.gain.value = 0.0001;
        master.connect(context.destination);

        // Gentle attack/decay envelope, two notes a fourth apart.
        master.gain.exponentialRampToValueAtTime(0.12, now + 0.02);
        master.gain.exponentialRampToValueAtTime(0.0001, now + 0.55);

        for (const [frequency, offset] of [
            [659.25, 0],
            [880, 0.12],
        ] as const) {
            const osc = context.createOscillator();
            osc.type = 'sine';
            osc.frequency.value = frequency;
            osc.connect(master);
            osc.start(now + offset);
            osc.stop(now + 0.6);
        }

        return true;
    } catch {
        return false;
    }
}
