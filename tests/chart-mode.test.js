const test = require('node:test');
const assert = require('node:assert/strict');
const { isSafariBrowser, shouldUseSimpleDetailChart } = require('../public/assets/chart-mode.js');

test('detects macOS Safari as Safari', () => {
    const userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15';

    assert.equal(isSafariBrowser(userAgent), true);
    assert.equal(shouldUseSimpleDetailChart(userAgent, 576), true);
});

test('detects iOS Safari as Safari', () => {
    const userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Mobile/15E148 Safari/604.1';

    assert.equal(isSafariBrowser(userAgent), true);
    assert.equal(shouldUseSimpleDetailChart(userAgent, 576), true);
});

test('does not misdetect Chrome desktop as Safari', () => {
    const userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36';

    assert.equal(isSafariBrowser(userAgent), false);
    assert.equal(shouldUseSimpleDetailChart(userAgent, 576), false);
});

test('does not misdetect Chrome on iOS as Safari', () => {
    const userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/135.0.7049.53 Mobile/15E148 Safari/604.1';

    assert.equal(isSafariBrowser(userAgent), false);
    assert.equal(shouldUseSimpleDetailChart(userAgent, 576), false);
});
