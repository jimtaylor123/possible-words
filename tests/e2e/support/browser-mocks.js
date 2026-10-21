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
