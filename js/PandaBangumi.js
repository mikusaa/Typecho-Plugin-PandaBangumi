/**
 * 校验 HTTPS URL
 * @param {string} value
 * @returns {string}
 */
function safeHttpsUrl(value) {
    const raw = String(value || '').trim();
    if (!raw.startsWith('https://')) {
        return '';
    }

    try {
        const url = new URL(raw);
        return url.protocol === 'https:' ? url.href : '';
    } catch (error) {
        return '';
    }
}

/**
 * 构造 Bangumi API 请求地址
 * @param {string} path
 * @returns {string}
 */
function buildBgmApiUrl(path) {
    let apiBase = safeHttpsUrl(window.bgmApiBase) || 'https://api.bgm.tv';
    apiBase = apiBase.replace(/\/+$/, '');
    path = '/' + String(path || '').replace(/^\/+/, '');

    if (apiBase.endsWith('/v0') && path.startsWith('/v0/')) {
        path = path.slice(3);
    } else if (apiBase.endsWith('/v0') && path === '/v0') {
        path = '';
    }

    return apiBase + path;
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
 * @returns {Promise<any>}
 */
async function fetchJson(url) {
    const response = await fetch(url);
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    return response.json();
}

/**
 * 创建列表条目
 * @param {object} item
 * @param {string} type
 * @returns {HTMLElement}
 */
function createBgmItem(item, type) {
    const href = safeHttpsUrl(item.url) || 'https://bgm.tv/';
    const imageUrl = safeHttpsUrl(item.img);
    const name = String(item.name || '');
    const nameCn = String(item.name_cn || item.name || '');
    const count = Number(item.count || 0);
    const epStatus = Math.max(0, Number(item.status || 0));

    const link = document.createElement('a');
    link.className = 'bgm-item';
    link.dataset.id = String(item.id || '');
    link.href = href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';

    const thumb = document.createElement('div');
    thumb.className = 'bgm-item-thumb';
    if (imageUrl) {
        thumb.style.backgroundImage = `url("${imageUrl}")`;
    }
    link.appendChild(thumb);

    const info = document.createElement('div');
    info.className = 'bgm-item-info';

    const mainTitle = document.createElement('span');
    mainTitle.className = 'bgm-item-title main';
    mainTitle.textContent = name;
    info.appendChild(mainTitle);

    const subTitle = document.createElement('span');
    subTitle.className = 'bgm-item-title';
    subTitle.textContent = nameCn;
    info.appendChild(subTitle);

    if (type === 'watching') {
        const statusContainer = document.createElement('div');
        statusContainer.className = 'bgm-item-statusBar-container';

        const statusBar = document.createElement('div');
        statusBar.className = 'bgm-item-statusBar';
        statusBar.style.width = count > 0 ? `${Math.min(100, epStatus / count * 100)}%` : '100%';
        statusContainer.appendChild(statusBar);

        const total = count > 0 ? String(count) : '未知';
        statusContainer.appendChild(document.createTextNode(`进度：${String(epStatus)} / ${total}`));
        info.appendChild(statusContainer);
    }

    link.appendChild(info);
    return link;
}

/**
 * 加载更多番剧条目
 *
 * @param {HTMLElement} loader
 */
async function loadMoreBgm(loader) {
    setLoading(loader);

    const refId = loader.getAttribute('data-ref');
    const listEl = refId ? document.getElementById(refId) : null;
    if (!listEl) {
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

    try {
        const data = await fetchJson(url);
        setText(loader, '加载更多');
        if (!Array.isArray(data) || data.length < 1) {
            setText(loader, '没有了');
            return;
        }

        data.forEach(item => {
            listEl.appendChild(createBgmItem(item, type));
            bgmCur++;
        });

        listEl.setAttribute('bgmCur', String(bgmCur));
    } catch (error) {
        console.error('加载更多番剧失败:', error);
        setText(loader, '加载失败');
    }
}

/**
 * 加载并渲染标签页式追番日历
 * @param {HTMLElement} calContainer
 */
async function loadCalendar(calContainer) {
    if (!calContainer || calContainer.dataset.bgmLoaded === '1') return;
    calContainer.dataset.bgmLoaded = '1';

    const calFilter = calContainer.getAttribute('data-filter') === 'watching' ? 'watching' : 'all';
    const url = `${window.bgmBase}?type=calendar&filter=${calFilter}`;
    const getTodayId = () => {
        const jsDay = new Date().getDay();
        return jsDay === 0 ? 7 : jsDay;
    };

    try {
        const data = await fetchJson(url);
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
                    const imageUrl = safeHttpsUrl(item.img);
                    const bangumiItem = document.createElement('a');
                    bangumiItem.href = href;
                    bangumiItem.target = '_blank';
                    bangumiItem.rel = 'noopener noreferrer';
                    bangumiItem.title = title;
                    bangumiItem.className = 'cal-bangumi-item';
                    if (imageUrl) {
                        bangumiItem.style.backgroundImage = `url("${imageUrl}")`;
                    }

                    const titleOverlay = document.createElement('span');
                    titleOverlay.className = 'cal-bangumi-title-overlay';
                    titleOverlay.textContent = title;

                    bangumiItem.appendChild(titleOverlay);
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

        calContainer.appendChild(tabs);
        calContainer.appendChild(panels);

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
                }
            }
        });
    } catch (error) {
        console.error('加载日历失败:', error);
        calContainer.textContent = '';
        const errorEl = document.createElement('p');
        errorEl.className = 'error';
        errorEl.textContent = '加载日历失败，请刷新页面。';
        calContainer.appendChild(errorEl);
        delete calContainer.dataset.bgmLoaded;
    }
}

/**
 * 加载番剧卡片信息
 */
async function loadBgmCard() {
    const cards = document.querySelectorAll('.bgm-card:not([data-bgm-loaded="1"])');

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
    cardElement.dataset.bgmLoaded = '1';
    cardElement.textContent = '';
    const loading = document.createElement('div');
    loading.className = 'loading-state';
    loading.textContent = '正在从 Bangumi 加载数据...';
    cardElement.appendChild(loading);

    const safeSubjectId = parseInt(subjectId, 10);
    if (!Number.isInteger(safeSubjectId) || safeSubjectId <= 0) {
        renderCardError(cardElement, subjectId);
        return;
    }

    try {
        const data = await fetchJson(buildBgmApiUrl('/v0/subjects/' + safeSubjectId));
        if (data.id === safeSubjectId) {
            cardElement.textContent = '';
            cardElement.appendChild(buildCardElement(data, safeSubjectId));
        } else {
            throw new Error('返回的番剧数据无效');
        }
    } catch (error) {
        console.error('Error fetching data:', error);
        renderCardError(cardElement, subjectId);
    }
}

/**
 * 渲染卡片错误状态
 * @param {HTMLElement} cardElement
 * @param {number|string} subjectId
 */
function renderCardError(cardElement, subjectId) {
    delete cardElement.dataset.bgmLoaded;
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

        const loader = document.createElement('div');
        loader.className = 'loader';
        loader.dataset.ref = item.id;
        loader.addEventListener('click', () => loadMoreBgm(loader));
        item.insertAdjacentElement('afterend', loader);
        loadMoreBgm(loader);
    });

    document.querySelectorAll('.bgm-calendar').forEach(calendar => {
        loadCalendar(calendar);
    });

    await loadBgmCard();
}

document.addEventListener('DOMContentLoaded', async () => initCollection());
document.addEventListener('pjax:complete', async () => initCollection());
