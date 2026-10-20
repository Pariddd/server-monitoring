import Alpine from "alpinejs";
import { fmtClock, fmtIsoTime, fmtNum } from "./lib/format";
import { POLL_OPTIONS, DEFAULT_POLL_MS } from "./lib/poller";
import summaryPage from "./pages/summary";
import detailPage from "./pages/detail";

window.Alpine = Alpine;

function loadPollMs() {
    try {
        const v = Number(localStorage.getItem("pollMs"));
        return POLL_OPTIONS.some(([ms]) => ms === v) ? v : DEFAULT_POLL_MS;
    } catch (e) {
        return DEFAULT_POLL_MS; // penyimpanan diblokir: pakai default
    }
}

Alpine.store("poll", {
    status: "idle",
    lastOkAt: null,
    cycle: 0,
    intervalMs: loadPollMs(),
    options: POLL_OPTIONS,
    get label() {
        return POLL_OPTIONS.find(([ms]) => ms === this.intervalMs)?.[1] ?? "";
    },
    setInterval(ms) {
        this.intervalMs = Number(ms);
        try {
            localStorage.setItem("pollMs", String(this.intervalMs));
        } catch (e) {
            /* abaikan */
        }
        window.dispatchEvent(new CustomEvent("poll-interval-changed"));
    },
});

Alpine.magic("fmt", () => ({ clock: fmtClock, time: fmtIsoTime, num: fmtNum }));

Alpine.data("utcClock", () => ({
    now: fmtClock(new Date()),
    init() {
        this._t = setInterval(() => {
            this.now = fmtClock(new Date());
        }, 1000);
    },
    destroy() {
        clearInterval(this._t);
    },
}));

Alpine.data("summaryPage", summaryPage);
Alpine.data("detailPage", detailPage);

Alpine.start();
