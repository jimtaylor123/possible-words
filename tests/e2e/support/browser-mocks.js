export const installBrowserMocks = async (page) => {
    await page.addInitScript(() => {
        window.__e2eWindows = [];
        window.open = (...args) => {
            window.__e2eWindows.push(args);
            return null;
        };
        HTMLMediaElement.prototype.play = function play() {
            this.dispatchEvent(new Event('play'));
            return Promise.resolve();
        };
    });
};

export const installNotificationAudioMock = async (page) => {
    await page.addInitScript(() => {
        window.__e2eAudio = { resumes: 0, starts: 0 };
        window.AudioContext = class {
            state = 'suspended';
            currentTime = 0;
            destination = {};
            resume() { this.state = 'running'; window.__e2eAudio.resumes++; return Promise.resolve(); }
            createOscillator() { return { frequency: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect: () => ({ connect: () => ({}) }), start: () => { window.__e2eAudio.starts++; }, stop() {} }; }
            createGain() { return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} } }; }
        };
    });
};
