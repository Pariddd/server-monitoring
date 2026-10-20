import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    Tooltip,
} from "chart.js";
import { fmtHm, fmtFull, TZ_OFFSET_MS } from "./format";

Chart.register(LineController, LineElement, PointElement, LinearScale, Tooltip);

/** Indeks titik ber-nilai dengan x terdekat (data terurut naik; titik celah y=null dilewati). */
export function nearestIndex(points, x) {
    let lo = 0,
        hi = points.length - 1;
    if (hi < 0) return -1;
    while (lo < hi) {
        const mid = (lo + hi) >> 1;
        if (points[mid].x < x) lo = mid + 1;
        else hi = mid;
    }
    if (lo > 0 && Math.abs(points[lo - 1].x - x) < Math.abs(points[lo].x - x))
        lo--;
    if (points[lo].y === null) {
        if (points[lo - 1]?.y != null) return lo - 1;
        if (points[lo + 1]?.y != null) return lo + 1;
        return -1;
    }
    return lo;
}

/** Sisipkan titik kosong di celah > maxGapMs agar garis terputus, bukan menyambung data yang tidak ada. */
export function splitGaps(raw, maxGapMs, toMs) {
    const points = [];
    const gaps = [];
    for (let i = 0; i < raw.length; i++) {
        if (i > 0 && raw[i].x - raw[i - 1].x > maxGapMs) {
            points.push({ x: raw[i - 1].x + 1, y: null });
            gaps.push({ from: raw[i - 1].x, to: raw[i].x, open: false });
        }
        points.push(raw[i]);
    }
    const last = raw.at(-1);
    if (last && toMs - last.x > maxGapMs) {
        gaps.push({ from: last.x, to: toMs, open: true });
    }
    return { points, gaps };
}

/** Garis ambang digambar DI BAWAH data agar data tidak pernah tertutup. */
const thresholdLines = {
    id: "thresholdLines",
    beforeDatasetsDraw(chart, _args, opts) {
        const {
            ctx,
            chartArea: { left, right, top, bottom },
            scales: { x, y },
        } = chart;

        for (const g of opts.gaps ?? []) {
            const x1 = Math.max(left, x.getPixelForValue(g.from));
            const x2 = Math.min(right, x.getPixelForValue(g.to));
            if (x2 - x1 < 1) continue;
            ctx.save();
            ctx.fillStyle = "rgba(229, 72, 45, 0.10)";
            ctx.fillRect(x1, top, x2 - x1, bottom - top);
            ctx.strokeStyle = "rgba(176, 42, 20, 0.35)"; // arsiran diagonal
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.rect(x1, top, x2 - x1, bottom - top);
            ctx.clip();
            for (let s = x1 - (bottom - top); s < x2; s += 10) {
                ctx.moveTo(s, bottom);
                ctx.lineTo(s + (bottom - top), top);
            }
            ctx.stroke();
            ctx.restore();

            if (x2 - x1 > 46) {
                // label hanya bila arsiran cukup lebar
                const text = g.open
                    ? "DOWN · sedang berlangsung"
                    : "DOWN · tanpa data";
                ctx.save();
                ctx.font = '700 12px "Barlow Semi Condensed", sans-serif';
                const w = Math.min(ctx.measureText(text).width, x2 - x1 - 8);
                const tx = (x1 + x2) / 2 - w / 2;
                ctx.fillStyle = "rgba(248, 249, 249, 0.94)";
                ctx.fillRect(tx - 4, top + 4, w + 8, 18);
                ctx.fillStyle = "#B02A14";
                ctx.textBaseline = "middle";
                ctx.fillText(text, tx, top + 13, w);
                ctx.restore();
            }
        }

        const line = (value, color, dash) => {
            if (value === null || value === undefined) return;
            const py = y.getPixelForValue(value);
            ctx.save();
            ctx.strokeStyle = color;
            ctx.lineWidth = 1.5;
            ctx.setLineDash(dash);
            ctx.beginPath();
            ctx.moveTo(left, py);
            ctx.lineTo(right, py);
            ctx.stroke();
            ctx.restore();
        };
        line(opts.fire, "#B02A14", [6, 4]);
        line(opts.recover, "#1E6B43", [2, 4]);
    },
};

/** Penanda breach dan garis bidik digambar DI ATAS data. */
const overlays = {
    id: "overlays",
    afterDatasetsDraw(chart, _args, opts) {
        const {
            ctx,
            chartArea: { left, top, bottom },
            scales: { x, y },
        } = chart;

        const breaches = (opts.breaches ?? [])
            .filter((b) => b.x >= x.min && b.x <= x.max)
            .sort((a, b) => a.x - b.x);

        breaches.forEach((b, i) => {
            const px = x.getPixelForValue(b.x),
                py = y.getPixelForValue(b.y);
            ctx.save();
            ctx.fillStyle = "#B02A14";
            ctx.strokeStyle = "#F8F9F9";
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.arc(px, py, 5, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();

            if (i === breaches.length - 1) {
                // label (berlatar) hanya untuk breach terbaru
                const text = `melewati ambang ${b.label}`;
                ctx.font = '600 12px "Barlow Semi Condensed", sans-serif';
                const w = ctx.measureText(text).width;
                const toLeft = px - left > w + 24;
                const tx = toLeft ? px - 12 - w : px + 12;
                const ty = Math.max(top + 2, py - 26);
                ctx.fillStyle = "rgba(248, 249, 249, 0.94)";
                ctx.strokeStyle = "#E2B7AC";
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.roundRect(tx - 5, ty, w + 10, 19, 4);
                ctx.fill();
                ctx.stroke();
                ctx.fillStyle = "#B02A14";
                ctx.textBaseline = "middle";
                ctx.fillText(text, tx, ty + 10);
            }
            ctx.restore();
        });

        const active = chart.tooltip?.getActiveElements() ?? [];
        if (active.length) {
            const ax = active[0].element.x;
            ctx.save();
            ctx.strokeStyle = "#1E2528";
            ctx.lineWidth = 1;
            ctx.setLineDash([3, 3]);
            ctx.beginPath();
            ctx.moveTo(ax, top);
            ctx.lineTo(ax, bottom);
            ctx.stroke();
            ctx.restore();
        }
    },
};

export function createMetricChart(canvas, label) {
    const mono = { family: '"IBM Plex Mono", monospace', size: 12 };

    const chart = new Chart(canvas, {
        type: "line",
        data: {
            datasets: [
                {
                    label,
                    data: [],
                    borderColor: "#0F5C73",
                    borderWidth: 2.4,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: "#F8F9F9",
                    pointHoverBorderColor: "#0F5C73",
                    pointHoverBorderWidth: 2.5,
                    tension: 0,
                    spanGaps: false, // titik y=null memutus garis
                },
            ],
        },
        options: {
            animation: false,
            responsive: true,
            maintainAspectRatio: false,
            parsing: false,
            normalized: true,
            layout: { padding: { top: 6 } },
            interaction: { mode: "index", intersect: false },
            scales: {
                x: {
                    type: "linear",
                    afterBuildTicks: (scale) => {
                        const step = scale.chart.$step;
                        if (!step) return;
                        const first =
                            Math.ceil((scale.min + TZ_OFFSET_MS) / step) *
                                step -
                            TZ_OFFSET_MS;
                        const ticks = [];
                        for (let v = first; v <= scale.max; v += step)
                            ticks.push({ value: v });
                        scale.ticks = ticks;
                    },
                    ticks: {
                        color: "#5B676C",
                        font: mono,
                        callback: (v) => fmtHm(v),
                    },
                    grid: { color: "#E1E6E7" },
                },
                y: {
                    min: 0,
                    max: 100,
                    ticks: {
                        stepSize: 25,
                        color: "#5B676C",
                        font: mono,
                        callback: (v) => `${v}%`,
                    },
                    grid: { color: "#E1E6E7" },
                },
            },
            plugins: {
                legend: { display: false },
                thresholdLines: {},
                overlays: {},
                tooltip: {
                    displayColors: false,
                    backgroundColor: "#1E2528",
                    titleFont: mono,
                    bodyFont: { ...mono, size: 13, weight: "600" },
                    filter: (item) => item.parsed.y !== null,
                    callbacks: {
                        title: (items) =>
                            items.length ? fmtFull(items[0].parsed.x) : "",
                        label: (item) =>
                            `${label} ${item.parsed.y.toFixed(1).replace(".", ",")}%`,
                        afterLabel: (item) => item.chart.$note || "",
                    },
                },
            },
            onHover: (_evt, active, c) => c.$onHover?.(c, active),
        },
        plugins: [thresholdLines, overlays],
    });

    canvas.tabIndex = 0;
    return chart;
}

/** Sinkronkan garis bidik dan tooltip antar-grafik, untuk mouse maupun keyboard. */
export function linkCharts(charts) {
    const show = (chart, index) => {
        const data = chart.data.datasets[0].data;
        if (index < 0 || data[index]?.y == null) return;
        const el = chart.getDatasetMeta(0).data[index];
        if (!el) return;
        const active = [{ datasetIndex: 0, index }];
        chart.setActiveElements(active);
        chart.tooltip.setActiveElements(active, { x: el.x, y: el.y });
        chart.update("none");
    };
    const clear = (chart) => {
        chart.setActiveElements([]);
        chart.tooltip.setActiveElements([], { x: 0, y: 0 });
        chart.update("none");
    };
    const syncOthers = (source, x) => {
        for (const other of charts) {
            if (other !== source)
                show(other, nearestIndex(other.data.datasets[0].data, x));
        }
    };

    for (const chart of charts) {
        chart.$onHover = (c, active) => {
            if (!active.length) return;
            const point = c.data.datasets[0].data[active[0].index];
            if (point && point.y !== null) syncOthers(c, point.x);
        };

        chart.canvas.addEventListener("mouseleave", () =>
            charts.forEach(clear),
        );
        chart.canvas.addEventListener("blur", () => charts.forEach(clear));
        chart.canvas.addEventListener("keydown", (e) => {
            const data = chart.data.datasets[0].data;
            if (!data.length) return;
            const act = chart.getActiveElements();
            let i = act.length ? act[0].index : data.length - 1;
            let dir = 0;
            const step = e.shiftKey ? 10 : 1;
            if (e.key === "ArrowLeft") {
                i -= step;
                dir = -1;
            } else if (e.key === "ArrowRight") {
                i += step;
                dir = 1;
            } else if (e.key === "Home") {
                i = 0;
                dir = 1;
            } else if (e.key === "End") {
                i = data.length - 1;
                dir = -1;
            } else if (e.key === "Escape") {
                charts.forEach(clear);
                return;
            } else return;
            e.preventDefault();
            i = Math.max(0, Math.min(data.length - 1, i));
            while (data[i]?.y === null && i > 0 && i < data.length - 1)
                i += dir; // lewati titik celah
            show(chart, i);
            syncOthers(chart, data[i].x);
        });
    }
}
