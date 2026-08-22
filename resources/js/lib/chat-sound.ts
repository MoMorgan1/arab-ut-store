/**
 * Soft two-note chime for new assistant messages, synthesised with the Web
 * Audio API so no asset is shipped. The preference persists per browser.
 */

const STORAGE_KEY = 'arabut-chat-sound';

/**
 * Peak gain of the chime (0-1). Raised 0.12 -> 0.35 -> 0.8 after the owner's
 * phone tests; a compressor on the output keeps it from clipping.
 */
export const CHIME_GAIN = 0.8;

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

        if (typeof context.createDynamicsCompressor === 'function') {
            const limiter = context.createDynamicsCompressor();
            limiter.threshold.value = -6;
            limiter.knee.value = 6;
            limiter.ratio.value = 12;
            limiter.attack.value = 0.002;
            limiter.release.value = 0.12;
            master.connect(limiter);
            limiter.connect(context.destination);
        } else {
            master.connect(context.destination);
        }

        // Gentle attack/decay envelope, two notes a fourth apart.
        master.gain.exponentialRampToValueAtTime(CHIME_GAIN, now + 0.02);
        master.gain.exponentialRampToValueAtTime(0.0001, now + 0.7);

        // Two notes a fourth apart plus a quieter octave-down body so the
        // chime carries on small phone speakers.
        for (const [frequency, offset, type, level] of [
            [659.25, 0, 'sine', 1],
            [880, 0.12, 'sine', 1],
            [329.63, 0, 'triangle', 0.35],
        ] as const) {
            const osc = context.createOscillator();
            const voice = context.createGain();
            voice.gain.value = level;
            osc.type = type;
            osc.frequency.value = frequency;
            osc.connect(voice);
            voice.connect(master);
            osc.start(now + offset);
            osc.stop(now + 0.75);
        }

        return true;
    } catch {
        return false;
    }
}
