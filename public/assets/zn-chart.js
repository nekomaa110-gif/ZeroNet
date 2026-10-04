(function () {
  'use strict';

  const ZN = (window.ZeroNet = window.ZeroNet || {});
  const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

  ZN.chartColors = function () {
    return {
      series: [css('--chart-1'), css('--chart-2'), css('--chart-3')],
      other: css('--chart-other'),
      grid: css('--chart-grid'),
      tick: css('--chart-tick'),
      ink: css('--ink'),
      ink2: css('--ink-2'),
      surface: css('--surface'),
      line: css('--line'),
      font: css('--font-sans') || 'sans-serif',
      mono: css('--font-mono') || 'monospace',
    };
  };

  ZN.fmtBps = function (bps) {
    const v = Number(bps) || 0;
    if (v >= 1e9) return (v / 1e9).toFixed(2) + ' Gbps';
    if (v >= 1e6) return (v / 1e6).toFixed(2) + ' Mbps';
    if (v >= 1e3) return (v / 1e3).toFixed(1) + ' Kbps';
    return Math.round(v) + ' bps';
  };

  ZN.fmtBpsAxis = function (bps) {
    const v = Number(bps) || 0;
    const t = (n) => (Math.round(n * 10) / 10).toString().replace('.', ',');
    if (v >= 1e9) return t(v / 1e9) + 'G';
    if (v >= 1e6) return t(v / 1e6) + 'M';
    if (v >= 1e3) return t(v / 1e3) + 'K';
    return Math.round(v) + '';
  };

  ZN.fmtBytes = function (n) {
    const v = Number(n) || 0;
    if (v >= 1099511627776) return (v / 1099511627776).toFixed(2) + ' TB';
    if (v >= 1073741824) return (v / 1073741824).toFixed(2) + ' GB';
    if (v >= 1048576) return (v / 1048576).toFixed(1) + ' MB';
    if (v >= 1024) return (v / 1024).toFixed(1) + ' KB';
    return Math.round(v) + ' B';
  };

  ZN.crosshairPlugin = {
    id: 'znCrosshair',
    afterDatasetsDraw(chart) {
      const act = chart.tooltip && chart.tooltip.getActiveElements ? chart.tooltip.getActiveElements() : [];
      if (!act.length) return;
      const x = act[0].element.x;
      const area = chart.chartArea;
      const ctx = chart.ctx;
      ctx.save();
      ctx.beginPath();
      ctx.moveTo(x, area.top);
      ctx.lineTo(x, area.bottom);
      ctx.lineWidth = 1;
      ctx.strokeStyle = ZN.chartColors().tick;
      ctx.globalAlpha = 0.5;
      ctx.stroke();
      ctx.restore();
    },
  };

  ZN.baseLineOptions = function (opts) {
    const c = ZN.chartColors();
    const o = opts || {};
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      interaction: { intersect: false, mode: 'index' },
      layout: { padding: { top: 4, right: 4 } },
      elements: {
        line: { borderWidth: 2, tension: 0.25 },
        point: { radius: 0, hoverRadius: 4, hitRadius: 12, hoverBorderWidth: 2, hoverBorderColor: c.surface },
      },
      scales: {
        x: o.x || { display: false },
        y: Object.assign({
          beginAtZero: true,
          border: { display: false },
          grid: { color: c.grid, drawTicks: false },
          ticks: { color: c.tick, font: { family: c.mono, size: 10.5 }, maxTicksLimit: 4, padding: 6, callback: o.yAxisFormat || (o.yFormat ? o.yFormat : ZN.fmtBpsAxis) },
        }, o.y || {}),
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: c.ink,
          titleColor: c.surface,
          bodyColor: c.surface,
          titleFont: { family: c.font, size: 11.5, weight: '600' },
          bodyFont: { family: c.mono, size: 11.5 },
          padding: 8,
          cornerRadius: 4,
          boxWidth: 8,
          boxHeight: 8,
          boxPadding: 4,
          callbacks: Object.assign({
            label: (ctx) => ' ' + ctx.dataset.label + ': ' + (o.yFormat || ZN.fmtBps)(ctx.raw),
          }, o.tooltipCallbacks || {}),
        },
      },
    };
  };

  ZN.recolorChart = function (chart, opts) {
    if (!chart) return;
    const c = ZN.chartColors();
    const o = opts || {};
    chart.data.datasets.forEach((ds, i) => {
      const slot = ds.znSlot !== undefined ? ds.znSlot : i;
      const col = slot === 'other' ? c.other : c.series[slot % c.series.length];
      ds.borderColor = col;
      ds.backgroundColor = ds.fill ? col + '1F' : col;
    });
    const y = chart.options.scales && chart.options.scales.y;
    if (y) {
      y.grid.color = c.grid;
      y.ticks.color = c.tick;
    }
    const x = chart.options.scales && chart.options.scales.x;
    if (x && x.ticks) x.ticks.color = c.tick;
    if (x && x.grid) x.grid.color = c.grid;
    const tt = chart.options.plugins && chart.options.plugins.tooltip;
    if (tt) {
      tt.backgroundColor = c.ink;
      tt.titleColor = c.surface;
      tt.bodyColor = c.surface;
    }
    if (o.update !== false) chart.update('none');
  };

  if (window.Chart && window.Chart.defaults) {
    window.Chart.defaults.font.family = css('--font-sans') || 'sans-serif';
    window.Chart.defaults.color = css('--chart-tick') || '#5B645E';
    window.Chart.defaults.borderColor = css('--chart-grid') || 'rgba(0,0,0,.08)';
  }

  const charts = new Set();
  ZN.trackChart = function (chart) {
    charts.add(chart);
    ZN.recolorChart(chart);
    return chart;
  };
  ZN.untrackChart = function (chart) { charts.delete(chart); };

  document.addEventListener('zeronet:prefs', (e) => {
    if (e.detail && e.detail.key !== 'theme') return;
    requestAnimationFrame(() => charts.forEach((ch) => ZN.recolorChart(ch)));
  });
})();
