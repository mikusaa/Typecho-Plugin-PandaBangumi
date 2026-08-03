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

const sandbox = {
    AbortController,
    URL,
    clearTimeout,
    console: { error() {}, log() {} },
    document: {
        addEventListener() {},
        readyState: 'loading'
    },
    setTimeout,
    window: {
        PandaBangumi: {},
        bgmBase: 'https://blog.example/PandaBangumi'
    }
};

vm.createContext(sandbox);
vm.runInContext(source, sandbox, { filename: 'PandaBangumi.js' });
const normalize = vm.runInContext('normalizeSubjectCardData', sandbox);
const normalizeCollection = vm.runInContext('normalizeCollectionType', sandbox);
const isCompletedCollection = vm.runInContext('isCompletedCollectionType', sandbox);

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
assert.deepEqual(missing.tags, []);

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

process.stdout.write('7 subject card fixtures and 10 collection type mappings passed\n');
