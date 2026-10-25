'use strict';
const assert = require('node:assert/strict');
const { parseSubjectId, createCode, createInsertion, insertAtSelection } = require('../assets/js/PandaBangumiEditor.js');
let checks = 0;
function equal(actual, expected) { assert.deepEqual(actual, expected); checks++; }
for (const id of ['1', '10440', '9007199254740991']) {
    equal(parseSubjectId(' ' + id + ' '), id);
    for (const host of ['bangumi.tv', 'bgm.tv', 'chii.in']) {
        for (const prefix of ['', 'https://']) {
            equal(parseSubjectId(prefix + host + '/subject/' + id), id);
        }
    }
}
for (const value of ['0', '-1', '1.5', '1e3', '+12', '012', '9007199254740992', '12abc', '',
    'https://bgm.tv.evil.com/subject/1', 'https://sub.bgm.tv/subject/1', 'http://bgm.tv/subject/1',
    'https://u:p@bgm.tv/subject/1', 'https://@bgm.tv/subject/1', 'https://bgm.tv:8080/subject/1',
    'https://bgm.tv/subject/0', 'https://bgm.tv/subject/1e3', 'https://bgm.tv/blog/1',
    'https://bgm.tv/a/../subject/1', 'https://bgm.tv\\subject\\1', 'https://bgm.\ntv/subject/1',
    '正文 https://bgm.tv/subject/1', 'https://bgm.tv/subject/1 正文', '//bgm.tv/subject/1',
    'https://bgm.tv/subject/1/extra', 'https://bgm.tv/subject/1?hello world', 'https://bgm.tv/SUBJECT/1']) {
    equal(parseSubjectId(value), '');
    equal(createCode('card', { card: value }), '');
}
equal(parseSubjectId('HTTPS://BGM.TV:443/subject/10440/?from=test#info'), '10440');
equal(createCode('card', { card: 'chii.in/subject/10440' }), '<div class="bgm-card" data-id="10440"></div>');
const statuses = { anime: ['watching', 'watched'], real: ['watching', 'watched'], book: ['reading', 'read'], game: ['playing', 'played'], music: ['listening', 'listened'] };
for (const [category, types] of Object.entries(statuses)) {
    for (const [i, status] of ['active', 'completed'].entries()) {
        equal(createCode('collection', { category, status }), '<div data-type="' + types[i] + '" data-cate="' + category + '" class="bgm-collection"></div>');
    }
}
equal(createCode('calendar', { calendar: 'watching' }), '<div data-filter="watching" class="bgm-calendar"></div>');
equal(createCode('calendar', { calendar: 'all' }), '<div class="bgm-calendar"></div>');
for (const category of ['__proto__', 'invalid']) equal(createCode('collection', { category, status: 'active' }), '');
const code = '<div class="bgm-calendar"></div>';
for (const [before, after] of [['', ''], ['', '正文'], ['正文', ''], ['前文', '后文'], ['前文\n', '\n后文'], ['前文\n\n', '\n\n后文'], ['前文\n \n', '\n \n后文']]) {
    const source = before + '选择' + after;
    const result = createInsertion(source, before.length, before.length + 2, code);
    const output = before + result.text + after;
    equal(output, (before ? before.replace(/\n[ \t]*\n$|\n$/, '') + (before.endsWith('\n \n') ? '\n \n' : '\n\n') : '')
        + code + (after ? (after.startsWith('\n \n') ? '\n \n' : '\n\n') + after.replace(/^\n[ \t]*\n|^\n/, '') : '\n\n'));
    equal(output.slice(result.cursor), after.replace(/^\n[ \t]*\n|^\n/, ''));
}
// Exercise the actual edit boundary and notifications through a PageDown adapter.
const events = [];
const textarea = {
    value: '前文选择后文',
    focus() {}, setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
    setRangeText(text, start, end) { this.value = this.value.slice(0, start) + text + this.value.slice(end); },
    dispatchEvent(event) { events.push(event.type); },
    pandaBangumiEditor: { textOperation(edit) { events.push('undo-boundary'); edit(); events.push('preview'); } }
};
insertAtSelection(textarea, { start: 2, end: 4, direction: 'forward' }, code);
equal(textarea.value, '前文\n\n' + code + '\n\n后文');
equal(events, ['undo-boundary', 'preview', 'input', 'change']);
equal(textarea.selectionStart, textarea.value.indexOf('后文'));
// Existing activations have no PHP bridge yet. Verify the command runner contract
// produces the same text and does not mutate another plugin/native button handler.
textarea.value = 'leftSELright';
delete textarea.pandaBangumiEditor;
const bold = { textOp() { throw new Error('Must not execute bold'); }, onclick() {
    const chunks = {};
    this.textOp(chunks);
    textarea.value = chunks.before + chunks.startTag + chunks.selection + chunks.endTag + chunks.after;
    textarea.setSelectionRange(chunks.before.length, chunks.before.length);
} };
const originalHandler = bold.onclick;
global.document = { querySelector() { return bold; } };
insertAtSelection(textarea, { start: 4, end: 7 }, code);
equal(textarea.value, 'left\n\n' + code + '\n\nright');
equal(textarea.selectionStart, textarea.value.indexOf('right'));
equal(bold.onclick, originalHandler);
delete global.document;
console.log('Editor insertion: ' + checks + ' assertions passed.');
