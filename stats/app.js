const apiUrl = 'api.php';
let latestDate = null;
let chartState = null;
let currentSeries = [];
let presetSyncInProgress = false;

const elements = {
  site: document.getElementById('site-name'),
  visits: document.getElementById('visits'),
  pages: document.getElementById('pages'),
  hits: document.getElementById('hits'),
  bandwidth: document.getElementById('bandwidth'),
  range: document.getElementById('range-label'),
  dailyRange: document.getElementById('daily-range'),
  lastUpdate: document.getElementById('last-update'),
  from: document.getElementById('from-date'),
  to: document.getElementById('to-date'),
  apply: document.getElementById('apply-range'),
  chart: document.getElementById('visits-chart'),
  tooltip: document.getElementById('chart-tooltip'),
  topPages: document.getElementById('top-pages'),
  topReferrers: document.getElementById('top-referrers'),
  topCountries: document.getElementById('top-countries'),
  topBrowsers: document.getElementById('top-browsers'),
  topOs: document.getElementById('top-os'),
  dailyList: document.getElementById('daily-list'),
  listRanges: document.querySelectorAll('.list-range'),
  rangePreset: document.getElementById('range-preset'),
  dateFields: document.querySelectorAll('.date-field'),
};

function formatNumber(value) {
  return new Intl.NumberFormat('en-GB').format(value);
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const index = Math.floor(Math.log(bytes) / Math.log(1024));
  const size = bytes / Math.pow(1024, index);
  return `${size.toFixed(index > 1 ? 1 : 0)} ${units[index]}`;
}

function syncCanvasSize() {
  const rect = elements.chart.getBoundingClientRect();
  const dpr = window.devicePixelRatio || 1;
  const width = Math.max(1, Math.round(rect.width * dpr));
  const height = Math.max(1, Math.round(rect.height * dpr));
  if (elements.chart.width !== width || elements.chart.height !== height) {
    elements.chart.width = width;
    elements.chart.height = height;
  }
  const ctx = elements.chart.getContext('2d');
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  return { ctx, width: rect.width, height: rect.height };
}


function toIsoDate(date) {
  return date.toISOString().slice(0, 10);
}

function ordinalSuffix(day) {
  if (day % 100 >= 11 && day % 100 <= 13) return 'th';
  const last = day % 10;
  if (last === 1) return 'st';
  if (last === 2) return 'nd';
  if (last === 3) return 'rd';
  return 'th';
}

function formatPrettyDate(isoDate) {
  const date = new Date(`${isoDate}T00:00:00`);
  if (Number.isNaN(date.getTime())) return isoDate;
  const weekday = new Intl.DateTimeFormat('en-GB', { weekday: 'short' }).format(date);
  const month = new Intl.DateTimeFormat('en-GB', { month: 'short' }).format(date);
  const day = date.getDate();
  return `${weekday} ${day}${ordinalSuffix(day)} ${month}`;
}

function applyPreset(preset) {
  const anchor = latestDate ? new Date(latestDate) : new Date();
  const start = new Date(anchor);
  const end = new Date(anchor);

  if (preset === '7d') {
    start.setDate(start.getDate() - 6);
  } else if (preset === '30d') {
    start.setDate(start.getDate() - 29);
  } else if (preset === '90d') {
    start.setDate(start.getDate() - 89);
  } else if (preset === 'this-month') {
    start.setDate(1);
  } else if (preset === 'last-month') {
    start.setDate(1);
    start.setMonth(start.getMonth() - 1);
    end.setDate(0);
  }

  if (preset !== 'custom') {
    elements.from.value = toIsoDate(start);
    elements.to.value = toIsoDate(end);
  }

  elements.dateFields.forEach((field) => {
    field.style.display = preset === 'custom' ? 'flex' : 'none';
  });
}

function renderList(container, items, metric = 'pages') {
  container.innerHTML = '';
  if (!items || items.length === 0) {
    container.innerHTML = '<div class="list-item"><strong>No data</strong><span>-</span></div>';
    return;
  }

  items.forEach((item) => {
    const row = document.createElement('div');
    row.className = 'list-item';
    const label = document.createElement('strong');
    label.textContent = item.label || '(unknown)';
    const value = document.createElement('span');
    value.textContent = formatNumber(item[metric] || 0);
    row.appendChild(label);
    row.appendChild(value);
    container.appendChild(row);
  });
}

function renderDailyList(series) {
  elements.dailyList.innerHTML = '';
  if (!series || series.length === 0) {
    elements.dailyList.innerHTML = '<div class="list-item"><strong>No data</strong><span>-</span></div>';
    return;
  }

  const items = [...series].reverse();
  items.forEach((day) => {
    const row = document.createElement('div');
    row.className = 'list-item';
    const label = document.createElement('strong');
    label.textContent = formatPrettyDate(day.date);
    const value = document.createElement('span');
    value.textContent = `${formatNumber(day.visits)} visits`;
    row.appendChild(label);
    row.appendChild(value);
    elements.dailyList.appendChild(row);
  });
}

function drawChart(series) {
  const { ctx, width, height } = syncCanvasSize();
  ctx.clearRect(0, 0, width, height);

  if (!series || series.length === 0) {
    ctx.fillStyle = 'rgba(28, 27, 32, 0.6)';
    ctx.fillText('No data', 20, 30);
    chartState = null;
    elements.tooltip.classList.remove('visible');
    return;
  }

  const values = series.map((point) => point.visits);
  const max = Math.max(...values, 1);
  const min = Math.min(...values, 0);
  const padding = 24;

  ctx.strokeStyle = 'rgba(15, 118, 110, 0.9)';
  ctx.lineWidth = 2.5;
  ctx.beginPath();

  series.forEach((point, index) => {
    const x = padding + (index / (series.length - 1 || 1)) * (width - padding * 2);
    const y = height - padding - ((point.visits - min) / (max - min || 1)) * (height - padding * 2);
    if (index === 0) {
      ctx.moveTo(x, y);
    } else {
      ctx.lineTo(x, y);
    }
  });

  ctx.stroke();

  ctx.fillStyle = 'rgba(15, 118, 110, 0.15)';
  ctx.lineTo(width - padding, height - padding);
  ctx.lineTo(padding, height - padding);
  ctx.closePath();
  ctx.fill();

  chartState = {
    series,
    min,
    max,
    padding,
    width,
    height,
  };
}

function updateTooltip(event) {
  if (!chartState) return;
  const rect = elements.chart.getBoundingClientRect();
  const x = event.clientX - rect.left;
  const { series, min, max, padding, width, height } = chartState;
  const usable = width - padding * 2;
  if (x < padding || x > width - padding || series.length === 0) {
    elements.tooltip.classList.remove('visible');
    return;
  }

  const ratio = (x - padding) / usable;
  const index = Math.max(0, Math.min(series.length - 1, Math.round(ratio * (series.length - 1))));
  const point = series[index];
  const pointX = padding + (index / (series.length - 1 || 1)) * usable;
  const pointY = height - padding - ((point.visits - min) / (max - min || 1)) * (height - padding * 2);

  elements.tooltip.textContent = `${formatPrettyDate(point.date)} · ${formatNumber(point.visits)} visits`;
  elements.tooltip.style.left = `${pointX}px`;
  elements.tooltip.style.top = `${pointY}px`;
  elements.tooltip.classList.add('visible');
}

function applyData(data) {
  if (data.error) {
    elements.range.textContent = data.error;
    return;
  }

  latestDate = data.meta.latest_date || latestDate;
  elements.site.textContent = data.meta.site;
  elements.visits.textContent = formatNumber(data.totals.visits);
  elements.pages.textContent = formatNumber(data.totals.pages);
  elements.hits.textContent = formatNumber(data.totals.hits);
  elements.bandwidth.textContent = formatBytes(data.totals.bandwidth_bytes);

  elements.range.textContent = `${data.meta.range.from} to ${data.meta.range.to}`;
  elements.dailyRange.textContent = `${data.meta.range.from} to ${data.meta.range.to}`;
  elements.listRanges.forEach((el) => {
    el.textContent = `Range: ${data.meta.range.from} to ${data.meta.range.to}`;
  });

  let lastUpdate = data.meta.last_update || '';
  if (lastUpdate.length === 14) {
    const y = lastUpdate.slice(0, 4);
    const m = lastUpdate.slice(4, 6);
    const d = lastUpdate.slice(6, 8);
    const h = lastUpdate.slice(8, 10);
    const min = lastUpdate.slice(10, 12);
    lastUpdate = `${y}-${m}-${d} ${h}:${min}`;
  }
  elements.lastUpdate.textContent = lastUpdate
    ? `Last update: ${lastUpdate}`
    : 'Last update: -';

  elements.from.value = data.meta.range.from;
  elements.to.value = data.meta.range.to;

  currentSeries = data.series || [];
  drawChart(currentSeries);
  renderDailyList(currentSeries);

  const preset = elements.rangePreset.value;
  if (preset !== 'custom' && latestDate && elements.to.value !== latestDate && !presetSyncInProgress) {
    presetSyncInProgress = true;
    applyPreset(preset);
    loadData().finally(() => {
      presetSyncInProgress = false;
    });
  }
  renderList(elements.topPages, data.top.pages, 'pages');
  renderList(elements.topReferrers, data.top.externalref, 'hits');
  renderList(elements.topCountries, data.top.country, 'pages');
  renderList(elements.topBrowsers, data.top.browser, 'hits');
  renderList(elements.topOs, data.top.os, 'hits');
}

async function loadData() {
  const params = new URLSearchParams();
  if (elements.from.value) params.set('from', elements.from.value);
  if (elements.to.value) params.set('to', elements.to.value);

  const response = await fetch(`${apiUrl}?${params.toString()}`);
  const data = await response.json();
  applyData(data);
}

elements.apply.addEventListener('click', () => {
  loadData().catch(() => {
    elements.range.textContent = 'Unable to load data.';
  });
});

elements.rangePreset.addEventListener('change', (event) => {
  applyPreset(event.target.value);
  loadData().catch(() => {
    elements.range.textContent = 'Unable to load data.';
  });
});

elements.chart.addEventListener('mousemove', updateTooltip);

elements.chart.addEventListener('mouseleave', () => {
  elements.tooltip.classList.remove('visible');
});

window.addEventListener('resize', () => {
  if (currentSeries && currentSeries.length) {
    drawChart(currentSeries);
  }
});

applyPreset(elements.rangePreset.value);

loadData().catch(() => {
  elements.range.textContent = 'Unable to load data.';
});
