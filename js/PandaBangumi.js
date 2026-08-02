/**
 * PandaBangumi 全局状态
 */
var PandaBangumiRuntime = window.PandaBangumi || {};
var PandaBangumiCollectionPageSize = 12;
PandaBangumiRuntime.controllers = PandaBangumiRuntime.controllers || new Set();
PandaBangumiRuntime.initTimer = PandaBangumiRuntime.initTimer || null;
PandaBangumiRuntime.bound = PandaBangumiRuntime.bound || false;
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
 * 加载指定星期面板的封面
 * @param {HTMLElement} panel
 */
function loadCalendarPanelImages(panel) {
    if (!panel || panel.dataset.imagesLoaded === '1') return;

    panel.querySelectorAll('.bgm-poster-card[data-cover-url]').forEach(item => {
        const imageUrl = String(item.dataset.coverUrl || '');
        const cover = item.querySelector('.bgm-poster-card__cover');
        if (imageUrl) {
            cover.style.backgroundImage = `url("${imageUrl}")`;
        }
        delete item.dataset.coverUrl;
    });
    panel.dataset.imagesLoaded = '1';
}

/**
 * 设置简单文本状态
 * @param {HTMLElement} el
 * @param {string} text
 */
function setText(el, text) {
    el.textContent = text;
}

/**
 * 设置加载动画
 * @param {HTMLElement} loader
 */
function setLoading(loader) {
    loader.textContent = '';
    for (let i = 0; i < 3; i++) {
        const dot = document.createElement('div');
        dot.className = 'dot';
        loader.appendChild(dot);
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
        if (options && options.deferCover) {
            link.dataset.coverUrl = imageUrl;
        } else {
            cover.style.backgroundImage = `url("${imageUrl}")`;
        }
    }
    link.appendChild(cover);

    const overlay = document.createElement('span');
    overlay.className = 'bgm-poster-card__overlay';

    const title = document.createElement('span');
    title.className = 'bgm-poster-card__title';
    title.textContent = displayName;
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
 * 加载更多番剧条目
 *
 * @param {HTMLElement} loader
 */
async function loadMoreBgm(loader) {
    if (!loader || loader.dataset.bgmLoading === '1') return;
    loader.dataset.bgmLoading = '1';
    loader.hidden = false;
    setLoading(loader);

    const refId = loader.getAttribute('data-ref');
    const listEl = refId ? document.getElementById(refId) : null;
    if (!listEl) {
        delete loader.dataset.bgmLoading;
        setText(loader, '加载失败');
        return;
    }

    let bgmCur = parseInt(listEl.getAttribute('bgmCur') || '0', 10);
    if (Number.isNaN(bgmCur) || bgmCur < 0) {
        bgmCur = 0;
    }

    const type = listEl.getAttribute('data-type') === 'watched' ? 'watched' : 'watching';
    const cate = listEl.getAttribute('data-cate') === 'real' ? 'real' : 'anime';
    const url = `${window.bgmBase}?from=${String(bgmCur)}&type=${type}&cate=${cate}`;
    const controller = createRequestController();

    try {
        const data = await fetchJson(url, controller.signal);
        if (!loader.isConnected || !listEl.isConnected) return;

        if (!Array.isArray(data) || data.length < 1) {
            loader.hidden = true;
            return;
        }

        const items = data.slice(0, PandaBangumiCollectionPageSize);
        items.forEach(item => {
            listEl.appendChild(createPosterCard(item, { type }));
            bgmCur++;
        });

        listEl.setAttribute('bgmCur', String(bgmCur));
        if (data.length > PandaBangumiCollectionPageSize) {
            setText(loader, '加载更多');
        } else {
            loader.hidden = true;
        }
    } catch (error) {
        if (isAbortError(error)) return;
        console.error('加载更多番剧失败:', error);
        if (loader.isConnected) {
            setText(loader, '加载失败');
        }
    } finally {
        removeRequestController(controller);
        delete loader.dataset.bgmLoading;
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

        const panels = document.createElement('div');
        panels.className = 'cal-panels';

        (Array.isArray(data) ? data : []).forEach(day => {
            const dayId = String(day.id || '');
            const tabButton = document.createElement('button');
            tabButton.className = 'cal-tab-button';
            tabButton.type = 'button';
            tabButton.textContent = String(day.date_cn || '');
            tabButton.dataset.dayId = dayId;
            if (Number(day.id) === todayId) {
                tabButton.classList.add('active');
            }
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
                noItem.textContent = '今日无更新';
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
        calendarHeader.appendChild(tabs);

        calContainer.appendChild(calendarHeader);
        calContainer.appendChild(panels);

        loadCalendarPanelImages(panels.querySelector('.cal-panel.active'));

        const activeTab = tabs.querySelector('.cal-tab-button.active');
        if (activeTab) {
            activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }

        tabs.addEventListener('click', (e) => {
            if (e.target.matches('.cal-tab-button')) {
                const dayId = e.target.dataset.dayId;
                tabs.querySelectorAll('.cal-tab-button').forEach(btn => btn.classList.remove('active'));
                panels.querySelectorAll('.cal-panel').forEach(pnl => pnl.classList.remove('active'));
                e.target.classList.add('active');
                const targetPanel = Array.from(panels.querySelectorAll('.cal-panel')).find(panel => panel.dataset.dayId === dayId);
                if (targetPanel) {
                    targetPanel.classList.add('active');
                    loadCalendarPanelImages(targetPanel);
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
    if (posterUrl) {
        img.src = posterUrl;
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
    let bgmIndex = 0;
    Array.from(document.querySelectorAll('.bgm-collection:not([data-bgm-initialized="1"])')).forEach(item => {
        bgmIndex++;
        item.dataset.bgmInitialized = '1';
        if (!item.id) {
            item.id = 'bgm-collection-' + String(Date.now()) + '-' + String(bgmIndex);
        }

        let loader = item.nextElementSibling && item.nextElementSibling.classList.contains('loader') && item.nextElementSibling.dataset.ref === item.id
            ? item.nextElementSibling
            : null;
        if (!loader) {
            loader = document.createElement('div');
            loader.className = 'loader';
            loader.dataset.ref = item.id;
            item.insertAdjacentElement('afterend', loader);
        }

        loader.className = 'loader';
        loader.dataset.ref = item.id;
        if (loader.dataset.bgmBound !== '1') {
            loader.dataset.bgmBound = '1';
            loader.addEventListener('click', () => loadMoreBgm(loader));
        }
        loadMoreBgm(loader);
    });

    document.querySelectorAll('.bgm-calendar').forEach(calendar => {
        loadCalendar(calendar);
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

    if (document.readyState !== 'loading') {
        scheduleInit();
    }
}
