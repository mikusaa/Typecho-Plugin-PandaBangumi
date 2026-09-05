'use strict';

(function () {
    function initializeSettings() {
        const snippets = document.querySelector('.pb-settings-snippets');
        if (!snippets || snippets.dataset.pbInitialized === 'true') {
            return;
        }
        snippets.dataset.pbInitialized = 'true';

        const statusMap = {
            anime: [['watching', '在看'], ['watched', '看过']],
            real: [['watching', '在看'], ['watched', '看过']],
            book: [['reading', '在读'], ['read', '读过']],
            game: [['playing', '在玩'], ['played', '玩过']],
            music: [['listening', '在听'], ['listened', '听过']]
        };

        const category = snippets.querySelector('#pb-snippet-category');
        const status = snippets.querySelector('#pb-snippet-status');
        const collectionCode = snippets.querySelector('#pb-collection-code');
        const calendarFilter = snippets.querySelector('#pb-calendar-filter');
        const calendarCode = snippets.querySelector('#pb-calendar-code');
        const subjectId = snippets.querySelector('#pb-subject-id');
        const cardCode = snippets.querySelector('#pb-card-code');
        const form = snippets.closest('form');
        const refreshInput = form && form.querySelector('[name="ValidTimeSpan"]');
        const imageModeInputs = form ? form.querySelectorAll('[name="ImageMode"]') : [];

        function updateRefreshDescription() {
            if (!refreshInput) {
                return;
            }
            const description = refreshInput.closest('.typecho-option').querySelector('.description');
            const seconds = Number.parseInt(refreshInput.value, 10);
            if (!Number.isInteger(seconds) || seconds < 300) {
                description.textContent = '最低 300 秒。';
                return;
            }

            let readable = seconds + ' 秒';
            if (seconds % 86400 === 0) {
                readable = (seconds / 86400) + ' 天';
            } else if (seconds % 3600 === 0) {
                readable = (seconds / 3600) + ' 小时';
            } else if (seconds % 60 === 0) {
                readable = (seconds / 60) + ' 分钟';
            }
            description.textContent = '约 ' + readable + '；最低 300 秒。';
        }

        function updateImageModeDescription() {
            const selected = form && form.querySelector('[name="ImageMode"]:checked');
            if (!selected) {
                return;
            }
            const description = selected.closest('.typecho-option').querySelector('.description');
            description.textContent = selected.value === 'cache'
                ? '访客只请求本站地址，封面保存到插件缓存目录。'
                : '访客浏览器直接请求 API 返回的图片地址。';
        }

        function updateCollectionCode() {
            const previous = status.value;
            const choices = statusMap[category.value] || statusMap.anime;
            status.replaceChildren();
            choices.forEach(function (choice) {
                const option = document.createElement('option');
                option.value = choice[0];
                option.textContent = choice[1];
                option.selected = choice[0] === previous;
                status.append(option);
            });
            collectionCode.value = '<div data-type="' + status.value
                + '" data-cate="' + category.value + '" class="bgm-collection"></div>';
        }

        function updateCardCode() {
            const value = Number.parseInt(subjectId.value, 10);
            const id = Number.isInteger(value) && value > 0 ? String(value) : 'subject id';
            cardCode.value = '<div class="bgm-card" data-id="' + id + '"></div>';
        }

        function updateCalendarCode() {
            calendarCode.value = calendarFilter.value === 'watching'
                ? '<div data-filter="watching" class="bgm-calendar"></div>'
                : '<div class="bgm-calendar"></div>';
        }

        category.addEventListener('change', updateCollectionCode);
        status.addEventListener('change', function () {
            collectionCode.value = '<div data-type="' + status.value
                + '" data-cate="' + category.value + '" class="bgm-collection"></div>';
        });
        subjectId.addEventListener('input', updateCardCode);
        calendarFilter.addEventListener('change', updateCalendarCode);
        if (refreshInput) {
            refreshInput.addEventListener('input', updateRefreshDescription);
        }
        imageModeInputs.forEach(function (input) {
            input.addEventListener('change', updateImageModeDescription);
        });
        updateCollectionCode();
        updateCalendarCode();
        updateCardCode();
        updateRefreshDescription();
        updateImageModeDescription();

        const copyStatus = snippets.querySelector('.pb-copy-status');
        snippets.querySelectorAll('[data-copy-target]').forEach(function (button) {
            button.addEventListener('click', async function () {
                const target = snippets.querySelector('#' + button.dataset.copyTarget);
                if (!target) {
                    return;
                }
                const originalLabel = button.textContent;
                try {
                    await copyText(target.value || '');
                    button.textContent = '已复制';
                    copyStatus.textContent = '代码已复制到剪贴板。';
                    window.setTimeout(function () {
                        button.textContent = originalLabel;
                    }, 1400);
                } catch (error) {
                    copyStatus.textContent = '复制失败，请手动选择代码。';
                }
            });
        });
    }

    async function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.append(textarea);
        textarea.select();
        const copied = document.execCommand('copy');
        textarea.remove();
        if (!copied) {
            throw new Error('copy_failed');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSettings, { once: true });
    } else {
        initializeSettings();
    }
})();
