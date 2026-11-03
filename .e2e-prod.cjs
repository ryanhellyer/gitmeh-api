#!/usr/bin/env node
/*
 * End-to-end CORS check against the PRODUCTION deployment (ai.hellyer.kiwi).
 *
 * Simulates what the browser does when https://translate.hellyer.kiwi calls
 * POST /v1/translations from the page:
 *   1. an OPTIONS preflight with Origin + Access-Control-Request-* headers
 *   2. the POST itself
 * and fails unless both responses carry Access-Control-Allow-Origin for the
 * page's origin.
 *
 * Usage: node .e2e-prod.cjs
 */
'use strict';

const API = process.env.API_URL || 'https://ai.hellyer.kiwi/v1/translations';
const ORIGIN = process.env.ORIGIN || 'https://translate.hellyer.kiwi';

let failed = false;

function fail(message) {
    failed = true;
    console.error(`FAIL ${message}`);
}

async function main() {
    console.log(`Preflight   OPTIONS ${API}  (Origin: ${ORIGIN})`);
    const preflight = await fetch(API, {
        method: 'OPTIONS',
        headers: {
            Origin: ORIGIN,
            'Access-Control-Request-Method': 'POST',
            'Access-Control-Request-Headers': 'content-type',
        },
    });

    const allowOrigin = preflight.headers.get('access-control-allow-origin');
    const allowMethods = preflight.headers.get('access-control-allow-methods');
    const allowHeaders = preflight.headers.get('access-control-allow-headers');

    console.log(`            -> HTTP ${preflight.status}`);
    console.log(`            -> Access-Control-Allow-Origin: ${allowOrigin}`);
    console.log(`            -> Access-Control-Allow-Methods: ${allowMethods}`);
    console.log(`            -> Access-Control-Allow-Headers: ${allowHeaders}`);

    if (preflight.status !== 200 && preflight.status !== 204) {
        fail(`preflight returned HTTP ${preflight.status} (expected 200 or 204)`);
    }
    if (allowOrigin !== ORIGIN) {
        fail(`preflight Access-Control-Allow-Origin was "${allowOrigin}", expected "${ORIGIN}"`);
    }

    console.log(`\nPOST        ${API}`);
    const response = await fetch(API, {
        method: 'POST',
        headers: {
            Origin: ORIGIN,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            from: 'English',
            to: 'German',
            text: 'Please send me the report when you have finished it.',
        }),
    });

    const postAllowOrigin = response.headers.get('access-control-allow-origin');
    console.log(`            -> HTTP ${response.status}`);
    console.log(`            -> Access-Control-Allow-Origin: ${postAllowOrigin}`);

    const body = await response.json().catch(() => null);
    console.log(`            -> ${JSON.stringify(body)}`);

    if (postAllowOrigin !== ORIGIN) {
        fail(`POST Access-Control-Allow-Origin was "${postAllowOrigin}", expected "${ORIGIN}"`);
    }
    if (!response.ok) {
        fail(`POST returned HTTP ${response.status}`);
    }
    if (!body || typeof body.translation !== 'string' || body.translation.trim() === '') {
        fail(`POST did not return a translation (got: ${JSON.stringify(body)})`);
    } else {
        console.log(`\nGerman translation: ${body.translation}`);
    }

    if (failed) {
        console.error('\nE2E FAILED');
        process.exit(1);
    }
    console.log('\nE2E PASSED');
}

main().catch((error) => {
    console.error(`FAIL ${error.message}`);
    process.exit(1);
});
