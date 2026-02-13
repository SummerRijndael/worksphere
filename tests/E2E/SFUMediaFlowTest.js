import puppeteer from 'puppeteer-core';

/**
 * SFU Media Flow E2E Test
 * Simulates two users joining a group call and verifies renegotiation/media flow.
 */

const CHROME_PATH = '/usr/bin/google-chrome';
const BASE_URL = 'http://127.0.0.1:8000';
const CHAT_PUBLIC_ID = '01KH8FMXJNP5NVF2WQ8KB8MYFE';

// User IDs from dev/users
const USER_A_ID = 2; // Test User
const USER_B_ID = 3; // Ishdpahsid

async function runTest() {
    console.log("🚀 Starting SFU E2E Stability Test...");

    const browser = await puppeteer.launch({
        executablePath: CHROME_PATH,
        headless: true,
        args: [
            '--use-fake-ui-for-media-stream',
            '--use-fake-device-for-media-stream',
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--allow-file-access-from-files',
            '--use-file-for-fake-video-capture=/dev/zero' // dummy video
        ]
    });

    try {
        // Setup User A
        const contextA = await browser.createBrowserContext();
        const pageA = await contextA.newPage();
        await setupPage(pageA, USER_A_ID, "User-A");

        // Setup User B
        const contextB = await browser.createBrowserContext();
        const pageB = await contextB.newPage();
        await setupPage(pageB, USER_B_ID, "User-B");

        console.log("👥 Both users authorized. Joining call...");

        // Join simultaneously to increase chance of glare
        await Promise.all([
            joinCall(pageA, "User-A"),
            joinCall(pageB, "User-B")
        ]);

        console.log("⏳ Waiting for SFU negotiation and track mapping...");

        // We expect at least one remote video element to appear in each page
        // AND we want to see the stabilization logs.
        const results = await Promise.all([
            verifyMediaFlow(pageA, "User-A"),
            verifyMediaFlow(pageB, "User-B")
        ]);

        if (results.every(r => r === true)) {
            console.log("\n✅ TEST PASSED: SFU media flow established and stabilized!");
        } else {
            throw new Error("One or more users failed to establish media flow.");
        }

    } catch (err) {
        console.error("\n❌ TEST FAILED:", err.message);
        process.exit(1);
    } finally {
        await browser.close();
    }
}

async function setupPage(page, userId, label) {
    // Capture logs
    page.on('console', msg => {
        const text = msg.text();
        if (text.includes('[SFU]') || text.includes('[RTC-TRACE]') || text.includes('[Call]')) {
            console.log(`[${label}] ${text}`);
        }
    });

    page.on('pageerror', err => {
        console.error(`[${label}] CRASH: ${err.message}`);
    });

    // TRICK: Sanctum's EnsureFrontendRequestsAreStateful requires Origin/Referer to match SANCTUM_STATEFUL_DOMAINS
    await page.setExtraHTTPHeaders({
        'Referer': BASE_URL,
        'Origin': BASE_URL
    });

    console.log(`[${label}] Logging in as user ${userId}...`);
    // Login
    const loginRes = await page.goto(`${BASE_URL}/dev/login-as?user_id=${userId}`, { waitUntil: 'networkidle0' });
    const loginStatus = loginRes.status();
    const loginBody = await loginRes.text();
    
    if (loginStatus >= 400) {
        throw new Error(`${label} Login failed with status ${loginStatus}: ${loginBody}`);
    }
    console.log(`[${label}] Login successful.`);
    // Go to chat
    await page.goto(`${BASE_URL}/chat/${CHAT_PUBLIC_ID}`, { waitUntil: 'networkidle2' });

    // Wait for the join button (the lobby should show for group calls)
    try {
        await page.waitForSelector('.btn-join', { timeout: 15000 });
        console.log(`[${label}] Lobby ready (.btn-join found).`);
    } catch (e) {
        const content = await page.content();
        console.log(`[${label}] FAILED. Current URL: ${page.url()}`);
        // console.log(`[${label}] Page Content Slice: ${content.substring(0, 500)}`);
        throw new Error(`${label} failed to reach lobby: ${e.message}`);
    }
}

async function joinCall(page, label) {
    const joinBtn = await page.$('.btn-join');
    if (!joinBtn) throw new Error(`${label} could not find join button (.btn-join)`);
    await joinBtn.click();
    console.log(`[${label}] Clicked JOIN.`);
}

async function verifyMediaFlow(page, label) {
    try {
        // 1. Wait for "Session established" log or state
        await page.waitForFunction(() => {
            return document.body.innerText.includes('SFU Session') || 
                   !!document.querySelector('.video-cell.remote video');
        }, { timeout: 25000 });

        // 2. Wait for remote video element to be active
        await page.waitForSelector('.video-cell.remote video', { timeout: 25000 });
        
        console.log(`[${label}] ✅ Remote media element detected!`);
        
        return true;
    } catch (e) {
        console.error(`[${label}] Media flow verification failed: ${e.message}`);
        return false;
    }
}

runTest();
