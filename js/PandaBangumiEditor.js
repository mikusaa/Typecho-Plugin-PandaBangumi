'use strict';

(function () {
    const buttonId = 'wmd-pandabangumi-card-button';
    const panelId = 'pb-editor-card-panel';

    function initializeEditor() {
        const toolbar = document.querySelector('#wmd-button-row');
        const textarea = document.querySelector('#text');
        if (!toolbar || !textarea) {
            return false;
        }
        if (document.querySelector('#' + buttonId)) {
            return true;
        }

        const assetScript = document.querySelector('script[data-pb-editor-icon]');
        const iconUrl = assetScript ? assetScript.dataset.pbEditorIcon : '';
        const spacer = document.createElement('li');
        spacer.className = 'wmd-spacer';
        spacer.setAttribute('aria-hidden', 'true');

        const button = document.createElement('li');
        button.id = buttonId;
        button.className = 'wmd-button';
        button.title = '插入 Bangumi 条目卡片';
        button.setAttribute('role', 'button');
        button.setAttribute('tabindex', '0');
        button.setAttribute('aria-label', '插入 Bangumi 条目卡片');

        const icon = document.createElement('img');
        icon.className = 'pb-editor-icon';
        icon.src = iconUrl;
        icon.alt = '';
        icon.setAttribute('aria-hidden', 'true');
        button.append(icon);
        toolbar.append(spacer, button);

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            openCardDialog(textarea);
        });
        button.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                event.stopPropagation();
                openCardDialog(textarea);
            }
        });
        return true;
    }

    function openCardDialog(textarea) {
        const existing = document.querySelector('#' + panelId);
        if (existing) {
            existing.querySelector('input').focus();
            return;
        }

        const selectionStart = textarea.selectionStart;
        const selectionEnd = textarea.selectionEnd;
        const panel = document.createElement('div');
        panel.id = panelId;
        panel.className = 'pb-editor-panel';
        panel.innerHTML = [
            '<div class="wmd-prompt-background" data-pb-editor-action="cancel"></div>',
            '<div class="wmd-prompt-dialog pb-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="pb-editor-card-title">',
            '    <p id="pb-editor-card-title" class="pb-editor-heading">插入 Bangumi 条目卡片</p>',
            '    <p class="pb-editor-description">输入 Bangumi 条目地址中 /subject/ 后面的数字 ID。</p>',
            '    <form novalidate>',
            '        <label class="sr-only" for="pb-editor-subject-id">subject id</label>',
            '        <input id="pb-editor-subject-id" type="text" inputmode="numeric" autocomplete="off" placeholder="subject id">',
            '        <p class="pb-editor-error" aria-live="polite"></p>',
            '        <div class="pb-editor-actions">',
            '            <button type="submit" class="btn btn-s primary">插入</button>',
            '            <button type="button" class="btn btn-s" data-pb-editor-action="cancel">取消</button>',
            '        </div>',
            '    </form>',
            '</div>'
        ].join('');
        document.body.append(panel);

        const form = panel.querySelector('form');
        const input = panel.querySelector('#pb-editor-subject-id');
        const error = panel.querySelector('.pb-editor-error');

        function closeDialog() {
            panel.remove();
            textarea.focus();
            textarea.setSelectionRange(selectionStart, selectionEnd);
        }

        panel.querySelectorAll('[data-pb-editor-action="cancel"]').forEach(function (element) {
            element.addEventListener('click', closeDialog);
        });
        panel.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeDialog();
            }
        });
        input.addEventListener('input', function () {
            error.textContent = '';
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const subjectId = input.value.trim();
            if (!/^[1-9]\d*$/.test(subjectId) || !Number.isSafeInteger(Number(subjectId))) {
                error.textContent = '请输入有效的正整数 subject id。';
                input.focus();
                input.select();
                return;
            }

            const code = '<div class="bgm-card" data-id="' + subjectId + '"></div>';
            insertAtSelection(textarea, selectionStart, selectionEnd, code);
            panel.remove();
        });

        input.focus();
    }

    function insertAtSelection(textarea, start, end, code) {
        const value = textarea.value;
        const before = value.slice(0, start);
        const after = value.slice(end);
        let prefix = '';
        let suffix = '';

        if (before && !before.endsWith('\n\n')) {
            prefix = before.endsWith('\n') ? '\n' : '\n\n';
        }
        if (after && !after.startsWith('\n\n')) {
            suffix = after.startsWith('\n') ? '\n' : '\n\n';
        }

        const insertion = prefix + code + suffix;
        textarea.focus();
        textarea.setRangeText(insertion, start, end, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function startEditor() {
        if (initializeEditor()) {
            return;
        }

        const observer = new MutationObserver(function () {
            if (initializeEditor()) {
                observer.disconnect();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
        window.setTimeout(function () {
            observer.disconnect();
        }, 3000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startEditor, { once: true });
    } else {
        startEditor();
    }
})();
