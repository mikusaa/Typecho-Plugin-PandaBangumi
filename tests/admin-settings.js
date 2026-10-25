'use strict';
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/PandaBangumiAdmin.js'), 'utf8');
function field(value) {
    const description = { textContent: '' };
    return { value, description, listeners: {}, closest() { return { querySelector() { return description; } }; },
        addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); },
        emit(type) { (this.listeners[type] || []).forEach(callback => callback()); } };
}
const refresh = field('86400');
const direct = field('direct');
const cache = field('cache');
let selected = direct;
const fields = { ID: field('demo'), ApiBase: field(''), ImageMode: direct, ValidTimeSpan: refresh, Limit: field('30') };
const form = { dataset: {}, querySelector(selector) {
    if (selector === '[name="ImageMode"]:checked') return selected;
    return fields[selector.match(/name="([^"]+)"/)[1]];
}, querySelectorAll() { return [direct, cache]; } };
const unrelated = { querySelector() { return null; } };
const context = { document: { readyState: 'complete', querySelectorAll() { return [unrelated, form]; } } };
vm.runInNewContext(source, context);
assert.equal(refresh.description.textContent, '约 1 天；最低 300 秒。');
for (const [seconds, text] of [['3600', '约 1 小时；最低 300 秒。'], ['300', '约 5 分钟；最低 300 秒。'], ['301', '约 301 秒；最低 300 秒。'], ['12abc', '最低 300 秒。'], ['3e3', '最低 300 秒。'], ['299', '最低 300 秒。']]) {
    refresh.value = seconds; refresh.emit('input'); assert.equal(refresh.description.textContent, text);
}
selected = cache; cache.emit('change'); assert.match(cache.description.textContent, /封面保存到插件缓存目录/);
selected = direct; direct.emit('change'); assert.match(direct.description.textContent, /直接请求 API/);
vm.runInNewContext(source, context);
assert.equal(refresh.listeners.input.length, 1);
refresh.closest = () => null;
refresh.emit('change');
selected = null; direct.emit('change');
vm.runInNewContext(source, { document: { readyState: 'complete', querySelectorAll() { return [unrelated]; } } });
console.log('Admin settings: refresh descriptions, cover mode, missing nodes and idempotency passed without snippets.');
