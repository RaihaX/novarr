#!/usr/bin/env node

const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');

puppeteer.use(StealthPlugin());

async function fetchPage(url) {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: 'new',
            executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--disable-gpu',
                '--window-size=1920,1080',
            ],
        });

        const page = await browser.newPage();

        // Set a realistic viewport
        await page.setViewport({ width: 1920, height: 1080 });

        // Set a realistic user agent
        await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        // Navigate to the page
        await page.goto(url, {
            waitUntil: 'domcontentloaded',
            timeout: 60000,
        });

        // Wait for Cloudflare challenge to complete (up to 30 seconds)
        // The challenge redirects after solving, so wait for either content or timeout
        let attempts = 0;
        const maxAttempts = 15;

        while (attempts < maxAttempts) {
            await new Promise(resolve => setTimeout(resolve, 2000));

            const html = await page.content();

            // Check if we passed the Cloudflare challenge
            if (!html.includes('Just a moment...') && !html.includes('cf-challenge')) {
                // Also check if we have actual content (not just redirected to error)
                if (html.includes('chr-content') || html.includes('chapter-content') || html.length > 50000) {
                    console.log(html);
                    return;
                }
            }

            attempts++;
        }

        // If we still have the challenge page, output what we have
        const html = await page.content();
        console.log(html);

    } catch (error) {
        console.error('Error:', error.message);
        process.exit(1);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
}

const url = process.argv[2];
if (!url) {
    console.error('Usage: node fetch-page.cjs <url>');
    process.exit(1);
}

fetchPage(url);
