import { test as base, expect } from '@playwright/test';

const namespaceFor = (testInfo) => `${process.env.E2E_RUN_ID ?? 'local'}-${testInfo.testId}`
    .toLowerCase().replaceAll(/[^a-z0-9-]/g, '-').slice(0, 80);

export const test = base.extend({
    e2e: async ({ page }, use, testInfo) => {
        const namespace = namespaceFor(testInfo);
        const reset = async () => {
            const response = await page.request.post('/testing/e2e/reset', { form: { namespace } });
            expect(response.ok()).toBeTruthy();
        };
        const setup = async (scenario = 'word') => {
            const response = await page.request.post('/testing/e2e/setup', { form: { namespace, scenario } });
            expect(response.ok()).toBeTruthy();
            return response.json();
        };
        const login = async (email, isAdmin = false) => {
            const response = await page.request.post('/testing/login', { form: { email, name: 'E2E User', is_admin: isAdmin ? '1' : '0' } });
            expect(response.ok()).toBeTruthy();
        };
        const logout = async () => {
            const response = await page.request.post('/testing/logout');
            expect(response.ok()).toBeTruthy();
        };
        await use({ namespace, reset, setup, login, logout });
        await reset();
    },
});

export { expect };
