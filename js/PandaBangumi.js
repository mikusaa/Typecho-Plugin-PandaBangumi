/**
 * PandaBangumi 全局状态
 */
var PandaBangumiRuntime = window.PandaBangumi || {};
PandaBangumiRuntime.controllers = PandaBangumiRuntime.controllers || new Set();
PandaBangumiRuntime.initTimer = PandaBangumiRuntime.initTimer || null;
PandaBangumiRuntime.bound = PandaBangumiRuntime.bound || false;
PandaBangumiRuntime.coverObserver = PandaBangumiRuntime.coverObserver || null;
PandaBangumiRuntime.coverLoads = PandaBangumiRuntime.coverLoads || new Map();
PandaBangumiRuntime.musicObiColors = PandaBangumiRuntime.musicObiColors || new Map();
PandaBangumiRuntime.coverPreloadMargin = 0;
window.PandaBangumi = PandaBangumiRuntime;

if (!PandaBangumiRuntime.consoleLogged) {
    console.log(
        ' %c PandaBangumi %c https://www.himiku.com/archives/pandabangumi.html ',
        'color: #fff; background: #DF98A0; padding: 5px;',
        'color: #fff; background: #1c2b36; padding: 5px;'
    );
    PandaBangumiRuntime.consoleLogged = true;
}

/**
 * 判断是否为请求取消
 * @param {unknown} error
 * @returns {boolean}
 */
function isAbortError(error) {
    return error && error.name === 'AbortError';
}

/**
 * 创建可被 PJAX 生命周期取消的请求控制器
 * @returns {AbortController}
 */
function createRequestController() {
    const controller = new AbortController();
    PandaBangumiRuntime.controllers.add(controller);
    return controller;
}

/**
 * 移除请求控制器
 * @param {AbortController} controller
 */
function removeRequestController(controller) {
    PandaBangumiRuntime.controllers.delete(controller);
}

/**
 * 取消未完成请求
 */
function abortPendingRequests() {
    PandaBangumiRuntime.controllers.forEach(controller => controller.abort());
    PandaBangumiRuntime.controllers.clear();
    disconnectCoverLoading();
}

/**
 * 校验 HTTPS 链接
 * @param {string} value
 * @returns {string}
 */
function safeHttpsUrl(value) {
    const raw = String(value || '').trim();
    try {
        const url = new URL(raw);
        return url.protocol === 'https:' ? url.href : '';
    } catch (error) {
        return '';
    }
}

/**
 * 校验 API 返回的图片 URL，不改写域名、协议或路径
 * @param {string} value
 * @returns {string}
 */
function safeImageUrl(value) {
    const raw = String(value || '').trim();
    try {
        const url = new URL(raw);
        if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
            return '';
        }
        return raw;
    } catch (error) {
        return '';
    }
}

/**
 * 标准化收藏分类
 * @param {string} value
 * @returns {'book'|'anime'|'music'|'game'|'real'}
 */
function normalizeCollectionCategory(value) {
    const cate = String(value || '').toLowerCase();
    return ['book', 'anime', 'music', 'game', 'real'].includes(cate) ? cate : 'anime';
}

var PandaBangumiCollectionTypes = {
    anime: ['watching', 'watched'],
    real: ['watching', 'watched'],
    book: ['reading', 'read'],
    game: ['playing', 'played'],
    music: ['listening', 'listened']
};

/**
 * 按条目分类标准化收藏状态名
 * @param {string} value
 * @param {string} cate
 * @returns {'watching'|'watched'|'reading'|'read'|'playing'|'played'|'listening'|'listened'}
 */
function normalizeCollectionType(value, cate) {
    const category = normalizeCollectionCategory(cate);
    const types = PandaBangumiCollectionTypes[category];
    const type = String(value || '').toLowerCase();
    return types.includes(type) ? type : types[0];
}

/**
 * 判断收藏状态是否为已完成
 * @param {string} type
 * @param {string} cate
 * @returns {boolean}
 */
function isCompletedCollectionType(type, cate) {
    const category = normalizeCollectionCategory(cate);
    return normalizeCollectionType(type, category) === PandaBangumiCollectionTypes[category][1];
}

/**
 * 构造本站日历封面地址
 * @param {object} item
 * @returns {string}
 */
function buildCalendarCoverUrl(item) {
    const subjectId = Number(item && item.id);
    const version = String(item && item.cover_version || '');
    const base = String(window.bgmBase || '');
    if (!Number.isInteger(subjectId) || subjectId <= 0 || !/^[a-f0-9]{16}$/.test(version) || !base) {
        return '';
    }

    const separator = base.includes('?') ? '&' : '?';
    return `${base}${separator}type=cover&id=${String(subjectId)}&v=${encodeURIComponent(version)}`;
}

/**
 * 构造本站收藏列表封面地址
 * @param {object} item
 * @param {string} list
 * @param {string} cate
 * @returns {string}
 */
function buildCollectionCoverUrl(item, list, cate) {
    const subjectId = Number(item && item.id);
    const version = String(item && item.cover_version || '');
    const normalizedCate = normalizeCollectionCategory(cate);
    const normalizedList = normalizeCollectionType(list, normalizedCate);
    const base = String(window.bgmBase || '');
    if (
        !Number.isInteger(subjectId)
        || subjectId <= 0
        || !/^[a-f0-9]{16}$/.test(version)
        || !normalizedList
        || !base
    ) {
        return '';
    }

    const separator = base.includes('?') ? '&' : '?';
    return `${base}${separator}type=cover&scope=collection&list=${normalizedList}`
        + `&cate=${normalizedCate}&id=${String(subjectId)}&v=${encodeURIComponent(version)}`;
}

/**
 * 构造本站 Subject 卡片封面地址
 * @param {object} data
 * @param {number} subjectId
 * @returns {string}
 */
function buildSubjectCoverUrl(data, subjectId) {
    const version = String(data && data.cover_version || '');
    const base = String(window.bgmBase || '');
    if (!Number.isInteger(subjectId) || subjectId <= 0 || !/^[a-f0-9]{16}$/.test(version) || !base) {
        return '';
    }

    const separator = base.includes('?') ? '&' : '?';
    return `${base}${separator}type=cover&scope=subject&id=${String(subjectId)}`
        + `&v=${encodeURIComponent(version)}`;
}

/**
 * 完成单张背景封面的加载状态
 * @param {HTMLElement} card
 * @param {HTMLImageElement} image
 * @param {'loaded'|'error'} state
 */
function finishPosterCoverLoad(card, image, state) {
    if (PandaBangumiRuntime.coverLoads.get(card) !== image) return;

    PandaBangumiRuntime.coverLoads.delete(card);
    card.classList.remove('is-cover-pending', 'is-cover-loading');
    card.classList.add(state === 'loaded' ? 'is-cover-loaded' : 'is-cover-error');
    card.dataset.coverState = state;
    delete card.dataset.coverUrl;
}

/**
 * 预加载背景封面，成功后再应用到卡片
 * @param {HTMLElement} card
 */
function loadPosterCover(card) {
    if (!card || card.dataset.coverState === 'loading' || card.dataset.coverState === 'loaded') return;

    const imageUrl = String(card.dataset.coverUrl || '');
    const cover = card.querySelector('.bgm-poster-card__cover');
    if (!imageUrl || !cover) {
        card.classList.remove('is-cover-pending', 'is-cover-loading');
        card.classList.add('is-cover-error');
        card.dataset.coverState = 'error';
        delete card.dataset.coverUrl;
        return;
    }

    if (PandaBangumiRuntime.coverObserver) {
        PandaBangumiRuntime.coverObserver.unobserve(card);
    }

    card.dataset.coverState = 'loading';
    card.classList.remove('is-cover-pending', 'is-cover-error');
    card.classList.add('is-cover-loading');

    const image = new Image();
    image.decoding = 'async';
    image.onload = () => {
        const applyCover = () => {
            if (!card.isConnected || PandaBangumiRuntime.coverLoads.get(card) !== image) return;
            cover.style.backgroundImage = `url("${imageUrl}")`;
            finishPosterCoverLoad(card, image, 'loaded');
        };

        if (typeof image.decode === 'function') {
            image.decode().then(applyCover, applyCover);
        } else {
            applyCover();
        }
    };
    image.onerror = () => finishPosterCoverLoad(card, image, 'error');
    PandaBangumiRuntime.coverLoads.set(card, image);
    image.src = imageUrl;
}

/**
 * 确认卡片当前仍处于封面预加载范围内
 * @param {HTMLElement} card
 * @returns {boolean}
 */
function isPosterCoverWithinRange(card) {
    if (!card || !card.isConnected || card.getClientRects().length === 0) return false;

    const rect = card.getBoundingClientRect();
    const margin = PandaBangumiRuntime.coverPreloadMargin;
    return rect.bottom >= -margin
        && rect.top <= window.innerHeight + margin
        && rect.right >= 0
        && rect.left <= window.innerWidth;
}

/**
 * 获取共享封面观察器
 * @returns {IntersectionObserver|null}
 */
function getCoverObserver() {
    if (!('IntersectionObserver' in window)) return null;
    if (PandaBangumiRuntime.coverObserver) return PandaBangumiRuntime.coverObserver;

    PandaBangumiRuntime.coverObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting && isPosterCoverWithinRange(entry.target)) {
                loadPosterCover(entry.target);
            }
        });
    }, {
        rootMargin: `${String(PandaBangumiRuntime.coverPreloadMargin)}px 0px`,
        threshold: 0.01
    });
    return PandaBangumiRuntime.coverObserver;
}

/**
 * 将封面卡片加入视口懒加载
 * @param {HTMLElement} card
 */
function observePosterCover(card) {
    if (!card || !card.dataset.coverUrl || card.dataset.coverState === 'loaded' || card.dataset.coverState === 'loading') return;

    card.dataset.coverState = 'pending';
    card.classList.add('is-cover-pending');
    const observer = getCoverObserver();
    if (observer) {
        observer.observe(card);
    } else {
        loadPosterCover(card);
    }
}

/**
 * 停止当前页面的封面观察与预加载
 */
function disconnectCoverLoading() {
    if (PandaBangumiRuntime.coverObserver) {
        PandaBangumiRuntime.coverObserver.disconnect();
        PandaBangumiRuntime.coverObserver = null;
    }

    PandaBangumiRuntime.coverLoads.forEach((image, card) => {
        image.onload = null;
        image.onerror = null;
        image.removeAttribute('src');
        if (card.isConnected && card.dataset.coverUrl) {
            card.dataset.coverState = 'pending';
            card.classList.remove('is-cover-loading');
            card.classList.add('is-cover-pending');
        }
    });
    PandaBangumiRuntime.coverLoads.clear();
}

/**
 * 激活指定星期面板的视口懒加载
 * @param {HTMLElement} panel
 */
function loadCalendarPanelImages(panel) {
    if (!panel) return;

    panel.querySelectorAll('.bgm-poster-card[data-cover-url]').forEach(item => {
        observePosterCover(item);
    });
}

/**
 * 等待批量内容完成布局
 * @returns {Promise<void>}
 */
function waitForStableLayout() {
    return new Promise(resolve => {
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(resolve);
        });
    });
}

/**
 * 设置移动端星期选择器的展开状态
 * @param {HTMLElement} picker
 * @param {boolean} expanded
 */
function setWeekPickerExpanded(picker, expanded) {
    if (!picker) return;

    picker.classList.toggle('is-expanded', expanded);
    const trigger = picker.querySelector('.cal-week-trigger');
    if (trigger) {
        trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
}

/**
 * 获取 JSON 响应
 * @param {string} url
 * @param {AbortSignal} [signal]
 * @returns {Promise<any>}
 */
async function fetchJson(url, signal) {
    const response = await fetch(url, { signal });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    return response.json();
}

/**
 * 判断封面卡片是否展示收藏进度。
 * @param {string} type
 * @param {string} cate
 * @param {boolean} hasProgress
 * @returns {boolean}
 */
function shouldShowPosterProgress(type, cate, hasProgress) {
    return type !== 'calendar'
        && !isCompletedCollectionType(type, cate)
        && cate !== 'game'
        && hasProgress;
}

/**
 * 创建通用封面卡片
 * @param {object} item
 * @param {{type: string, cate?: string, imageUrl?: string}} options
 * @returns {HTMLElement}
 */
function createPosterCard(item, options) {
    const type = String(options && options.type || 'watched');
    const cate = normalizeCollectionCategory(options && options.cate);
    const href = safeHttpsUrl(item.url) || 'https://bgm.tv/';
    const localImageUrl = buildCollectionCoverUrl(item, type, cate);
    const requestedImageUrl = options && options.imageUrl
        || localImageUrl
        || item.img;
    const imageUrl = safeImageUrl(requestedImageUrl);
    const name = String(item.name || '');
    const displayName = String(item.name_cn || item.name || '');
    const count = Number(item.count || 0);
    const epStatus = Math.max(0, Number(item.status || 0));

    const link = document.createElement('a');
    link.className = `bgm-poster-card bgm-poster-card--${type}`;
    link.dataset.id = String(item.id || '');
    link.dataset.subjectType = cate;
    link.href = href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.title = name || displayName;

    const cover = document.createElement('span');
    cover.className = 'bgm-poster-card__cover';
    if (imageUrl) {
        link.dataset.coverUrl = imageUrl;
        link.dataset.coverState = 'pending';
        link.classList.add('is-cover-pending');
    }
    link.appendChild(cover);

    const overlay = document.createElement('span');
    overlay.className = 'bgm-poster-card__overlay';

    const title = document.createElement('span');
    title.className = 'bgm-poster-card__title';
    title.textContent = displayName;

    const score = Number(item.score || 0);
    if (isCompletedCollectionType(type, cate) && Number.isFinite(score) && score > 0) {
        const scoreEl = document.createElement('span');
        scoreEl.className = 'bgm-poster-card__score';
        scoreEl.textContent = `★ ${score.toFixed(1)}`;
        overlay.appendChild(scoreEl);
    }
    overlay.appendChild(title);

    const isBook = cate === 'book';
    const isMusic = cate === 'music';
    const current = isBook ? Math.max(0, Number(item.vol_status || 0)) : epStatus;
    const totalCount = isBook ? Math.max(0, Number(item.vol_count || 0)) : count;
    const hasProgress = !isBook || current > 0 || totalCount > 0;
    if (shouldShowPosterProgress(type, cate, hasProgress)) {
        const total = totalCount > 0 ? String(totalCount) : '未知';
        const unit = isBook ? ' 册' : isMusic ? ' 曲' : '';
        const progressText = document.createElement('span');
        progressText.className = 'bgm-poster-card__progress-text';
        progressText.textContent = `${String(current)} / ${total}${unit}`;
        overlay.appendChild(progressText);

        const progressTrack = document.createElement('span');
        progressTrack.className = 'bgm-poster-card__progress-track';

        const progressBar = document.createElement('span');
        progressBar.className = 'bgm-poster-card__progress-bar';
        progressBar.style.width = totalCount > 0 ? `${Math.min(100, current / totalCount * 100)}%` : '0';
        progressTrack.appendChild(progressBar);
        link.appendChild(progressTrack);
    }

    link.appendChild(overlay);
    return link;
}

/**
 * 设置列表操作卡状态
 * @param {HTMLElement} action
 * @param {'load'|'loading'|'retry'|'external'} state
 */
function setCollectionActionState(action, state) {
    const states = {
        load: { icon: '→', label: '加载更多' },
        loading: { icon: '', label: '加载中' },
        retry: { icon: '↻', label: '重新加载' },
        external: { icon: '↗', label: '在 Bangumi 查看更多' }
    };
    const content = states[state] || states.load;

    action.className = `bgm-collection-action bgm-collection-action--${state}`;
    action.dataset.bgmState = state;
    action.textContent = '';
    action.setAttribute('aria-label', content.label);
    if (action instanceof HTMLButtonElement) {
        action.disabled = state === 'loading';
        action.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
    }

    const icon = document.createElement('span');
    icon.className = 'bgm-collection-action__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = content.icon;
    action.appendChild(icon);

    const label = document.createElement('span');
    label.className = 'bgm-collection-action__label';
    label.textContent = content.label;
    action.appendChild(label);
}

/**
 * 创建可加载或重试的操作卡
 * @returns {HTMLButtonElement}
 */
function createCollectionActionButton() {
    const button = document.createElement('button');
    button.type = 'button';
    setCollectionActionState(button, 'loading');
    button.addEventListener('click', () => loadMoreBgm(button));
    return button;
}

/**
 * 创建 Bangumi 收藏页链接卡
 * @param {string} url
 * @returns {HTMLAnchorElement|null}
 */
function createCollectionExternalLink(url) {
    const href = safeHttpsUrl(url);
    if (!href) return null;

    const link = document.createElement('a');
    link.href = href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    setCollectionActionState(link, 'external');
    return link;
}

/**
 * 加载更多番剧条目
 * @param {HTMLButtonElement} action
 */
async function loadMoreBgm(action) {
    if (!action || action.dataset.bgmLoading === '1') return;
    const listEl = action.closest('.bgm-collection');
    if (!listEl) return;

    const restoreState = action.dataset.bgmState === 'retry' ? 'retry' : 'load';
    action.dataset.bgmLoading = '1';
    setCollectionActionState(action, 'loading');

    let offset = parseInt(listEl.dataset.bgmOffset || '0', 10);
    if (Number.isNaN(offset) || offset < 0) {
        offset = 0;
    }

    const cate = normalizeCollectionCategory(listEl.dataset.cate);
    const type = normalizeCollectionType(listEl.dataset.type, cate);
    const url = `${window.bgmBase}?from=${String(offset)}&type=${type}&cate=${cate}`;
    const controller = createRequestController();

    try {
        const data = await fetchJson(url, controller.signal);
        if (!action.isConnected || !listEl.isConnected) return;
        if (!data || !Array.isArray(data.items) || typeof data.has_more !== 'boolean') {
            throw new Error('返回的收藏分页数据无效');
        }

        const cards = [];
        const fragment = document.createDocumentFragment();
        data.items.forEach(item => {
            const card = createPosterCard(item, { type, cate });
            cards.push(card);
            fragment.appendChild(card);
        });
        listEl.insertBefore(fragment, action);
        await waitForStableLayout();
        cards.forEach(card => observePosterCover(card));

        const nextOffset = Number(data.next_offset);
        listEl.dataset.bgmOffset = String(Number.isInteger(nextOffset) && nextOffset >= offset
            ? nextOffset
            : offset + data.items.length);

        if (data.has_more) {
            setCollectionActionState(action, 'load');
        } else {
            const externalLink = createCollectionExternalLink(data.more_url);
            if (externalLink) {
                action.replaceWith(externalLink);
            } else {
                action.remove();
            }
        }
    } catch (error) {
        if (isAbortError(error)) {
            if (action.isConnected) {
                setCollectionActionState(action, restoreState);
            }
            return;
        }
        console.error('加载更多收藏失败:', error);
        if (action.isConnected) {
            setCollectionActionState(action, 'retry');
        }
    } finally {
        removeRequestController(controller);
        delete action.dataset.bgmLoading;
    }
}

/**
 * 加载并渲染标签页式追番日历
 * @param {HTMLElement} calContainer
 */
async function loadCalendar(calContainer) {
    if (!calContainer || calContainer.dataset.bgmLoaded === '1' || calContainer.dataset.bgmLoading === '1') return;
    calContainer.dataset.bgmLoading = '1';

    const calFilter = calContainer.getAttribute('data-filter') === 'watching' ? 'watching' : 'all';
    const previousElement = calContainer.previousElementSibling;
    const calendarHeading = previousElement && previousElement.matches('h1, h2, h3, h4, h5, h6')
        ? previousElement
        : null;
    const url = `${window.bgmBase}?type=calendar&filter=${calFilter}`;
    const controller = createRequestController();
    const getTodayId = () => {
        const jsDay = new Date().getDay();
        return jsDay === 0 ? 7 : jsDay;
    };

    try {
        const data = await fetchJson(url, controller.signal);
        if (!calContainer.isConnected) return;

        const todayId = getTodayId();
        calContainer.textContent = '';

        const tabs = document.createElement('div');
        tabs.className = 'cal-tabs';

        const weekPicker = document.createElement('div');
        weekPicker.className = 'cal-week-picker';

        const weekTrigger = document.createElement('button');
        weekTrigger.className = 'cal-week-trigger';
        weekTrigger.type = 'button';
        weekTrigger.setAttribute('aria-expanded', 'false');
        weekTrigger.setAttribute('aria-label', '选择星期');

        const weekTriggerLabel = document.createElement('span');
        weekTriggerLabel.className = 'cal-week-trigger__label';
        weekTrigger.appendChild(weekTriggerLabel);

        const weekTriggerChevron = document.createElement('span');
        weekTriggerChevron.className = 'cal-week-trigger__chevron';
        weekTriggerChevron.setAttribute('aria-hidden', 'true');
        weekTrigger.appendChild(weekTriggerChevron);

        const panels = document.createElement('div');
        panels.className = 'cal-panels';
        const shortWeekdayNames = ['', '周一', '周二', '周三', '周四', '周五', '周六', '周日'];

        (Array.isArray(data) ? data : []).forEach(day => {
            const dayId = String(day.id || '');
            const fullDayName = String(day.date_cn || '');
            const tabButton = document.createElement('button');
            tabButton.className = 'cal-tab-button';
            tabButton.type = 'button';
            tabButton.textContent = shortWeekdayNames[Number(day.id)] || fullDayName;
            tabButton.title = fullDayName;
            tabButton.setAttribute('aria-label', fullDayName);
            tabButton.dataset.dayId = dayId;
            if (Number(day.id) === todayId) {
                tabButton.classList.add('active', 'is-today');
                tabButton.setAttribute('aria-current', 'date');
            }
            tabButton.setAttribute('aria-pressed', Number(day.id) === todayId ? 'true' : 'false');
            tabs.appendChild(tabButton);

            const panel = document.createElement('div');
            panel.className = 'cal-panel';
            panel.dataset.dayId = dayId;
            if (Number(day.id) === todayId) {
                panel.classList.add('active');
            }

            const itemsArray = day.items ? Object.values(day.items) : [];
            if (itemsArray.length > 0) {
                itemsArray.forEach(item => {
                    const title = String(item.name_cn || item.name || '');
                    const href = safeHttpsUrl(item.url) || 'https://bgm.tv/';
                    const imageUrl = buildCalendarCoverUrl(item) || safeImageUrl(item.img);
                    const bangumiItem = createPosterCard({
                        id: item.id,
                        name: item.name,
                        name_cn: title,
                        url: href,
                        img: item.img
                    }, {
                        type: 'calendar',
                        imageUrl
                    });
                    panel.appendChild(bangumiItem);
                });
            } else {
                const noItem = document.createElement('p');
                noItem.className = 'cal-no-item';
                noItem.textContent = '今天没有在追番哦～';
                panel.appendChild(noItem);
            }
            panels.appendChild(panel);
        });

        const calendarHeader = document.createElement('div');
        calendarHeader.className = 'bgm-calendar-header';
        if (calendarHeading) {
            calendarHeading.classList.add('bgm-calendar-title');
            calendarHeader.appendChild(calendarHeading);
        }
        const activeTab = tabs.querySelector('.cal-tab-button.active');
        if (activeTab) {
            weekTriggerLabel.textContent = activeTab.textContent;
            weekTrigger.setAttribute('aria-label', `选择星期，当前${activeTab.textContent}`);
            weekTrigger.classList.toggle('is-today', activeTab.classList.contains('is-today'));
        }

        weekPicker.appendChild(weekTrigger);
        weekPicker.appendChild(tabs);
        calendarHeader.appendChild(weekPicker);

        calContainer.appendChild(calendarHeader);
        calContainer.appendChild(panels);

        await waitForStableLayout();
        loadCalendarPanelImages(panels.querySelector('.cal-panel.active'));

        if (activeTab && !window.matchMedia('(max-width: 480px)').matches) {
            activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }

        weekTrigger.addEventListener('click', () => {
            setWeekPickerExpanded(weekPicker, !weekPicker.classList.contains('is-expanded'));
        });

        tabs.addEventListener('click', (e) => {
            if (e.target.matches('.cal-tab-button')) {
                const dayId = e.target.dataset.dayId;
                tabs.querySelectorAll('.cal-tab-button').forEach(btn => {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-pressed', 'false');
                });
                panels.querySelectorAll('.cal-panel').forEach(pnl => pnl.classList.remove('active'));
                e.target.classList.add('active');
                e.target.setAttribute('aria-pressed', 'true');
                weekTriggerLabel.textContent = e.target.textContent;
                weekTrigger.setAttribute('aria-label', `选择星期，当前${e.target.textContent}`);
                weekTrigger.classList.toggle('is-today', e.target.classList.contains('is-today'));
                const targetPanel = Array.from(panels.querySelectorAll('.cal-panel')).find(panel => panel.dataset.dayId === dayId);
                if (targetPanel) {
                    targetPanel.classList.add('active');
                    loadCalendarPanelImages(targetPanel);
                }
                setWeekPickerExpanded(weekPicker, false);
                if (window.matchMedia('(max-width: 480px)').matches) {
                    weekTrigger.focus({ preventScroll: true });
                }
            }
        });
        calContainer.dataset.bgmLoaded = '1';
    } catch (error) {
        if (isAbortError(error)) return;
        console.error('加载日历失败:', error);
        if (calContainer.isConnected) {
            calContainer.textContent = '';
            const errorEl = document.createElement('p');
            errorEl.className = 'error';
            errorEl.textContent = '加载日历失败，请刷新页面。';
            calContainer.appendChild(errorEl);
            delete calContainer.dataset.bgmLoaded;
        }
    } finally {
        removeRequestController(controller);
        delete calContainer.dataset.bgmLoading;
    }
}

/**
 * 加载 Bangumi 条目卡片信息
 */
async function loadBgmCard() {
    const cards = document.querySelectorAll('.bgm-card:not([data-bgm-loaded="1"]):not([data-bgm-loading="1"])');

    for (const card of cards) {
        const id = card.getAttribute('data-id');
        if (id) await renderCard(id, card);
    }
}

/**
 * 根据 Subject ID 渲染 Bangumi 条目卡片
 *
 * @param {number|string} subjectId
 * @param {HTMLElement} cardElement
 */
async function renderCard(subjectId, cardElement) {
    if (!cardElement || cardElement.dataset.bgmLoaded === '1' || cardElement.dataset.bgmLoading === '1') return;

    cardElement.dataset.bgmLoading = '1';
    cardElement.setAttribute('aria-busy', 'true');
    cardElement.textContent = '';
    const loading = document.createElement('div');
    loading.className = 'bgm-subject-card-state bgm-subject-card-state--loading';
    loading.setAttribute('role', 'status');

    const loadingText = document.createElement('span');
    loadingText.className = 'bgm-subject-card__sr-only';
    loadingText.textContent = '正在从 Bangumi 加载条目信息...';
    loading.appendChild(loadingText);

    const loadingPoster = document.createElement('span');
    loadingPoster.className = 'bgm-subject-card-state__poster';
    loading.setAttribute('aria-label', loadingText.textContent);
    loading.appendChild(loadingPoster);

    const loadingContent = document.createElement('span');
    loadingContent.className = 'bgm-subject-card-state__content';
    ['short', 'title', 'subtitle', 'summary', 'meta'].forEach(size => {
        const line = document.createElement('span');
        line.className = `bgm-subject-card-state__line bgm-subject-card-state__line--${size}`;
        loadingContent.appendChild(line);
    });
    loading.appendChild(loadingContent);
    cardElement.appendChild(loading);

    const safeSubjectId = parseInt(subjectId, 10);
    if (!Number.isInteger(safeSubjectId) || safeSubjectId <= 0) {
        renderCardError(cardElement, subjectId);
        delete cardElement.dataset.bgmLoading;
        return;
    }
    const controller = createRequestController();

    try {
        const data = await fetchJson(`${window.bgmBase}?type=subject&id=${String(safeSubjectId)}`, controller.signal);
        if (!cardElement.isConnected) return;

        if (data.id === safeSubjectId) {
            cardElement.textContent = '';
            const subjectCard = buildCardElement(data, safeSubjectId);
            cardElement.dataset.subjectType = subjectCard.dataset.subjectType;
            cardElement.appendChild(subjectCard);
            cardElement.dataset.bgmLoaded = '1';
            cardElement.setAttribute('aria-busy', 'false');
        } else {
            throw new Error('返回的 Bangumi 条目数据无效');
        }
    } catch (error) {
        if (isAbortError(error)) return;
        console.error('Error fetching data:', error);
        if (cardElement.isConnected) {
            renderCardError(cardElement, subjectId);
        }
    } finally {
        removeRequestController(controller);
        delete cardElement.dataset.bgmLoading;
    }
}

/**
 * 渲染卡片错误状态
 * @param {HTMLElement} cardElement
 * @param {number|string} subjectId
 */
function renderCardError(cardElement, subjectId) {
    delete cardElement.dataset.bgmLoaded;
    delete cardElement.dataset.bgmLoading;
    delete cardElement.dataset.subjectType;
    cardElement.setAttribute('aria-busy', 'false');
    cardElement.textContent = '';
    const errorEl = document.createElement('div');
    errorEl.className = 'bgm-subject-card-state bgm-subject-card-state--error';
    errorEl.setAttribute('role', 'status');
    errorEl.textContent = `无法加载条目信息。请检查 Subject ID (${String(subjectId)}) 或网络连接。`;
    cardElement.appendChild(errorEl);
}

/**
 * 从 infobox 数组中查找特定键的值
 * @param {Array} infobox
 * @param {string} key
 * @returns {string}
 */
function findInfo(infobox, key) {
    if (!Array.isArray(infobox)) return '';
    const info = infobox.find(item => item && item.key === key);
    if (!info || info.value === null || typeof info.value === 'undefined') return '';

    const values = Array.isArray(info.value) ? info.value : [info.value];
    return values.map(value => {
        if (value === null || typeof value === 'undefined') return '';
        if (typeof value === 'object') {
            return String(value.v || value.k || '');
        }
        return String(value);
    }).filter(Boolean).join('、');
}

/**
 * 压缩 Subject 文本中的连续空白
 * @param {unknown} value
 * @returns {string}
 */
function normalizeSubjectText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

/**
 * 为计数值补充单位
 * @param {unknown} value
 * @param {string} unit
 * @returns {string}
 */
function formatSubjectCount(value, unit) {
    const text = normalizeSubjectText(value);
    if (!text) return '';
    return text.endsWith(unit) ? text : `${text} ${unit}`;
}

/**
 * 获取音乐条目的首要创作者或厂牌
 * @param {Array} infobox
 * @returns {{text: string, label: string, value: string}}
 */
function findMusicCredit(infobox) {
    const keys = {
        '艺术家': 'ARTIST',
        '演唱': 'VOCALS',
        '表演者': 'PERFORMER',
        '主唱': 'VOCALS',
        '作曲': 'COMPOSER',
        '编曲': 'ARRANGER',
        '厂牌': 'LABEL'
    };
    for (const [key, label] of Object.entries(keys)) {
        const value = normalizeSubjectText(findInfo(infobox, key));
        if (value) return { text: `${key} ${value}`, label, value };
    }
    return { text: '', label: '', value: '' };
}

/**
 * 将 RGB 转为 HSL。
 * @param {number} red
 * @param {number} green
 * @param {number} blue
 * @returns {{hue: number, saturation: number, lightness: number}}
 */
function rgbToHsl(red, green, blue) {
    const r = red / 255;
    const g = green / 255;
    const b = blue / 255;
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const lightness = (max + min) / 2;
    const delta = max - min;
    if (delta === 0) return { hue: 0, saturation: 0, lightness };

    const saturation = delta / (1 - Math.abs(2 * lightness - 1));
    let hue;
    if (max === r) hue = ((g - b) / delta) % 6;
    else if (max === g) hue = (b - r) / delta + 2;
    else hue = (r - g) / delta + 4;
    hue = ((hue * 60) + 360) % 360;
    return { hue, saturation, lightness };
}

/**
 * 将 HSL 转为 RGB。
 * @param {number} hue
 * @param {number} saturation
 * @param {number} lightness
 * @returns {number[]}
 */
function hslToRgb(hue, saturation, lightness) {
    const chroma = (1 - Math.abs(2 * lightness - 1)) * saturation;
    const segment = hue / 60;
    const component = chroma * (1 - Math.abs((segment % 2) - 1));
    const values = segment < 1 ? [chroma, component, 0]
        : segment < 2 ? [component, chroma, 0]
            : segment < 3 ? [0, chroma, component]
                : segment < 4 ? [0, component, chroma]
                    : segment < 5 ? [component, 0, chroma]
                        : [chroma, 0, component];
    const offset = lightness - chroma / 2;
    return values.map(value => Math.round((value + offset) * 255));
}

/**
 * 计算 RGB 的相对亮度。
 * @param {number} red
 * @param {number} green
 * @param {number} blue
 * @returns {number}
 */
function relativeLuminance(red, green, blue) {
    const channels = [red, green, blue].map(value => {
        const channel = value / 255;
        return channel <= 0.04045
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4;
    });
    return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
}

/**
 * 从缩采样像素中选取适合作为白字背景的代表色。
 * @param {ArrayLike<number>} pixels
 * @returns {string}
 */
function pickMusicObiColor(pixels) {
    const buckets = Array.from({ length: 18 }, () => ({ weight: 0, red: 0, green: 0, blue: 0 }));
    for (let index = 0; index < pixels.length; index += 4) {
        if (pixels[index + 3] < 200) continue;
        const red = pixels[index];
        const green = pixels[index + 1];
        const blue = pixels[index + 2];
        const hsl = rgbToHsl(red, green, blue);
        if (hsl.lightness < 0.07 || hsl.lightness > 0.93 || hsl.saturation < 0.14) continue;

        const weight = (0.35 + hsl.saturation * 1.65)
            * (1 - Math.abs(hsl.lightness - 0.5) * 0.8);
        const bucket = buckets[Math.floor(hsl.hue / 20) % buckets.length];
        bucket.weight += weight;
        bucket.red += red * weight;
        bucket.green += green * weight;
        bucket.blue += blue * weight;
    }

    const winner = buckets.reduce((best, bucket) => bucket.weight > best.weight ? bucket : best);
    if (winner.weight === 0) return '';

    const average = [winner.red, winner.green, winner.blue]
        .map(value => Math.round(value / winner.weight));
    const hsl = rgbToHsl(average[0], average[1], average[2]);
    const saturation = Math.min(0.7, Math.max(0.38, hsl.saturation));
    let lightness = Math.min(0.4, Math.max(0.2, hsl.lightness));
    let rgb = hslToRgb(hsl.hue, saturation, lightness);

    while ((1.05 / (relativeLuminance(rgb[0], rgb[1], rgb[2]) + 0.05)) < 4.5 && lightness > 0.12) {
        lightness -= 0.02;
        rgb = hslToRgb(hsl.hue, saturation, lightness);
    }
    return `hsl(${Math.round(hsl.hue)}, ${Math.round(saturation * 100)}%, ${Math.round(lightness * 100)}%)`;
}

/**
 * 仅对本站同源封面提取 Obi 颜色。
 * @param {HTMLElement} card
 * @param {HTMLImageElement} image
 * @param {string} imageUrl
 */
function applyMusicObiColor(card, image, imageUrl) {
    if (!card || !image || !imageUrl || !window.location) return;
    let parsedUrl;
    try {
        parsedUrl = new URL(imageUrl, window.location.href);
    } catch (error) {
        return;
    }
    if (parsedUrl.origin !== window.location.origin) return;

    const cacheKey = parsedUrl.href;
    const cached = PandaBangumiRuntime.musicObiColors.get(cacheKey);
    if (cached) {
        card.style.setProperty('--pb-music-obi-color', cached);
        return;
    }

    try {
        const canvas = document.createElement('canvas');
        canvas.width = 24;
        canvas.height = 24;
        const context = canvas.getContext('2d');
        if (!context) return;
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const color = pickMusicObiColor(context.getImageData(0, 0, canvas.width, canvas.height).data);
        if (!color) return;
        PandaBangumiRuntime.musicObiColors.set(cacheKey, color);
        card.style.setProperty('--pb-music-obi-color', color);
    } catch (error) {
        // 直连图片或不支持 Canvas 的浏览器继续使用默认色。
    }
}

/**
 * 将 Bangumi Subject 数据标准化为卡片展示模型
 * @param {object} data
 * @param {number} subjectId
 * @returns {object}
 */
function normalizeSubjectCardData(data, subjectId) {
    const source = data && typeof data === 'object' ? data : {};
    const typeNumber = Number(source.type || 0);
    const typeConfig = {
        1: { key: 'book', label: '书籍' },
        2: { key: 'anime', label: '动画' },
        3: { key: 'music', label: '音乐' },
        4: { key: 'game', label: '游戏' },
        6: { key: 'real', label: '三次元' }
    }[typeNumber] || { key: 'unknown', label: '条目' };

    const title = normalizeSubjectText(source.name_cn || source.name) || '未命名条目';
    const originalTitle = source.name_cn ? normalizeSubjectText(source.name) : '';
    const rating = source.rating && typeof source.rating === 'object' ? source.rating : {};
    const scoreValue = Number(rating.score || 0);
    const ratingCountValue = Number(rating.total);
    const collectionCountValue = Number(source.collection && source.collection.collect);
    let primaryMeta = '';
    let secondaryMeta = '';
    let musicCredit = { text: '', label: '', value: '' };

    if (typeNumber === 1) {
        const volumeCount = Number(source.volumes || 0);
        const volumes = volumeCount > 0 ? String(volumeCount) : findInfo(source.infobox, '册数');
        primaryMeta = formatSubjectCount(volumes, '册');
    } else if (typeNumber === 2) {
        primaryMeta = formatSubjectCount(
            findInfo(source.infobox, '话数') || (Number(source.total_episodes || 0) > 0 ? source.total_episodes : ''),
            '话'
        );
    } else if (typeNumber === 3) {
        const trackCount = Number(source.total_episodes || 0);
        primaryMeta = formatSubjectCount(
            trackCount > 0 ? trackCount : findInfo(source.infobox, '曲目数量'),
            '曲'
        );
        secondaryMeta = formatSubjectCount(findInfo(source.infobox, '碟片数量'), '碟');
        musicCredit = findMusicCredit(source.infobox);
    } else if (typeNumber === 4) {
        primaryMeta = normalizeSubjectText(findInfo(source.infobox, '平台') || source.platform);
    } else if (typeNumber === 6) {
        primaryMeta = formatSubjectCount(
            findInfo(source.infobox, '集数') || (Number(source.total_episodes || 0) > 0 ? source.total_episodes : ''),
            '集'
        );
    }

    const tags = [];
    (Array.isArray(source.tags) ? source.tags : []).forEach(tag => {
        const name = normalizeSubjectText(tag && tag.name);
        if (name && !tags.includes(name) && tags.length < 3) tags.push(name);
    });

    return {
        id: subjectId,
        typeKey: typeConfig.key,
        typeLabel: typeConfig.label,
        title,
        originalTitle: originalTitle !== title ? originalTitle : '',
        summary: normalizeSubjectText(source.summary),
        posterUrl: buildSubjectCoverUrl(source, subjectId)
            || safeImageUrl(source.images && source.images.large),
        date: normalizeSubjectText(source.date),
        score: Number.isFinite(scoreValue) && scoreValue > 0 ? scoreValue.toFixed(1) : '暂无评分',
        hasScore: Number.isFinite(scoreValue) && scoreValue > 0,
        ratingCount: Number.isFinite(ratingCountValue) && ratingCountValue >= 0
            ? Math.floor(ratingCountValue)
            : null,
        collectionCount: Number.isFinite(collectionCountValue) && collectionCountValue >= 0
            ? Math.floor(collectionCountValue)
            : null,
        primaryMeta,
        secondaryMeta,
        musicCredit: musicCredit.text,
        musicCreditLabel: musicCredit.label,
        musicCreditValue: musicCredit.value,
        tags
    };
}

/**
 * 创建卡片元素
 * @param {object} data
 * @param {number} subjectId
 * @returns {HTMLElement}
 */
function buildCardElement(data, subjectId) {
    const cardData = normalizeSubjectCardData(data, subjectId);
    const bangumiUrl = `https://bgm.tv/subject/${subjectId}`;
    const isMusic = cardData.typeKey === 'music';

    const wrapper = document.createElement('a');
    wrapper.href = bangumiUrl;
    wrapper.target = '_blank';
    wrapper.rel = 'noopener noreferrer';
    wrapper.className = 'bgm-subject-card';
    wrapper.dataset.subjectType = cardData.typeKey;
    wrapper.setAttribute('aria-label', `${cardData.typeLabel}《${cardData.title}》，在 Bangumi 查看`);

    if (isMusic) {
        const obi = document.createElement('div');
        obi.className = 'bgm-subject-card__obi';
        obi.setAttribute('aria-hidden', 'true');

        const obiLabel = document.createElement('span');
        obiLabel.className = 'bgm-subject-card__obi-label';
        obiLabel.textContent = 'Music Album';
        obi.appendChild(obiLabel);

        const obiId = document.createElement('span');
        obiId.className = 'bgm-subject-card__obi-id';
        obiId.textContent = `Bangumi Subject ${subjectId}`;
        obi.appendChild(obiId);

        const releaseYear = cardData.date.match(/^\d{4}/);
        if (releaseYear) {
            const obiYear = document.createElement('span');
            obiYear.className = 'bgm-subject-card__obi-year';
            obiYear.textContent = releaseYear[0];
            obi.appendChild(obiYear);
        }
        wrapper.appendChild(obi);
    }

    const poster = document.createElement('div');
    poster.className = 'bgm-subject-card__poster';
    const img = document.createElement('img');
    img.className = 'bgm-subject-card__image';
    img.alt = `${cardData.title} 封面`;
    img.loading = 'lazy';
    img.decoding = 'async';
    if (cardData.posterUrl) {
        poster.classList.add('is-cover-loading');
        img.addEventListener('load', () => {
            poster.classList.remove('is-cover-loading', 'is-cover-error');
            poster.classList.add('is-cover-loaded');
            if (isMusic) applyMusicObiColor(wrapper, img, cardData.posterUrl);
        }, { once: true });
        img.addEventListener('error', () => {
            poster.classList.remove('is-cover-loading', 'is-cover-loaded');
            poster.classList.add('is-cover-error');
        }, { once: true });
        img.src = cardData.posterUrl;
    } else {
        poster.classList.add('is-cover-error');
    }
    poster.appendChild(img);
    if (isMusic) {
        const caseEl = document.createElement('div');
        caseEl.className = 'bgm-subject-card__case';
        caseEl.appendChild(poster);
        wrapper.appendChild(caseEl);
    } else {
        wrapper.appendChild(poster);
    }

    const content = document.createElement('div');
    content.className = 'bgm-subject-card__content';

    const header = document.createElement('div');
    header.className = 'bgm-subject-card__header';

    const eyebrow = document.createElement('div');
    eyebrow.className = 'bgm-subject-card__eyebrow';

    const type = document.createElement('span');
    type.className = isMusic ? 'bgm-subject-card__kind' : 'bgm-subject-card__type';
    type.textContent = isMusic ? 'ALBUM' : cardData.typeLabel;
    eyebrow.appendChild(type);

    if (cardData.date) {
        const date = document.createElement('time');
        date.className = 'bgm-subject-card__date';
        date.dateTime = cardData.date;
        date.textContent = cardData.date;
        eyebrow.appendChild(date);
    }
    header.appendChild(eyebrow);

    const ratingArea = document.createElement('div');
    ratingArea.className = 'bgm-subject-card__rating';

    const scoreEl = document.createElement('span');
    scoreEl.className = 'bgm-subject-card__score';
    if (!cardData.hasScore) scoreEl.classList.add('is-empty');
    scoreEl.textContent = cardData.score;
    ratingArea.appendChild(scoreEl);

    if (cardData.ratingCount !== null && cardData.hasScore) {
        const ratingCountEl = document.createElement('span');
        ratingCountEl.className = 'bgm-subject-card__rating-count';
        ratingCountEl.textContent = `${cardData.ratingCount} 人评分`;
        ratingArea.appendChild(ratingCountEl);
    }

    const external = document.createElement('span');
    external.className = 'bgm-subject-card__external';
    external.setAttribute('aria-hidden', 'true');
    external.textContent = '↗';
    ratingArea.appendChild(external);
    header.appendChild(ratingArea);
    content.appendChild(header);

    const title = document.createElement('h3');
    title.className = 'bgm-subject-card__title';
    title.textContent = cardData.title;
    content.appendChild(title);

    if (isMusic && (cardData.musicCreditValue || cardData.originalTitle)) {
        const credit = document.createElement('div');
        credit.className = 'bgm-subject-card__credit';

        const creditLabel = document.createElement('span');
        creditLabel.className = 'bgm-subject-card__credit-label';
        creditLabel.textContent = cardData.musicCreditLabel || 'ORIGINAL';
        credit.appendChild(creditLabel);

        const creditName = document.createElement('span');
        creditName.className = 'bgm-subject-card__credit-name';
        creditName.textContent = cardData.musicCreditValue || cardData.originalTitle;
        credit.appendChild(creditName);
        content.appendChild(credit);
    } else if (cardData.originalTitle) {
        const subtitle = document.createElement('p');
        subtitle.className = 'bgm-subject-card__subtitle';
        subtitle.textContent = cardData.originalTitle;
        content.appendChild(subtitle);
    }

    if (cardData.summary) {
        const summary = document.createElement('p');
        summary.className = 'bgm-subject-card__summary';
        summary.textContent = cardData.summary;
        content.appendChild(summary);
    }

    const footer = document.createElement('div');
    footer.className = 'bgm-subject-card__footer';

    if (isMusic) {
        const specs = document.createElement('div');
        specs.className = 'bgm-subject-card__specs';
        if (cardData.primaryMeta) appendMusicSpec(specs, 'TRACKS', cardData.primaryMeta, 'tracks');
        if (cardData.secondaryMeta) appendMusicSpec(specs, 'DISCS', cardData.secondaryMeta, 'discs');
        if (cardData.collectionCount !== null) {
            appendMusicSpec(specs, 'COLLECTIONS', `${cardData.collectionCount} 人`, 'collection');
        }
        if (specs.childElementCount > 0) footer.appendChild(specs);
    } else {
        const meta = document.createElement('div');
        meta.className = 'bgm-subject-card__meta';
        if (cardData.primaryMeta) appendMetaItem(meta, cardData.primaryMeta, 'primary');
        if (cardData.secondaryMeta) appendMetaItem(meta, cardData.secondaryMeta, 'secondary');
        if (cardData.collectionCount !== null) {
            appendMetaItem(meta, `${cardData.collectionCount} 人收藏`, 'collection');
        }
        if (meta.childElementCount > 0) footer.appendChild(meta);
    }

    if (cardData.tags.length > 0) {
        const tags = document.createElement('div');
        tags.className = 'bgm-subject-card__tags';
        cardData.tags.forEach(tag => {
            const tagEl = document.createElement('span');
            tagEl.className = 'bgm-subject-card__tag';
            tagEl.textContent = tag;
            tags.appendChild(tagEl);
        });
        footer.appendChild(tags);
    }

    content.appendChild(footer);
    wrapper.appendChild(content);
    return wrapper;
}

/**
 * 追加音乐专辑规格。
 * @param {HTMLElement} container
 * @param {string} label
 * @param {string} value
 * @param {string} kind
 */
function appendMusicSpec(container, label, value, kind) {
    const spec = document.createElement('span');
    spec.className = `bgm-subject-card__spec bgm-subject-card__spec--${kind}`;

    const labelEl = document.createElement('span');
    labelEl.className = 'bgm-subject-card__spec-label';
    labelEl.textContent = label;
    spec.appendChild(labelEl);

    const valueEl = document.createElement('span');
    valueEl.className = 'bgm-subject-card__spec-value';
    valueEl.textContent = value;
    spec.appendChild(valueEl);
    container.appendChild(spec);
}

/**
 * 追加元信息
 * @param {HTMLElement} container
 * @param {string} text
 * @param {string} [kind]
 */
function appendMetaItem(container, text, kind) {
    const item = document.createElement('span');
    item.className = 'bgm-subject-card__meta-item';
    if (kind) item.classList.add(`bgm-subject-card__meta-item--${kind}`);
    item.textContent = String(text);
    container.appendChild(item);
}

/**
 * 初始化所有番剧列表
 */
async function initCollection() {
    const initialLoads = [];
    Array.from(document.querySelectorAll('.bgm-collection:not([data-bgm-initialized="1"])')).forEach(item => {
        item.dataset.bgmInitialized = '1';
        item.dataset.bgmOffset = '0';
        item.dataset.cate = normalizeCollectionCategory(item.dataset.cate);
        item.dataset.type = normalizeCollectionType(item.dataset.type, item.dataset.cate);

        const action = createCollectionActionButton();
        item.appendChild(action);
        initialLoads.push(loadMoreBgm(action));
    });

    document.querySelectorAll('.bgm-calendar').forEach(calendar => {
        initialLoads.push(loadCalendar(calendar));
    });

    initialLoads.push(loadBgmCard());
    await Promise.allSettled(initialLoads);
}

/**
 * 防抖调度初始化
 */
function scheduleInit() {
    if (PandaBangumiRuntime.initTimer) {
        clearTimeout(PandaBangumiRuntime.initTimer);
    }
    PandaBangumiRuntime.initTimer = setTimeout(() => {
        PandaBangumiRuntime.initTimer = null;
        initCollection();
    }, 30);
}

PandaBangumiRuntime.init = scheduleInit;

if (!PandaBangumiRuntime.bound) {
    PandaBangumiRuntime.bound = true;

    ['DOMContentLoaded', 'pjax:complete', 'pjax:end', 'pjax:success', 'turbo:load', 'turbolinks:load'].forEach(eventName => {
        document.addEventListener(eventName, scheduleInit);
    });

    ['pjax:send', 'pjax:beforeReplace', 'turbo:before-render'].forEach(eventName => {
        document.addEventListener(eventName, abortPendingRequests);
    });

    document.addEventListener('click', event => {
        document.querySelectorAll('.cal-week-picker.is-expanded').forEach(picker => {
            if (!picker.contains(event.target)) {
                setWeekPickerExpanded(picker, false);
            }
        });
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;

        document.querySelectorAll('.cal-week-picker.is-expanded').forEach(picker => {
            setWeekPickerExpanded(picker, false);
            const trigger = picker.querySelector('.cal-week-trigger');
            if (trigger) trigger.focus({ preventScroll: true });
        });
    });

    if (document.readyState !== 'loading') {
        scheduleInit();
    }
}
