import { createPoller } from "../lib/poller";
import { fmtIsoTime, fmtNum, fmtTimeMs } from "../lib/format";
import { STATUS, mmss } from "../lib/ui";
import {
    createMetricChart,
    linkCharts,
    nearestIndex,
    splitGaps,
} from "../lib/charts";

const chartsOf = new WeakMap(); // di luar state reaktif Alpine

const NOTE = { "30m": "", "3h": "maks per 1 mnt", "24h": "maks per 10 mnt" };
const STEP = { "30m": 300000, "3h": 1800000, "24h": 14400000 }; // label sumbu: 5 mnt, 30 mnt, 4 jam
const GAP = { "30m": 25000, "3h": 150000, "24h": 1500000 }; // ±2,5x interval data: lebih dari ini = celah

export default (sourceName) => ({
    S: STATUS,
    name: sourceName,
    range: "30m",
    data: null,
    receivedAt: 0,
    now: Date.now(),
    copied: false,
    ranges: [
        ["30m", "30 mnt"],
        ["3h", "3 jam"],
        ["24h", "24 jam"],
    ],

    init() {
        const cpu = createMetricChart(this.$refs.cpu, "CPU");
        const mem = createMetricChart(this.$refs.mem, "Memori");
        linkCharts([cpu, mem]);
        chartsOf.set(this.$el, { cpu, mem });

        this.poller = createPoller(
            () =>
                `/data/servers/${encodeURIComponent(this.name)}?range=${this.range}`,
            (d) => this.onData(d),
        );
        this.poller.start();
        this._onRefresh = () => this.poller.refresh();
        window.addEventListener("poll-refresh", this._onRefresh);
        this._tick = setInterval(() => {
            this.now = Date.now();
        }, 1000);
    },

    destroy() {
        this.poller.stop();
        window.removeEventListener("poll-refresh", this._onRefresh);
        clearInterval(this._tick);
        const c = chartsOf.get(this.$el);
        c?.cpu.destroy();
        c?.mem.destroy();
    },

    setRange(r) {
        if (this.range === r) return;
        this.range = r;
        this.poller.refresh();
    },

    onData(d) {
        if (d.range !== this.range) {
            // respons untuk rentang lama: minta ulang
            setTimeout(() => this.poller.refresh(), 0);
            return;
        }
        this.data = d;
        this.receivedAt = Date.now();

        const c = chartsOf.get(this.$el);
        for (const key of ["cpu", "mem"]) {
            const chart = c[key];
            const raw = d.series[key].map(([x, y]) => ({ x, y }));
            const { points, gaps } = splitGaps(raw, GAP[d.range], d.to_ms);
            chart.data.datasets[0].data = points;
            chart.options.scales.x.min = d.from_ms;
            chart.options.scales.x.max = d.to_ms;
            chart.options.plugins.thresholdLines = {
                fire: d.thresholds[key].fire,
                recover: d.thresholds[key].recover,
                gaps,
            };
            chart.options.plugins.overlays = {
                breaches: d.breaches
                    .filter((b) => b.series === key)
                    .map((b) => {
                        const i = nearestIndex(raw, b.t_ms);
                        return {
                            x: b.t_ms,
                            y: i >= 0 ? raw[i].y : d.thresholds[key].fire,
                            label: fmtIsoTime(b.iso),
                        };
                    }),
            };
            chart.$step = STEP[d.range];
            chart.$note = NOTE[d.range];
            chart.update("none");
        }
    },

    // ---------- tampilan ----------
    fmtIsoTime,
    fmtNum,
    fmtTimeMs,
    get elapsed() {
        return Math.max(0, (this.now - this.receivedAt) / 1000);
    },
    get src() {
        return this.data?.source ?? null;
    },
    get state() {
        return this.src?.state ?? "NORMAL";
    },
    get seen() {
        return !this.src || this.src.seen_seconds === null
            ? null
            : this.src.seen_seconds + this.elapsed;
    },
    get stale() {
        return (
            !!this.src &&
            this.src.seen_seconds !== null &&
            this.src.seen_seconds > (this.src.heartbeat_limit ?? 30)
        );
    },
    get lastCpu() {
        return this.data?.series.cpu.at(-1)?.[1] ?? null;
    },
    get lastMem() {
        return this.data?.series.mem.at(-1)?.[1] ?? null;
    },
    get bucketNote() {
        return { "3h": "1 menit", "24h": "10 menit" }[this.range] ?? null;
    },
    get tracePath() {
        let d = "";
        for (let k = 0; k < 8; k++) {
            const o = k * 52;
            d += `M${o} 18 H${o + 12} L${o + 16} 18 L${o + 20} 4 L${o + 25} 30 L${o + 29} 18 H${o + 52} `;
        }
        return d;
    },
    held(a) {
        return a.pending
            ? Math.min(a.pending.for, a.pending.held + this.elapsed)
            : 0;
    },
    cooldown(a) {
        return a.cooldown_left === null
            ? null
            : mmss(a.cooldown_left - this.elapsed);
    },
    delta(fromIso, toIso) {
        if (!fromIso || !toIso) return "";
        const s = (Date.parse(toIso) - Date.parse(fromIso)) / 1000;
        return s < 1 ? `+${Math.round(s * 1000)} ms` : `+${fmtNum(s, 1)} dtk`;
    },
    steps(a) {
        const n = a.notification;
        if (!n) return [];
        return [
            {
                key: "t_breach",
                label: "Data melewati ambang",
                time: n.t_breach,
                delta: "",
            },
            {
                key: "t_detected",
                label: "Terdeteksi FIRING",
                time: n.t_detected,
                delta: this.delta(n.t_breach, n.t_detected),
            },
            {
                key: "t_queued",
                label: "Masuk antrean",
                time: n.t_queued,
                delta: this.delta(n.t_detected, n.t_queued),
            },
            {
                key: "t_sent",
                label:
                    n.status === "failed"
                        ? "Gagal terkirim"
                        : "Terkirim ke Telegram",
                time: n.t_sent,
                delta: this.delta(n.t_queued, n.t_sent),
                failed: n.status === "failed",
            },
        ];
    },
    diskX(v) {
        return Math.min(400, Math.max(0, v * 4));
    },
    async copyIp() {
        try {
            await navigator.clipboard.writeText(this.src.ip);
            this.copied = true;
            setTimeout(() => {
                this.copied = false;
            }, 2000);
        } catch (e) {
            /* clipboard tidak tersedia */
        }
    },
});
