import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    CHIME_GAIN,
    isChatSoundEnabled,
    playChatNotification,
    setChatSoundEnabled,
} from '@/lib/chat-sound';

class FakeAudioContext {
    static instances = 0;

    static ramps: number[] = [];

    state = 'running';

    currentTime = 0;

    destination = {};

    started: number[] = [];

    constructor() {
        FakeAudioContext.instances++;
    }

    resume = vi.fn(async () => {});

    createGain() {
        const gain = {
            gain: {
                value: 0,
                exponentialRampToValueAtTime: vi.fn((value: number) => {
                    FakeAudioContext.ramps.push(value);
                }),
            },
            connect: vi.fn(),
        };

        return gain;
    }

    createOscillator() {
        const osc = {
            type: 'sine',
            frequency: { value: 0 },
            connect: vi.fn(),
            start: (at: number) => {
                this.started.push(at);
            },
            stop: vi.fn(),
        };

        return osc;
    }
}

describe('chat sound', () => {
    beforeEach(() => {
        window.localStorage.clear();
        FakeAudioContext.instances = 0;
        vi.stubGlobal('AudioContext', FakeAudioContext);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('is enabled by default and persists the preference', () => {
        expect(isChatSoundEnabled()).toBe(true);

        setChatSoundEnabled(false);
        expect(isChatSoundEnabled()).toBe(false);
        expect(window.localStorage.getItem('arabut-chat-sound')).toBe('off');

        setChatSoundEnabled(true);
        expect(isChatSoundEnabled()).toBe(true);
    });

    it('plays two notes through one shared audio context', () => {
        expect(playChatNotification()).toBe(true);
        expect(playChatNotification()).toBe(true);
        expect(FakeAudioContext.instances).toBe(1);
    });

    it('ramps to the configured chime gain', () => {
        FakeAudioContext.ramps = [];

        expect(playChatNotification()).toBe(true);
        expect(CHIME_GAIN).toBeGreaterThanOrEqual(0.3);
        expect(FakeAudioContext.ramps[0]).toBe(CHIME_GAIN);
    });

    it('reports false when Web Audio is unavailable', () => {
        vi.stubGlobal('AudioContext', undefined);
        expect(playChatNotification()).toBe(false);
    });
});
