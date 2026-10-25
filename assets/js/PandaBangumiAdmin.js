'use strict';

(function () {
    function initializeSettings() {
        // Anchor to this plugin's real fields; code snippets are no longer part of the form.
        const form = Array.from(document.querySelectorAll('form')).find(function (candidate) {
            return ['ID', 'ApiBase', 'ImageMode', 'ValidTimeSpan', 'Limit'].every(function (name) {
                return candidate.querySelector('[name="' + name + '"]');
            });
        });
        if (!form || form.dataset.pbInitialized === 'true') return;
        form.dataset.pbInitialized = 'true';
        const refreshInput = form.querySelector('[name="ValidTimeSpan"]');
        const imageModeInputs = form.querySelectorAll('[name="ImageMode"]');

        function descriptionFor(input) {
            const option = input && input.closest('.typecho-option');
            return option && option.querySelector('.description');
        }
        function updateRefreshDescription() {
            const description = descriptionFor(refreshInput);
            if (!description) return;
            const seconds = Number(refreshInput.value);
            if (!/^\d+$/.test(refreshInput.value.trim()) || !Number.isSafeInteger(seconds) || seconds < 300) {
                description.textContent = '最低 300 秒。';
                return;
            }
            let readable = seconds + ' 秒';
            if (seconds % 86400 === 0) readable = (seconds / 86400) + ' 天';
            else if (seconds % 3600 === 0) readable = (seconds / 3600) + ' 小时';
            else if (seconds % 60 === 0) readable = (seconds / 60) + ' 分钟';
            description.textContent = '约 ' + readable + '；最低 300 秒。';
        }
        function updateImageModeDescription() {
            const selected = form.querySelector('[name="ImageMode"]:checked');
            const description = descriptionFor(selected);
            if (!description) return;
            description.textContent = selected.value === 'cache'
                ? '访客只请求本站地址，封面保存到插件缓存目录。'
                : '访客浏览器直接请求 API 返回的图片地址。';
        }
        refreshInput.addEventListener('input', updateRefreshDescription);
        refreshInput.addEventListener('change', updateRefreshDescription);
        imageModeInputs.forEach(input => input.addEventListener('change', updateImageModeDescription));
        updateRefreshDescription();
        updateImageModeDescription();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeSettings, { once: true });
    else initializeSettings();
})();
