'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'js', 'PandaBangumi.js'), 'utf8');
const fixtures = JSON.parse(
    fs.readFileSync(path.join(__dirname, 'fixtures', 'subject-card-types.json'), 'utf8')
);

class FakeElement {
    constructor(tagName = 'div') {
        this.tagName = tagName.toUpperCase();
        this.children = [];
        this.dataset = {};
        this.attributes = {};
        this.className = '';
        this.textContent = '';
        this.isConnected = true;
        this.classList = {
            add: (...names) => {
                this.className = [this.className, ...names].filter(Boolean).join(' ');
            }
        };
    }

    appendChild(child) {
        this.children.push(child);
        return child;
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    addEventListener(name, callback) {
        this.listeners = { ...(this.listeners || {}), [name]: callback };
    }
}

class FakeButtonElement extends FakeElement {
    constructor() {
        super('button');
    }
}

const sandbox = {
    AbortController,
    HTMLButtonElement: FakeButtonElement,
    URL,
    clearTimeout,
    console: { error() {}, log() {} },
    document: {
        addEventListener() {},
        createElement(tagName) {
            return tagName === 'button' ? new FakeButtonElement() : new FakeElement(tagName);
        },
        readyState: 'loading'
    },
    setTimeout,
    window: {
        PandaBangumi: {},
        bgmBase: 'https://blog.example/PandaBangumi',
        location: {
            href: 'https://blog.example/post',
            origin: 'https://blog.example'
        }
    }
};

vm.createContext(sandbox);
vm.runInContext(source, sandbox, { filename: 'PandaBangumi.js' });
assert.equal(typeof sandbox.window.PandaBangumi.init, 'function');
assert.equal(typeof sandbox.window.PandaBangumi.requestQueue.enqueue, 'function');
const normalize = vm.runInContext('normalizeSubjectCardData', sandbox);
const normalizeCollection = vm.runInContext('normalizeCollectionType', sandbox);
const isCompletedCollection = vm.runInContext('isCompletedCollectionType', sandbox);
const shouldShowProgress = vm.runInContext('shouldShowPosterProgress', sandbox);
const pickMusicObiColor = vm.runInContext('pickMusicObiColor', sandbox);
const hslToRgb = vm.runInContext('hslToRgb', sandbox);
const relativeLuminance = vm.runInContext('relativeLuminance', sandbox);
const createRequestQueue = vm.runInContext('createRequestQueue', sandbox);
const abortPendingRequests = vm.runInContext('abortPendingRequests', sandbox);
const createRequestController = vm.runInContext('createRequestController', sandbox);
const centerCalendarTab = vm.runInContext('centerCalendarTab', sandbox);
const fetchJson = vm.runInContext('fetchJson', sandbox);
const loadImageResource = vm.runInContext('loadImageResource', sandbox);
const renderCardError = vm.runInContext('renderCardError', sandbox);

function normalized(name) {
    const fixture = fixtures[name];
    return JSON.parse(JSON.stringify(normalize(fixture, fixture.id)));
}

const anime = normalized('anime');
assert.equal(anime.typeKey, 'anime');
assert.equal(anime.typeLabel, '动画');
assert.equal(anime.primaryMeta, '12 话');
assert.equal(anime.summary, '第一行 第二行 第三段');
assert.equal(anime.score, '8.2');
assert.equal(anime.ratingCount, 3210);
assert.equal(anime.collectionCount, 4567);
assert.equal(anime.posterUrl, 'https://blog.example/PandaBangumi?type=cover&scope=subject&id=101&v=1234567890abcdef');
assert.deepEqual(anime.tags, ['原创', '科幻', '群像']);

const book = normalized('book');
assert.equal(book.typeKey, 'book');
assert.equal(book.primaryMeta, '9 册');
assert.equal(book.posterUrl, 'https://lain.bgm.tv/pic/cover/l/book.jpg');

const game = normalized('game');
assert.equal(game.typeKey, 'game');
assert.equal(game.primaryMeta, 'Nintendo Switch、PC');

const real = normalized('real');
assert.equal(real.typeKey, 'real');
assert.equal(real.primaryMeta, '24 集');

const music = normalized('music');
assert.equal(music.typeKey, 'music');
assert.equal(music.typeLabel, '音乐');
assert.equal(music.primaryMeta, '44 曲');
assert.equal(music.secondaryMeta, '2 碟');
assert.equal(music.musicCredit, '作曲 松本文紀(szak) / ピクセルビー');
assert.equal(music.musicCreditLabel, 'COMPOSER');
assert.equal(music.musicCreditValue, '松本文紀(szak) / ピクセルビー');

const unknown = normalized('unknown');
assert.equal(unknown.typeKey, 'unknown');
assert.equal(unknown.typeLabel, '条目');

const missing = normalized('missing');
assert.equal(missing.typeKey, 'book');
assert.equal(missing.title, '未命名条目');
assert.equal(missing.date, '');
assert.equal(missing.score, '暂无评分');
assert.equal(missing.hasScore, false);
assert.equal(missing.ratingCount, null);
assert.equal(missing.collectionCount, null);
assert.equal(missing.primaryMeta, '');
assert.equal(missing.secondaryMeta, '');
assert.equal(missing.musicCredit, '');
assert.equal(missing.musicCreditLabel, '');
assert.equal(missing.musicCreditValue, '');
assert.deepEqual(missing.tags, []);

const neutralPixels = new Uint8ClampedArray([
    255, 255, 255, 255,
    32, 32, 32, 255,
    128, 128, 128, 255
]);
assert.equal(pickMusicObiColor(neutralPixels), '');

const representativePixels = new Uint8ClampedArray([
    248, 248, 248, 255,
    63, 83, 141, 255,
    64, 82, 138, 255,
    150, 72, 54, 255
]);
const representativeColor = pickMusicObiColor(representativePixels);
assert.match(representativeColor, /^hsl\(22\d, /);

const colorParts = representativeColor.match(/[\d.]+/g).map(Number);
const colorRgb = hslToRgb(colorParts[0], colorParts[1] / 100, colorParts[2] / 100);
const whiteContrast = 1.05 / (relativeLuminance(colorRgb[0], colorRgb[1], colorRgb[2]) + 0.05);
assert.ok(whiteContrast >= 4.5);

const collectionTypes = {
    anime: ['watching', 'watched'],
    real: ['watching', 'watched'],
    book: ['reading', 'read'],
    game: ['playing', 'played'],
    music: ['listening', 'listened']
};
Object.entries(collectionTypes).forEach(([category, [active, completed]]) => {
    assert.equal(normalizeCollection(active, category), active);
    assert.equal(normalizeCollection(completed, category), completed);
    assert.equal(isCompletedCollection(active, category), false);
    assert.equal(isCompletedCollection(completed, category), true);
});

assert.equal(shouldShowProgress('calendar', 'anime', true), false);
assert.equal(shouldShowProgress('watching', 'anime', true), true);
assert.equal(shouldShowProgress('watched', 'anime', true), false);
assert.equal(shouldShowProgress('playing', 'game', true), false);

const missingCard = new FakeElement();
renderCardError(missingCard, 404, { code: 'not_found' });
assert.equal(missingCard.children[0].children[0].textContent, '未找到 Subject ID (404)。');
assert.equal(missingCard.children[0].children[1].textContent, '重新加载');

const failedCard = new FakeElement();
renderCardError(failedCard, 500, { code: 'refresh_failed' });
assert.equal(failedCard.children[0].children[0].textContent, '暂时无法加载条目信息。');
assert.equal(failedCard.children[0].children[1].textContent, '重新加载');

const calendarScrollCalls = [];
const calendarTabs = {
    clientWidth: 300,
    scrollWidth: 700,
    scrollLeft: 100,
    getBoundingClientRect: () => ({ left: 50 }),
    scrollTo: options => calendarScrollCalls.push(options)
};
centerCalendarTab(calendarTabs, {
    getBoundingClientRect: () => ({ left: 400, width: 80 })
});
assert.deepEqual(JSON.parse(JSON.stringify(calendarScrollCalls)), [{ left: 340, behavior: 'smooth' }]);

centerCalendarTab({
    clientWidth: 300,
    scrollWidth: 300,
    scrollLeft: 0,
    getBoundingClientRect: () => ({ left: 0 }),
    scrollTo: () => calendarScrollCalls.push('no-overflow')
}, {
    getBoundingClientRect: () => ({ left: 0, width: 80 })
});
assert.equal(calendarScrollCalls.length, 1);

const fallbackTabs = {
    clientWidth: 200,
    scrollWidth: 500,
    scrollLeft: 20,
    getBoundingClientRect: () => ({ left: 40 })
};
centerCalendarTab(fallbackTabs, {
    getBoundingClientRect: () => ({ left: 0, width: 80 })
});
assert.equal(fallbackTabs.scrollLeft, 0);

async function testRequestQueue() {
    const queue = createRequestQueue(2);
    const started = [];
    const releases = {};
    const task = name => queue.enqueue(() => new Promise(resolve => {
        started.push(name);
        releases[name] = resolve;
    }));

    const first = task('first');
    const second = task('second');
    const third = task('third');
    await Promise.resolve();
    assert.deepEqual(started, ['first', 'second']);
    assert.deepEqual(JSON.parse(JSON.stringify(queue.stats())), { active: 2, waiting: 1 });
    releases.first();
    await first;
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(started, ['first', 'second', 'third']);
    releases.second();
    releases.third();
    await Promise.all([second, third]);
    assert.deepEqual(JSON.parse(JSON.stringify(queue.stats())), { active: 0, waiting: 0 });

    const pjaxQueue = createRequestQueue(1);
    sandbox.window.PandaBangumi.requestQueue = pjaxQueue;
    const activeController = createRequestController();
    const waitingController = createRequestController();
    const active = pjaxQueue.enqueue(() => new Promise((resolve, reject) => {
        activeController.signal.addEventListener('abort', () => {
            const error = new Error('aborted');
            error.name = 'AbortError';
            reject(error);
        }, { once: true });
    }), activeController.signal);
    const waiting = pjaxQueue.enqueue(() => Promise.resolve(), waitingController.signal);
    await Promise.resolve();
    abortPendingRequests();
    await assert.rejects(active, error => error.name === 'AbortError');
    await assert.rejects(waiting, error => error.name === 'AbortError');
    await Promise.resolve();
    assert.deepEqual(JSON.parse(JSON.stringify(pjaxQueue.stats())), { active: 0, waiting: 0 });
}

function fakeResponse(status, data = {}, retryAfter = '') {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: { get: name => name === 'Retry-After' ? retryAfter : null },
        json: async () => data
    };
}

async function testRequestRetries() {
    sandbox.window.PandaBangumi.requestQueue = createRequestQueue(2);
    let calls = 0;
    sandbox.fetch = async () => {
        calls++;
        return calls === 1
            ? fakeResponse(429, {}, '0')
            : fakeResponse(200, { ok: true });
    };
    const recovered = await fetchJson(
        'https://blog.example/PandaBangumi?type=calendar',
        new AbortController().signal
    );
    assert.deepEqual(JSON.parse(JSON.stringify(recovered)), { ok: true });
    assert.equal(calls, 2);

    calls = 0;
    sandbox.fetch = async () => {
        calls++;
        return fakeResponse(503, { error: 'refresh_failed', retry_after: 30 });
    };
    await assert.rejects(
        fetchJson('https://blog.example/PandaBangumi?type=calendar', new AbortController().signal),
        error => error.status === 503
            && error.code === 'refresh_failed'
            && error.retryAfter === 30
    );
    assert.equal(calls, 1);

    calls = 0;
    sandbox.fetch = async () => {
        calls++;
        return fakeResponse(404, { error: 'not_found' });
    };
    await assert.rejects(
        fetchJson('https://blog.example/PandaBangumi?type=subject&id=404', new AbortController().signal),
        error => error.status === 404 && error.code === 'not_found'
    );
    assert.equal(calls, 1);

    calls = 0;
    sandbox.fetch = async () => {
        calls++;
        return fakeResponse(429, {}, '0');
    };
    await assert.rejects(
        fetchJson('https://blog.example/PandaBangumi?type=calendar', new AbortController().signal),
        error => error.status === 429
    );
    assert.equal(calls, 3);

    calls = 0;
    sandbox.fetch = async () => {
        calls++;
        return fakeResponse(429, {}, '1');
    };
    const controller = new AbortController();
    const abortedRetry = fetchJson(
        'https://blog.example/PandaBangumi?type=calendar',
        controller.signal
    );
    await new Promise(resolve => setImmediate(resolve));
    controller.abort();
    await assert.rejects(abortedRetry, error => error.name === 'AbortError');
    assert.equal(calls, 1);
}

async function testLocalCoverRetry() {
    const originalSetTimeout = sandbox.setTimeout;
    const originalClearTimeout = sandbox.clearTimeout;
    sandbox.setTimeout = callback => {
        Promise.resolve().then(callback);
        return 1;
    };
    sandbox.clearTimeout = () => {};

    class FakeImage {
        constructor(failures) {
            this.failures = failures;
            this.attempts = 0;
            this.onload = null;
            this.onerror = null;
        }

        set src(value) {
            this.attempts++;
            const attempt = this.attempts;
            Promise.resolve().then(() => {
                const callback = attempt <= this.failures ? this.onerror : this.onload;
                if (callback) callback();
            });
        }

        removeAttribute() {}
    }

    try {
        sandbox.window.PandaBangumi.requestQueue = createRequestQueue(2);
        const localImage = new FakeImage(1);
        await loadImageResource(
            localImage,
            'https://blog.example/PandaBangumi?type=cover&id=101&v=1234567890abcdef',
            new AbortController().signal
        );
        assert.equal(localImage.attempts, 2);

        const externalImage = new FakeImage(1);
        await assert.rejects(loadImageResource(
            externalImage,
            'https://lain.bgm.tv/pic/cover/l/test.jpg',
            new AbortController().signal
        ));
        assert.equal(externalImage.attempts, 1);
    } finally {
        sandbox.setTimeout = originalSetTimeout;
        sandbox.clearTimeout = originalClearTimeout;
    }
}

async function runAsyncTests() {
    await testRequestQueue();
    await testRequestRetries();
    await testLocalCoverRetry();
}

runAsyncTests().then(() => {
    process.stdout.write('7 subject card fixtures, 2 error states, 10 collection type mappings, 4 progress cases, 3 palette checks, calendar scroll, request queue, and retry checks passed\n');
}).catch(error => {
    console.error(error);
    process.exitCode = 1;
});
