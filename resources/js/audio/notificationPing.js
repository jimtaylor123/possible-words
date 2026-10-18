let audioContext;

const getAudioContext = () => {
    const AudioContext = window.AudioContext ?? window.webkitAudioContext;

    if (!AudioContext) {
        return null;
    }

    audioContext ??= new AudioContext();

    return audioContext;
};

export const unlockNotificationAudio = () => {
    const context = getAudioContext();

    if (context?.state === 'suspended') {
        void context.resume().catch(() => undefined);
    }
};

const playPing = (context) => {
    if (context.state !== 'running') {
        return;
    }

    try {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        const now = context.currentTime;

        oscillator.type = 'triangle';
        oscillator.frequency.setValueAtTime(1046.5, now);
        oscillator.frequency.exponentialRampToValueAtTime(1568, now + 0.12);
        gain.gain.setValueAtTime(0.3, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.28);
        oscillator.connect(gain).connect(context.destination);
        oscillator.start(now);
        oscillator.stop(now + 0.28);
    } catch {
        // Browsers may reject audio during autoplay restrictions; notifications
        // must still be received and displayed when that happens.
    }
};

export const playNotificationPing = () => {
    const context = getAudioContext();

    if (!context) {
        return;
    }

    if (context.state === 'suspended') {
        void context.resume().then(() => playPing(context)).catch(() => undefined);

        return;
    }

    playPing(context);
};
