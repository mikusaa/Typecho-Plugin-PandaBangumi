/**
 * PandaBangumi 全局状态
 */
var PandaBangumiRuntime = window.PandaBangumi || {};
PandaBangumiRuntime.controllers = PandaBangumiRuntime.controllers || new Set();
PandaBangumiRuntime.initTimer = PandaBangumiRuntime.initTimer || null;
PandaBangumiRuntime.bound = PandaBangumiRuntime.bound || false;
PandaBangumiRuntime.coverObserver = PandaBangumiRuntime.coverObserver || null;
PandaBangumiRuntime.coverLoads = PandaBangumiRuntime.coverLoads || new Map();
window.PandaBangumi = PandaBangumiRuntime;

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
 * 校验 HTTPS URL，并升级 Bangumi 官方封面地址
 * @param {string} value
 * @returns {string}
 */
function safeHttpsUrl(value) {
    const raw = String(value || '').trim();
    try {
        const url = new URL(raw);
        if (url.protocol === 'http:' && url.hostname === 'lain.bgm.tv') {
            url.protocol = 'https:';
        }

        return url.protocol === 'https:' ? url.href : '';
    } catch (error) {
        return '';
    }
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
 * 获取共享封面观察器
 * @returns {IntersectionObserver|null}
 */
function getCoverObserver() {
    if (!('IntersectionObserver' in window)) return null;
    if (PandaBangumiRuntime.coverObserver) return PandaBangumiRuntime.coverObserver;

    PandaBangumiRuntime.coverObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                loadPosterCover(entry.target);
            }
        });
    }, {
        rootMargin: '300px 0px',
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
    panel.dataset.imagesLoaded = '1';
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
 * 创建通用封面卡片
 * @param {object} item
 * @param {{type: string, imageUrl?: string, deferCover?: boolean}} options
 * @returns {HTMLElement}
 */
function createPosterCard(item, options) {
    const type = String(options && options.type || 'watched');
    const href = safeHttpsUrl(item.url) || 'https://bgm.tv/';
    const requestedImageUrl = options && options.imageUrl || item.img;
    const imageUrl = options && options.deferCover
        ? String(requestedImageUrl || '')
        : safeHttpsUrl(requestedImageUrl);
    const name = String(item.name || '');
    const displayName = String(item.name_cn || item.name || '');
    const count = Number(item.count || 0);
    const epStatus = Math.max(0, Number(item.status || 0));

    const link = document.createElement('a');
    link.className = `bgm-poster-card bgm-poster-card--${type}`;
    link.dataset.id = String(item.id || '');
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
    if (type === 'watched' && Number.isFinite(score) && score > 0) {
        const scoreEl = document.createElement('span');
        scoreEl.className = 'bgm-poster-card__score';
        scoreEl.textContent = `★ ${score.toFixed(1)}`;
        overlay.appendChild(scoreEl);
    }
    overlay.appendChild(title);

    if (type === 'watching') {
        const total = count > 0 ? String(count) : '未知';
        const progressText = document.createElement('span');
        progressText.className = 'bgm-poster-card__progress-text';
        progressText.textContent = `${String(epStatus)} / ${total}`;
        overlay.appendChild(progressText);

        const progressTrack = document.createElement('span');
        progressTrack.className = 'bgm-poster-card__progress-track';

        const progressBar = document.createElement('span');
        progressBar.className = 'bgm-poster-card__progress-bar';
        progressBar.style.width = count > 0 ? `${Math.min(100, epStatus / count * 100)}%` : '0';
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

    action.dataset.bgmLoading = '1';
    setCollectionActionState(action, 'loading');

    let offset = parseInt(listEl.dataset.bgmOffset || '0', 10);
    if (Number.isNaN(offset) || offset < 0) {
        offset = 0;
    }

    const type = listEl.dataset.type === 'watched' ? 'watched' : 'watching';
    const cate = listEl.dataset.cate === 'real' ? 'real' : 'anime';
    const url = `${window.bgmBase}?from=${String(offset)}&type=${type}&cate=${cate}`;
    const controller = createRequestController();

    try {
        const data = await fetchJson(url, controller.signal);
        if (!action.isConnected || !listEl.isConnected) return;
        if (!data || !Array.isArray(data.items) || typeof data.has_more !== 'boolean') {
            throw new Error('返回的收藏分页数据无效');
        }

        data.items.forEach(item => {
            const card = createPosterCard(item, { type });
            listEl.insertBefore(card, action);
            observePosterCover(card);
        });

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
        if (isAbortError(error)) return;
        console.error('加载更多番剧失败:', error);
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
                    const imageUrl = buildCalendarCoverUrl(item);
                    const bangumiItem = createPosterCard({
                        id: item.id,
                        name: item.name,
                        name_cn: title,
                        url: href
                    }, {
                        type: 'calendar',
                        imageUrl,
                        deferCover: true
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
 * 加载番剧卡片信息
 */
async function loadBgmCard() {
    const cards = document.querySelectorAll('.bgm-card:not([data-bgm-loaded="1"]):not([data-bgm-loading="1"])');

    for (const card of cards) {
        const id = card.getAttribute('data-id');
        if (id) await renderCard(id, card);
    }
}

/**
 * 根据番剧ID渲染番剧卡片
 *
 * @param {number|string} subjectId
 * @param {HTMLElement} cardElement
 */
async function renderCard(subjectId, cardElement) {
    if (!cardElement || cardElement.dataset.bgmLoaded === '1' || cardElement.dataset.bgmLoading === '1') return;

    cardElement.dataset.bgmLoading = '1';
    cardElement.textContent = '';
    const loading = document.createElement('div');
    loading.className = 'loading-state';
    loading.textContent = '正在从 Bangumi 加载数据...';
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
            cardElement.appendChild(buildCardElement(data, safeSubjectId));
            cardElement.dataset.bgmLoaded = '1';
        } else {
            throw new Error('返回的番剧数据无效');
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
    cardElement.textContent = '';
    const errorEl = document.createElement('div');
    errorEl.className = 'error-state';
    errorEl.textContent = `无法加载番剧信息。请检查条目ID (${String(subjectId)}) 或网络连接。`;
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
    return (info && info.value) ? String(info.value) : '';
}

/**
 * 根据分数返回描述性文字
 * @param {number|null} score
 * @returns {string}
 */
function getScoreDescriptionJs(score) {
    if (isNaN(score) || score <= 0) {
        return '暂无评分';
    }

    switch (Math.floor(score)) {
        case 1: return '不忍直视';
        case 2: return '很差';
        case 3: return '差';
        case 4: return '较差';
        case 5: return '不过不失';
        case 6: return '还行';
        case 7: return '推荐';
        case 8: return '力荐';
        case 9: return '神作';
        case 10: return '神作';
        default: return '暂无评分';
    }
}

/**
 * 创建卡片元素
 * @param {object} data
 * @param {number} subjectId
 * @returns {HTMLElement}
 */
function buildCardElement(data, subjectId) {
    const nameCN = String(data.name_cn || data.name || '');
    const nameOriginal = data.name_cn ? String(data.name || '') : '';
    const posterUrl = safeHttpsUrl(data.images && data.images.large);
    const bangumiUrl = `https://bgm.tv/subject/${subjectId}`;
    const rating = data.rating || {};
    const scoreValue = Number(rating.score || 0);
    const score = scoreValue > 0 ? scoreValue.toFixed(1) : 'N/A';
    const ratingCount = Number(rating.total || 0);
    const airDate = String(data.date || '未知');
    const totalEpisodes = findInfo(data.infobox, '话数') || String(data.total_episodes || '未知');
    const collectionCount = Number(data.collection && data.collection.collect || 0);

    const wrapper = document.createElement('a');
    wrapper.href = bangumiUrl;
    wrapper.target = '_blank';
    wrapper.rel = 'noopener noreferrer';
    wrapper.className = 'bgm-card-link-wrapper';

    const poster = document.createElement('div');
    poster.className = 'bgm-card-poster';
    const img = document.createElement('img');
    img.alt = `${nameCN} Poster`;
    img.loading = 'lazy';
    img.decoding = 'async';
    if (posterUrl) {
        poster.classList.add('is-cover-loading');
        img.addEventListener('load', () => {
            poster.classList.remove('is-cover-loading', 'is-cover-error');
            poster.classList.add('is-cover-loaded');
        }, { once: true });
        img.addEventListener('error', () => {
            poster.classList.remove('is-cover-loading', 'is-cover-loaded');
            poster.classList.add('is-cover-error');
        }, { once: true });
        img.src = posterUrl;
    } else {
        poster.classList.add('is-cover-error');
    }
    poster.appendChild(img);
    wrapper.appendChild(poster);

    const content = document.createElement('div');
    content.className = 'bgm-card-content';

    const title = document.createElement('h3');
    title.className = 'bgm-card-title';
    title.textContent = nameCN;
    content.appendChild(title);

    const subtitle = document.createElement('p');
    subtitle.className = 'bgm-card-subtitle';
    subtitle.textContent = nameOriginal || '\u00a0';
    content.appendChild(subtitle);

    const meta = document.createElement('div');
    meta.className = 'bgm-card-meta';
    appendMetaItem(meta, 'icon-calendar', airDate);
    appendMetaItem(meta, 'icon-tv', `${totalEpisodes} 集`);
    appendMetaItem(meta, 'icon-collection', `${collectionCount} 人收藏`);
    content.appendChild(meta);

    const tags = document.createElement('div');
    tags.className = 'bgm-card-tags';
    (Array.isArray(data.tags) ? data.tags : []).slice(0, 7).forEach(tag => {
        const tagEl = document.createElement('span');
        tagEl.className = 'bgm-card-tag';
        tagEl.textContent = String(tag.name || '');
        tags.appendChild(tagEl);
    });
    content.appendChild(tags);

    const ratingArea = document.createElement('div');
    ratingArea.className = 'bgm-card-rating-area';

    const scoreEl = document.createElement('span');
    scoreEl.className = 'bgm-card-score';
    scoreEl.textContent = score;
    ratingArea.appendChild(scoreEl);

    const scoreText = document.createElement('span');
    scoreText.className = 'bgm-card-score-text';
    scoreText.textContent = getScoreDescriptionJs(scoreValue);
    ratingArea.appendChild(scoreText);

    const ratingCountEl = document.createElement('span');
    ratingCountEl.className = 'bgm-card-rating-count';
    ratingCountEl.textContent = `(${ratingCount}人评分)`;
    ratingArea.appendChild(ratingCountEl);

    content.appendChild(ratingArea);
    wrapper.appendChild(content);
    return wrapper;
}

/**
 * 追加元信息
 * @param {HTMLElement} container
 * @param {string} iconClass
 * @param {string} text
 */
function appendMetaItem(container, iconClass, text) {
    const item = document.createElement('span');
    item.className = 'meta-item';

    const icon = document.createElement('i');
    icon.className = `meta-icon ${iconClass}`;
    item.appendChild(icon);
    item.appendChild(document.createTextNode(String(text)));
    container.appendChild(item);
}

/**
 * 初始化所有番剧列表
 */
async function initCollection() {
    Array.from(document.querySelectorAll('.bgm-collection:not([data-bgm-initialized="1"])')).forEach(item => {
        item.dataset.bgmInitialized = '1';
        item.dataset.bgmOffset = '0';

        const legacyLoader = item.nextElementSibling && item.nextElementSibling.classList.contains('loader')
            ? item.nextElementSibling
            : null;
        if (legacyLoader) {
            legacyLoader.remove();
        }

        const action = createCollectionActionButton();
        item.appendChild(action);
        loadMoreBgm(action);
    });

    document.querySelectorAll('.bgm-calendar').forEach(calendar => {
        loadCalendar(calendar);
    });

    document.querySelectorAll('.bgm-collection .bgm-poster-card[data-cover-url], .cal-panel.active .bgm-poster-card[data-cover-url]').forEach(card => {
        observePosterCover(card);
    });

    await loadBgmCard();
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
window.initCollection = initCollection;

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
