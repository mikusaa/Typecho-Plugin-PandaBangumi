/**
 * 加载更多番剧条目
 *
 * 此函数根据传入的加载器参数，动态加载更多番剧条目
 * 它可以处理单个加载器或页面上的所有加载器
 *
 * @param {string|HTMLElement} loader - 加载器的ID或'all'以处理所有加载器
 */
async function loadMoreBgm(loader) {
    if (loader === 'all') {
        // 加载页面上的全部面板
        Array.from(document.querySelectorAll('.loader')).forEach(item => {
            loadMoreBgm(item);
        });
        return;
    }

    loader.innerHTML = '<div class="dot"></div><div class="dot"></div><div class="dot"></div>';

    const refSelector = loader.getAttribute('data-ref');
    if (!refSelector) {
        loader.innerHTML = '加载失败';
        return;
    }

    const listEl = document.querySelector(refSelector);
    if (!listEl) {
        loader.innerHTML = '加载失败';
        return;
    }

    let bgmCur = parseInt(listEl.getAttribute('bgmCur') || '0', 10);
    const type = listEl.getAttribute('data-type');
    const cate = listEl.getAttribute('data-cate');

    const url = bgmBase + '?from=' + String(bgmCur) + '&type=' + type + '&cate=' + cate;
    await fetch(url)
        .then(response => response.json())
        .then(data => {
            loader.innerHTML = '加载更多';
            if (data.length < 1) loader.innerHTML = '没有了';

            data.forEach(item => {
                const name_cn = item.name_cn ? item.name_cn : item.name;
                let status, total;
                if (!item.count) {
                    status = 100;
                    total = '未知';
                } else {
                    status = item.status / item.count * 100;
                    total = String(item.count);
                }
                let html = `
                    <a class="bgm-item" data-id="${item.id}" href="${item.url}" target="_blank">
                        <div class="bgm-item-thumb" style="background-image:url(${item.img})"></div>
                        <div class="bgm-item-info">
                            <span class="bgm-item-title main">${item.name}</span>
                            <span class="bgm-item-title">${name_cn}</span>
                            {{status-bar}}
                        </div>
                    </a>`;
                if (type === 'watching') {
                    html = html.replace('{{status-bar}}', `
                            <div class="bgm-item-statusBar-container">
                                <div class="bgm-item-statusBar" style="width:${String(status)}%"></div>
                                进度：${String(item.status)} / ${total}
                            </div>`);
                } else {
                    html = html.replace('{{status-bar}}', '');
                }
                listEl.insertAdjacentHTML('beforeend', html);

                bgmCur++;
            });

            // 记录当前数量
            listEl.setAttribute('bgmCur', String(bgmCur));
        })
        .catch(error => {
            console.error('加载更多番剧失败:', error);
            loader.innerHTML = '加载失败';
        })
}

/**
 * 加载并渲染标签页式追番日历 (全新版本)
 */
async function loadCalendar() {
    const calContainer = document.querySelector('.bgm-calendar');
    if (!calContainer) return;
    const calFilter = calContainer.getAttribute('data-filter');
    const url = `${bgmBase}?type=calendar&filter=${calFilter}`;

    // 获取当天是星期几 (1=周一, 7=周日)
    const getTodayId = () => {
        const jsDay = new Date().getDay();
        return jsDay === 0 ? 7 : jsDay;
    };

    try {
        const response = await fetch(url);
        const data = await response.json();
        
        const todayId = getTodayId();
        calContainer.innerHTML = ''; // 清空旧内容

        // 1. 创建标签页容器和内容面板容器
        const tabs = document.createElement('div');
        tabs.className = 'cal-tabs';

        const panels = document.createElement('div');
        panels.className = 'cal-panels';

        // 2. 遍历数据，生成标签和内容面板
        data.forEach(day => {
            // 创建标签按钮
            const tabButton = document.createElement('button');
            tabButton.className = 'cal-tab-button';
            tabButton.textContent = day.date_cn;
            tabButton.dataset.dayId = day.id; // 使用data属性关联
            if (day.id === todayId) {
                tabButton.classList.add('active');
            }
            tabs.appendChild(tabButton);

            // 创建内容面板
            const panel = document.createElement('div');
            panel.className = 'cal-panel';
            panel.dataset.dayId = day.id;
            if (day.id === todayId) {
                panel.classList.add('active');
            }

            const itemsArray = day.items ? Object.values(day.items) : [];
            if (itemsArray.length > 0) {
                itemsArray.forEach(item => {
                    const title = item.name_cn || item.name;
                    const bangumiItem = document.createElement('a');
                    bangumiItem.href = item.url;
                    bangumiItem.target = '_blank';
                    bangumiItem.rel = 'noopener noreferrer';
                    bangumiItem.title = title;
                    bangumiItem.className = 'cal-bangumi-item';
                    bangumiItem.style.backgroundImage = `url('${item.img}')`;
                    
                    const titleOverlay = document.createElement('span');
                    titleOverlay.className = 'cal-bangumi-title-overlay';
                    titleOverlay.textContent = title;

                    bangumiItem.appendChild(titleOverlay);
                    panel.appendChild(bangumiItem);
                });
            } else {
                panel.innerHTML = `<p class="cal-no-item">今日无更新</p>`;
            }
            panels.appendChild(panel);
        });

        calContainer.appendChild(tabs);
        calContainer.appendChild(panels);

        // 3. 添加标签页点击事件逻辑
        tabs.addEventListener('click', (e) => {
            if (e.target.matches('.cal-tab-button')) {
                const dayId = e.target.dataset.dayId;
                
                // 移除所有 active 类
                tabs.querySelectorAll('.cal-tab-button').forEach(btn => btn.classList.remove('active'));
                panels.querySelectorAll('.cal-panel').forEach(pnl => pnl.classList.remove('active'));

                // 为点击的目标和对应内容添加 active 类
                e.target.classList.add('active');
                panels.querySelector(`.cal-panel[data-day-id="${dayId}"]`).classList.add('active');
            }
        });

    } catch (error) {
        console.error('加载日历失败:', error);
        calContainer.innerHTML = '<p class="error">加载日历失败，请刷新页面。</p>';
    }
}

/**
 * 加载番剧卡片信息
 *
 * 此函数负责遍历页面上所有类名为 'bgm-card' 的元素，并尝试加载它们的番剧信息
 * 它通过元素上的 'data-id' 属性来识别每个元素关联的番剧ID，并据此渲染或更新元素的内容
 */
async function loadBgmCard() {
    const cards = document.querySelectorAll('.bgm-card');

    cards.forEach(card => {
        const id = card.getAttribute('data-id');
        if (id) renderCard(id, card);
    })
}

/**
 * 根据番剧ID渲染番剧卡片
 * 本函数通过Bangumi API加载番剧数据，并在页面上渲染番剧卡片
 * 如果加载失败，会显示错误信息
 *
 * @param {number} subjectId 番剧ID，用于从API获取番剧信息
 * @param {HTMLElement} cardElement 番剧卡片的HTML元素，用于显示加载状态、番剧信息或错误信息
 */
async function renderCard(subjectId, cardElement) {
    cardElement.innerHTML = `<div class="loading-state">正在从 Bangumi 加载数据...</div>`;
    const url = 'https://api.bgm.tv/v0/subjects/' + subjectId;

    await fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.id === parseInt(subjectId)) {
                cardElement.innerHTML = buildCardHTML(data, subjectId);
            } else {
                throw new Error('返回的番剧数据无效');
            }
        })
        .catch(error => {
            console.error('Error fetching data:', error);
            cardElement.innerHTML = `<div class="error-state">无法加载番剧信息。请检查条目ID (${subjectId}) 或网络连接。</div>`;
        });
}

/**
 * 从 infobox 数组中查找特定键的值
 * @param {Array} infobox
 * @param {string} key
 * @returns {string}
 */
function findInfo(infobox, key) {
    if (!infobox) return '未知';
    const info = infobox.find(item => item && item.key === key);
    return (info && info.value) || '未知';
}

/**
 * 根据分数返回描述性文字
 * @param {number|null} score 分数
 * @returns {string} 描述文字
 */
function getScoreDescriptionJs(score) {
    if (isNaN(score) || score <= 0) {
        return "暂无评分";
    }
    // 使用 Math.floor() 获取分数的整数部分
    switch (Math.floor(score)) {
        case 1: return "不忍直视";
        case 2: return "很差";
        case 3: return "差";
        case 4: return "较差";
        case 5: return "不过不失";
        case 6: return "还行";
        case 7: return "推荐";
        case 8: return "力荐";
        case 9: return "神作";
        case 10: return "神作"; // 分数 9 和 10 都视为神作
        default: return "暂无评分";
    }
}

/**
 * 构建卡片HTML (最终版：加宽、单行评分)
 * @param {object} data
 * @param {string} subjectId
 * @returns {string}
 */
function buildCardHTML(data, subjectId) {
    // 从 API 数据中提取信息
    const nameCN = data.name_cn || data.name;
    const nameOriginal = data.name_cn ? data.name : '';
    const posterUrl = (data.images && data.images.large) || '';
    const bangumiUrl = `https://bgm.tv/subject/${subjectId}`;

    // 评分信息
    const rating = data.rating || {};
    const score = rating.score ? rating.score.toFixed(1) : 'N/A';
    const ratingCount = rating.total || 0;

    // 其他元数据
    const airDate = data.date || '未知';
    const totalEpisodes = findInfo(data.infobox, '话数') || data.total_episodes || '未知';
    const collectionCount = (data.collection && data.collection.collect) || 0;

    // 截取标签
    const tags = (data.tags || []).slice(0, 7).map(tag => `<span class="bgm-card-tag">${tag.name}</span>`).join('');

    // 获取文字描述
    const scoreCn = getScoreDescriptionJs(score);

    // 构建HTML结构
    return `
    <a href="${bangumiUrl}" target="_blank" rel="noopener noreferrer" class="bgm-card-link-wrapper">
        <div class="bgm-card-poster">
            <img src="${posterUrl}" alt="${nameCN} Poster">
        </div>
        <div class="bgm-card-content">
            <h3 class="bgm-card-title">${nameCN}</h3>
            <p class="bgm-card-subtitle">${nameOriginal || '&nbsp;'}</p>

            <div class="bgm-card-meta">
                <span class="meta-item"><i class="meta-icon icon-calendar"></i>${airDate}</span>
                <span class="meta-item"><i class="meta-icon icon-tv"></i>${totalEpisodes} 集</span>
                <span class="meta-item"><i class="meta-icon icon-collection"></i>${collectionCount} 人收藏</span>
            </div>

            <div class="bgm-card-tags">
                ${tags}
            </div>

            <div class="bgm-card-rating-area">
                <span class="bgm-card-score">${score}</span>
                <span class="bgm-card-score-text">${scoreCn}</span>
                <span class="bgm-card-rating-count">(${ratingCount}人评分)</span>
            </div>
        </div>
    </a>
  `;
}


/**
 * 初始化所有番剧列表
 *
 * 本函数主要用于初始化番剧列表，并顺带初始化日历和番剧卡片
 */
async function initCollection() {
    let bgmIndex = 0;
    Array.from(document.querySelectorAll('.bgm-collection')).forEach(item => {
        bgmIndex++;
        item.setAttribute('id', 'bgm-collection-' + String(bgmIndex));
        item.insertAdjacentHTML('afterend', '<div class="loader" data-ref="' + '#bgm-collection-' + String(bgmIndex) + '" onclick="loadMoreBgm(this);"></div>');
    });

    await loadMoreBgm('all');

    await loadCalendar();

    await loadBgmCard();
}

document.addEventListener('DOMContentLoaded', async () => initCollection())

document.addEventListener('pjax:complete', async () => initCollection())

