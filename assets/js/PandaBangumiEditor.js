'use strict';

(function () {
    const categories = {
        anime: { label: '动画', active: ['watching', '在看'], completed: ['watched', '看过'] },
        real: { label: '三次元', active: ['watching', '在看'], completed: ['watched', '看过'] },
        book: { label: '书籍', active: ['reading', '在读'], completed: ['read', '读过'] },
        game: { label: '游戏', active: ['playing', '在玩'], completed: ['played', '玩过'] },
        music: { label: '音乐', active: ['listening', '在听'], completed: ['listened', '听过'] }
    };
    const tabs = { card: '条目卡片', collection: '收藏列表', calendar: '追番日历' };

    function parseSubjectId(input) {
        let value = input.trim();
        let id = value;
        if (!/^[1-9]\d*$/.test(value)) {
            if (/^(bangumi\.tv|bgm\.tv|chii\.in)\//i.test(value)) value = 'https://' + value;
            // Check the literal URL too: URL() normalizes whitespace, backslashes and dot segments.
            if (!/^https:\/\/(bangumi\.tv|bgm\.tv|chii\.in)(?::443)?\/subject\/[1-9]\d*\/?(?:[?#][^\s\\]*)?$/i.test(value)) return '';
            const url = new URL(value);
            const match = url.pathname.match(/^\/subject\/([1-9]\d*)\/?$/);
            if (!match) return '';
            id = match[1];
        }
        return Number.isSafeInteger(Number(id)) ? id : '';
    }

    function createCode(view, state) {
        if (view === 'card') {
            const id = parseSubjectId(state.card);
            return id ? '<div class="bgm-card" data-id="' + id + '"></div>' : '';
        }
        if (view === 'collection' && Object.hasOwn(categories, state.category)
            && ['active', 'completed'].includes(state.status)) {
            return '<div data-type="' + categories[state.category][state.status][0]
                + '" data-cate="' + state.category + '" class="bgm-collection"></div>';
        }
        if (view === 'calendar' && ['watching', 'all'].includes(state.calendar)) {
            return state.calendar === 'watching'
                ? '<div data-filter="watching" class="bgm-calendar"></div>'
                : '<div class="bgm-calendar"></div>';
        }
        return '';
    }

    function createInsertion(value, start, end, code) {
        const before = value.slice(0, start);
        const after = value.slice(end);
        const prefix = !before || /\n[ \t]*\n$/.test(before) ? '' : before.endsWith('\n') ? '\n' : '\n\n';
        const suffix = /^\n[ \t]*\n/.test(after) ? '' : after.startsWith('\n') ? '\n' : '\n\n';
        const text = prefix + code + suffix;
        // At EOF leave a blank line ready for more Markdown; reuse existing newlines elsewhere.
        const following = after.match(/^\n[ \t]*\n/);
        return { text: text, cursor: start + text.length + (following ? following[0].length : after.startsWith('\n') ? 1 : 0) };
    }

    function insertAtSelection(textarea, selection, code) {
        const value = textarea.value;
        const insertion = createInsertion(textarea.value, selection.start, selection.end, code);
        textarea.focus({ preventScroll: true });
        textarea.setSelectionRange(selection.start, selection.end, selection.direction);
        const edit = function () {
            textarea.setRangeText(insertion.text, selection.start, selection.end, 'end');
            textarea.setSelectionRange(insertion.cursor, insertion.cursor);
        };
        const editor = textarea.pandaBangumiEditor;
        if (editor && typeof editor.textOperation === 'function') {
            editor.textOperation(edit);
        } else if (pageDownCommand()) {
            // Already-active installations may not have the new PHP hook registered.
            // PageDown's existing command runner owns the same undo/preview transaction.
            // Call it with our text operation; do not replace the toolbar's handler or textOp.
            const result = value.slice(0, selection.start) + insertion.text + value.slice(selection.end);
            pageDownCommand().onclick.call({ textOp: function (chunks) {
                chunks.before = result.slice(0, insertion.cursor);
                chunks.selection = '';
                chunks.after = result.slice(insertion.cursor);
                chunks.startTag = chunks.endTag = '';
            } });
        } else {
            // A plain textarea has no PageDown command runner; try the browser undo stack.
            const previous = textarea.value;
            try { document.execCommand('insertText', false, insertion.text); } catch (error) { /* fallback below */ }
            if (textarea.value === previous) edit();
            textarea.setSelectionRange(insertion.cursor, insertion.cursor);
            if (editor && typeof editor.refreshPreview === 'function') editor.refreshPreview();
        }
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function pageDownCommand() {
        if (typeof document === 'undefined') return null;
        const command = document.querySelector('#wmd-bold-button');
        return command && typeof command.textOp === 'function' && typeof command.onclick === 'function' ? command : null;
    }

    // Keep pure contracts testable with Node, without adding browser globals or a dependency.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { parseSubjectId, createCode, createInsertion, insertAtSelection };
        return;
    }
    if (window.pandaBangumiInsertMenu) return;

    const buttonId = 'wmd-pandabangumi-card-button';
    const panelId = 'pb-editor-insert-panel';
    // Remember the last tab and choices for this page session.
    const state = { tab: 'card', category: 'anime', status: 'active', calendar: 'watching', card: '' };
    let instance = null;

    function choice(name, value, label) {
        return '<label class="pb-editor-choice"><input type="radio" name="pb-' + name + '" value="' + value + '"'
            + (state[name] === value ? ' checked' : '') + '><span>' + label + '</span></label>';
    }

    function initializeEditor() {
        const toolbar = document.querySelector('#wmd-button-row');
        const textarea = document.querySelector('#text');
        if (instance && (instance.toolbar !== toolbar || instance.textarea !== textarea
            || !instance.button.isConnected)) {
            instance.destroy();
            instance = null;
        }
        if (instance || !toolbar || !textarea || textarea.tagName !== 'TEXTAREA') return;

        const spacer = document.createElement('li');
        spacer.className = 'wmd-spacer';
        spacer.setAttribute('aria-hidden', 'true');
        const item = document.createElement('li');
        item.id = buttonId;
        item.className = 'wmd-button';
        const button = document.createElement('button');
        button.type = 'button';
        button.title = '插入 Bangumi 内容';
        button.setAttribute('aria-label', button.title);
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', panelId);
        const icon = document.createElement('img');
        icon.className = 'pb-editor-icon';
        const script = document.querySelector('script[data-pb-editor-icon]');
        icon.src = script ? script.dataset.pbEditorIcon : '';
        icon.alt = '';
        button.append(icon);
        item.append(button);
        toolbar.append(spacer, item);

        const panel = document.createElement('div');
        panel.id = panelId;
        panel.className = 'pb-editor-panel';
        panel.hidden = true;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', '插入 Bangumi 内容');
        panel.innerHTML = '<div class="pb-editor-tabs" role="tablist">' + Object.keys(tabs).map(function (key) {
            return '<button type="button" role="tab" id="pb-editor-tab-' + key + '" data-pb-tab="' + key
                + '" aria-controls="pb-editor-body">' + tabs[key] + '</button>';
        }).join('') + '</div>'
            + '<div class="pb-editor-body" id="pb-editor-body" role="tabpanel"></div>'
            + '<div class="pb-editor-code"><code></code><button type="button" data-pb-action="copy">复制</button></div>'
            + '<div class="pb-editor-actions"><button type="button" data-pb-action="cancel">取消</button>'
            + '<button type="button" class="primary" data-pb-action="insert">插入</button></div>';
        document.body.append(panel);
        const body = panel.querySelector('.pb-editor-body');
        const code = panel.querySelector('.pb-editor-code code');
        const copy = panel.querySelector('[data-pb-action="copy"]');
        let selection = null;
        let cleanup = null;

        function undoShortcut(event) {
            if (!(event.ctrlKey || event.metaKey) || event.altKey || event.isComposing) return;
            const key = event.key.toLowerCase();
            if (key !== 'z' && key !== 'y') return;
            if (!textarea.pandaBangumiEditor && !pageDownCommand()) return;
            const command = toolbar.querySelector(key === 'y' || event.shiftKey ? '#wmd-redo-button' : '#wmd-undo-button');
            if (!command || typeof command.onclick !== 'function') return;
            // Typecho 1.3 PageDown handles Z/Y in both UndoManager and UIManager.
            // Route the shortcut through its toolbar once, keeping its own history.
            event.preventDefault();
            event.stopImmediatePropagation();
            command.click();
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }
        textarea.addEventListener('keydown', undoShortcut, true);

        function close(restore) {
            if (panel.hidden) return;
            panel.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            if (cleanup) cleanup();
            cleanup = null;
            if (restore && textarea.isConnected) {
                textarea.focus({ preventScroll: true });
                textarea.setSelectionRange(selection.start, selection.end, selection.direction);
            }
        }

        function position() {
            if (panel.hidden) return;
            const anchor = button.getBoundingClientRect();
            const viewport = window.visualViewport;
            const leftEdge = viewport ? viewport.offsetLeft : 0;
            const topEdge = viewport ? viewport.offsetTop : 0;
            const width = viewport ? viewport.width : document.documentElement.clientWidth;
            const height = viewport ? viewport.height : window.innerHeight;
            if (!button.isConnected || !anchor.width || !anchor.height || anchor.bottom < topEdge
                || anchor.top > topEdge + height || anchor.right < leftEdge || anchor.left > leftEdge + width) {
                close(false);
                return;
            }
            const gap = 8;
            // One fixed width for every tab, so switching never makes the panel jump.
            panel.style.width = Math.min(360, width - gap * 2) + 'px';
            panel.style.maxHeight = Math.max(0, height - gap * 2) + 'px';
            const panelHeight = panel.getBoundingClientRect().height;
            let top = anchor.bottom + 6;
            if (top + panelHeight > topEdge + height - gap && anchor.top - panelHeight - 6 >= topEdge + gap) {
                top = anchor.top - panelHeight - 6;
            }
            panel.style.top = Math.max(topEdge + gap, Math.min(top, topEdge + height - gap - panelHeight)) + 'px';
            panel.style.left = Math.max(leftEdge + gap, Math.min(anchor.left, leftEdge + width - gap - panel.offsetWidth)) + 'px';
        }

        function updateCode() {
            const value = createCode(state.tab, state);
            code.textContent = value || '<div class="bgm-card" data-id="…"></div>';
            code.classList.toggle('pb-editor-code-ready', Boolean(value));
            copy.disabled = !value;
            copy.textContent = '复制';
        }

        function updateCalendarHelp() {
            body.querySelector('[data-pb-calendar-help]').textContent = state.calendar === 'watching'
                ? '只显示你在 Bangumi 标记为「在看」的动画。'
                : '显示 Bangumi 每日放送中的全部动画。';
        }

        function render(next, focusField) {
            state.tab = next;
            panel.querySelectorAll('[role="tab"]').forEach(function (tab) {
                const selected = tab.dataset.pbTab === next;
                tab.setAttribute('aria-selected', String(selected));
                tab.tabIndex = selected ? 0 : -1;
            });
            body.setAttribute('aria-labelledby', 'pb-editor-tab-' + next);
            if (next === 'card') {
                body.innerHTML = '<label class="pb-editor-label" for="pb-editor-subject-id">条目 ID 或链接</label>'
                    + '<input id="pb-editor-subject-id" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="10440 或 https://bgm.tv/subject/10440" aria-describedby="pb-editor-card-help pb-editor-error">'
                    + '<p id="pb-editor-card-help" class="pb-editor-description">支持 bangumi.tv、bgm.tv、chii.in 的 HTTPS 条目链接，回车直接插入。</p>'
                    + '<p id="pb-editor-error" class="pb-editor-error" aria-live="polite"></p>';
                body.querySelector('input').value = state.card;
            } else if (next === 'collection') {
                body.innerHTML = '<fieldset><legend class="pb-editor-label">分类</legend><div class="pb-editor-choices">'
                    + Object.keys(categories).map(key => choice('category', key, categories[key].label)).join('')
                    + '</div></fieldset><fieldset><legend class="pb-editor-label">状态</legend><div class="pb-editor-choices">'
                    + ['active', 'completed'].map(key => choice('status', key, categories[state.category][key][1])).join('')
                    + '</div></fieldset><p class="pb-editor-description">展示插件设置中 Bangumi 用户的对应收藏。</p>';
            } else {
                body.innerHTML = '<fieldset><legend class="pb-editor-label">展示范围</legend><div class="pb-editor-choices">'
                    + choice('calendar', 'watching', '仅我在看') + choice('calendar', 'all', '全部放送')
                    + '</div></fieldset><p class="pb-editor-description" data-pb-calendar-help></p>';
                updateCalendarHelp();
            }
            updateCode();
            position();
            if (focusField) body.querySelector('input:checked, input[type="text"]').focus({ preventScroll: true });
        }

        function submit() {
            const value = createCode(state.tab, state);
            if (!value) {
                const input = body.querySelector('#pb-editor-subject-id');
                if (input) {
                    body.querySelector('.pb-editor-error').textContent = '请输入有效的正整数 ID 或 HTTPS Bangumi 条目链接。';
                    input.setAttribute('aria-invalid', 'true');
                    input.focus();
                    position();
                }
                return;
            }
            // Close before focusing the textarea, so focusout cannot restore the old selection.
            close(false);
            insertAtSelection(textarea, selection, value);
            if (state.tab === 'card') state.card = '';
        }

        function copyCode() {
            const text = code.textContent;
            const done = function (label) { if (code.textContent === text) copy.textContent = label; };
            const fallback = function () {
                window.getSelection().selectAllChildren(code);
                done('已选中');
            };
            if (!navigator.clipboard || !navigator.clipboard.writeText) { fallback(); return; }
            navigator.clipboard.writeText(text).then(function () { done('已复制'); }, fallback);
        }

        button.addEventListener('click', function () {
            if (!panel.hidden) { close(true); return; }
            selection = { start: textarea.selectionStart, end: textarea.selectionEnd, direction: textarea.selectionDirection };
            // A selected ID or subject link opens straight into the card tab.
            const selected = textarea.value.slice(selection.start, selection.end).trim();
            if (selected && parseSubjectId(selected)) {
                state.card = selected;
                state.tab = 'card';
            }
            // Match the actual editor surface, including explicit theme overrides;
            // fall back to the system scheme when every ancestor is transparent.
            let surface = textarea;
            panel.dataset.pbTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            while (surface) {
                const color = getComputedStyle(surface).backgroundColor;
                const rgb = color.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)$/);
                if (rgb && (!rgb[4] || Number(rgb[4]) > 0.5)) {
                    panel.dataset.pbTheme = (Number(rgb[1]) * .299 + Number(rgb[2]) * .587 + Number(rgb[3]) * .114) < 128 ? 'dark' : 'light';
                    break;
                }
                surface = surface.parentElement;
            }
            panel.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            render(state.tab, true);
            if (panel.hidden) return;
            const contains = target => panel.contains(target) || button.contains(target);
            const outside = event => { if (!contains(event.target)) close(false); };
            const escape = event => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    close(true);
                }
            };
            document.addEventListener('pointerdown', outside, true);
            document.addEventListener('focusin', outside);
            document.addEventListener('keydown', escape, true);
            window.addEventListener('resize', position);
            window.addEventListener('scroll', position, true);
            const viewport = window.visualViewport;
            if (viewport) {
                viewport.addEventListener('resize', position);
                viewport.addEventListener('scroll', position);
            }
            cleanup = function () {
                document.removeEventListener('pointerdown', outside, true);
                document.removeEventListener('focusin', outside);
                document.removeEventListener('keydown', escape, true);
                window.removeEventListener('resize', position);
                window.removeEventListener('scroll', position, true);
                if (viewport) {
                    viewport.removeEventListener('resize', position);
                    viewport.removeEventListener('scroll', position);
                }
            };
        });
        panel.addEventListener('click', function (event) {
            const target = event.target.closest('button');
            if (!target) return;
            if (target.dataset.pbTab) render(target.dataset.pbTab, false);
            else if (target.dataset.pbAction === 'copy') copyCode();
            else if (target.dataset.pbAction === 'cancel') close(true);
            else if (target.dataset.pbAction === 'insert') submit();
        });
        panel.addEventListener('input', function (event) {
            if (event.target.id !== 'pb-editor-subject-id') return;
            state.card = event.target.value;
            event.target.removeAttribute('aria-invalid');
            body.querySelector('.pb-editor-error').textContent = '';
            updateCode();
            position();
        });
        panel.addEventListener('change', function (event) {
            const input = event.target;
            if (input.name === 'pb-category') {
                state.category = input.value;
                body.querySelectorAll('[name="pb-status"]').forEach(function (status) {
                    status.nextElementSibling.textContent = categories[state.category][status.value][1];
                });
            } else if (input.name === 'pb-status') state.status = input.value;
            else if (input.name === 'pb-calendar') { state.calendar = input.value; updateCalendarHelp(); }
            updateCode();
            position();
        });
        panel.addEventListener('keydown', function (event) {
            const tab = event.target.closest('[role="tab"]');
            if (tab && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
                const keys = Object.keys(tabs);
                const step = event.key === 'ArrowRight' ? 1 : keys.length - 1;
                const next = keys[(keys.indexOf(tab.dataset.pbTab) + step) % keys.length];
                event.preventDefault();
                render(next, false);
                panel.querySelector('#pb-editor-tab-' + next).focus();
            } else if (event.key === 'Enter' && event.target.tagName === 'INPUT' && !event.isComposing) {
                event.preventDefault();
                submit();
            }
        });
        instance = { toolbar, textarea, button, destroy: function () {
            close(false);
            textarea.removeEventListener('keydown', undoShortcut, true);
            panel.remove(); item.remove(); spacer.remove();
        } };
    }

    // One observer per document handles delayed toolbars and editor replacement; no polling timer.
    // PageDown rewrites the preview on every keystroke, so check at most once per frame.
    let scheduled = false;
    const observer = new MutationObserver(function () {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(function () {
            scheduled = false;
            initializeEditor();
        });
    });
    function start() {
        initializeEditor();
        observer.observe(document.body, { childList: true, subtree: true });
    }
    window.pandaBangumiInsertMenu = { initialize: initializeEditor };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
