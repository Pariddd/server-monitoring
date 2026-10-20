import { createPoller } from "../lib/poller";
import { fmtIsoTime, fmtNum } from "../lib/format";
import { STATUS, spark, zoneX, mmss } from "../lib/ui";

export default () => ({
    S: STATUS,
    data: null,
    filter: "ALL",
    q: "",
    hov: false,
    foc: false,
    frozen: null,
    showAllAlerts: false,
    receivedAt: 0,
    now: Date.now(),
    prevStates: {},
    flash: {},

    init() {
        this.poller = createPoller("/data/summary", (d) => this.onData(d));
        this.poller.start();
        this._onRefresh = () => this.poller.refresh();
        window.addEventListener("poll-refresh", this._onRefresh);
        this._tick = setInterval(() => {
            this.now = Date.now();
        }, 1000);
        this.$watch("q", () => {
            if (this.frozen) this.freeze();
        });
    },

    destroy() {
        this.poller.stop();
        window.removeEventListener("poll-refresh", this._onRefresh);
        clearInterval(this._tick);
    },

    onData(d) {
        for (const h of d.hosts) {
            const prev = this.prevStates[h.id];
            if (prev && prev !== h.state) {
                this.flash[h.id] = true;
                setTimeout(() => {
                    delete this.flash[h.id];
                }, 1700);
            }
            this.prevStates[h.id] = h.state;
        }
        this.data = d;
        this.receivedAt = Date.now();
    },

    // ---------- bantuan umum ----------
    spark,
    zoneX,
    fmtIsoTime,
    fmtNum,
    get elapsed() {
        return Math.max(0, (this.now - this.receivedAt) / 1000);
    },
    get pollOk() {
        return this.$store.poll.status !== "error";
    },
    get th() {
        return this.data?.thresholds ?? { cpu: 85, mem: 90, disk: 10 };
    },
    get room() {
        return this.data?.room ?? null;
    },
    get hostsAll() {
        return this.data ? this.data.hosts : [];
    },

    // ---------- ringkasan ----------
    get segs() {
        const c = this.data?.counts ?? { FIRING: 0, PENDING: 0, NORMAL: 0 };
        return ["FIRING", "PENDING", "NORMAL"].map((k) => ({ k, n: c[k] }));
    },
    segStyle(g) {
        const on = this.filter === g.k;
        return (
            `flex:${g.n} 1 0;margin:0 ${g.n ? 1.5 : 0}px;padding:0 ${g.n ? 10 : 0}px;` +
            `border-width:${g.n ? 1 : 0}px;box-shadow:${on ? "inset 0 0 0 2px #1E2528" : "none"}`
        );
    },
    get roomAlerts() {
        return (this.data?.alerts ?? []).filter(
            (a) => a.source_type === "esp32",
        );
    },
    get roomState() {
        if (!this.room) return null;
        const states = this.roomAlerts.map((a) => a.state);
        return states.includes("FIRING")
            ? "FIRING"
            : states.includes("PENDING")
              ? "PENDING"
              : "NORMAL";
    },
    get roomReason() {
        if (!this.room) return "ESP32 belum terhubung";
        const t = this.room.temperature.value,
            h = this.room.humidity.value;
        return `Suhu ${fmtNum(t)} °C · kelembaban ${fmtNum(h)}% RH`;
    },
    get attn() {
        const list = this.hostsAll
            .filter((h) => h.state !== "NORMAL")
            .map((h) => ({
                key: `h${h.id}`,
                name: h.name,
                state: h.state,
                href: this.hostHref(h),
            }));
        if (this.roomState && this.roomState !== "NORMAL") {
            list.push({
                key: "room",
                name: "Ruang server",
                state: this.roomState,
                href: "#ruang-server",
            });
        }
        return list.sort(
            (a, b) =>
                STATUS[a.state].rank - STATUS[b.state].rank ||
                a.name.localeCompare(b.name),
        );
    },

    // ---------- alert aktif ----------
    get alerts() {
        return this.data?.alerts ?? [];
    },
    get alertsShown() {
        return this.showAllAlerts ? this.alerts : this.alerts.slice(0, 4);
    },
    alertHref(a) {
        return a.source_type === "host"
            ? `/servers/${encodeURIComponent(a.source)}`
            : "#ruang-server";
    },
    alertSource(a) {
        return [a.source, a.ip, a.tag].filter(Boolean).join(" · ");
    },
    held(a) {
        return a.pending
            ? Math.min(a.pending.for, a.pending.held + this.elapsed)
            : 0;
    },
    pendingPct(a) {
        return a.pending ? (this.held(a) / a.pending.for) * 100 : 0;
    },
    alertTime(a) {
        if (a.state === "PENDING") {
            return `Melewati ambang ${fmtIsoTime(a.breach_at)} · menunggu ${Math.floor(this.held(a))}/${a.pending?.for ?? "–"} dtk`;
        }
        let text = `Melewati ambang ${fmtIsoTime(a.breach_at)} · FIRING sejak ${fmtIsoTime(a.since)}`;
        if (a.cooldown_left !== null)
            text += ` · pesan ulang dalam ${mmss(a.cooldown_left - this.elapsed)}`;
        return text;
    },

    // ---------- daftar server ----------
    matches(h) {
        const q = this.q.trim().toLowerCase();
        const f = this.filter;
        const okFilter =
            f === "ALL" ||
            (f === "ATTN" ? h.state !== "NORMAL" : h.state === f);
        const okQuery =
            !q || h.name.toLowerCase().includes(q) || (h.ip ?? "").includes(q);
        return okFilter && okQuery;
    },
    get hostsFresh() {
        return this.hostsAll.filter((h) => this.matches(h));
    },
    get hosts() {
        if (!this.frozen) return this.hostsFresh;
        const byId = new Map(this.hostsAll.map((h) => [h.id, h]));
        return this.frozen.map((id) => byId.get(id)).filter(Boolean);
    },
    get heldDiff() {
        return (
            !!this.frozen &&
            this.frozen.join() !== this.hostsFresh.map((h) => h.id).join()
        );
    },
    get chips() {
        const all = this.hostsAll;
        return [
            { k: "ALL", label: "Semua", n: all.length },
            {
                k: "ATTN",
                label: "Perlu perhatian",
                n: all.filter((h) => h.state !== "NORMAL").length,
            },
            {
                k: "FIRING",
                label: "FIRING",
                n: all.filter((h) => h.state === "FIRING").length,
                lamp: "bg-firing-lamp",
            },
            {
                k: "PENDING",
                label: "PENDING",
                n: all.filter((h) => h.state === "PENDING").length,
                lamp: "bg-pending-lamp",
            },
            {
                k: "NORMAL",
                label: "NORMAL",
                n: all.filter((h) => h.state === "NORMAL").length,
                lamp: "bg-normal-lamp",
            },
        ];
    },
    setFilter(k) {
        this.filter = k;
        if (this.frozen) this.freeze();
    },
    resetFilter() {
        this.filter = "ALL";
        this.q = "";
    },
    freeze() {
        this.frozen = this.hostsFresh.map((h) => h.id);
    },
    holdOn() {
        if (!this.hov) {
            this.hov = true;
            if (!this.frozen) this.freeze();
        }
    },
    holdOff() {
        this.hov = false;
        if (!this.foc) this.frozen = null;
    },
    focusIn() {
        if (!this.foc) {
            this.foc = true;
            if (!this.frozen) this.freeze();
        }
    },
    focusOut(e) {
        if (e.relatedTarget && this.$refs.grid.contains(e.relatedTarget))
            return;
        this.foc = false;
        if (!this.hov) this.frozen = null;
    },
    hostHref(h) {
        return `/servers/${encodeURIComponent(h.name)}`;
    },
    seen(h) {
        return h.seen_seconds === null
            ? "–"
            : `${Math.round(h.seen_seconds + this.elapsed)} dtk lalu`;
    },
    pct(v) {
        return v === null || v === undefined ? "–" : `${fmtNum(v, 0)}%`;
    },
    barWidth(v) {
        return v === null || v === undefined
            ? 0
            : Math.min(100, Math.max(0, v));
    },

    cardDisks(h) {
        const disks = h.disks ?? [];
        return disks.length <= 3
            ? disks
            : [...disks].sort((a, b) => a.free - b.free).slice(0, 3);
    },
    pct1(v) {
        return v === null || v === undefined ? "–" : `${fmtNum(v, 1)}%`;
    },
});
