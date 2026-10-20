export const POLL_OPTIONS = [
    [10000, "10 dtk"],
    [30000, "30 dtk"],
    [60000, "60 dtk"],
    [300000, "5 mnt"],
    [600000, "10 mnt"],
];
export const DEFAULT_POLL_MS = 10000;

/** Polling berantai (setTimeout), membaca interval dari store setiap siklus agar perubahan langsung berlaku. */
export function createPoller(url, onData) {
    let timer = null;
    let inFlight = false;
    let stopped = true;
    const store = () => window.Alpine.store("poll");

    async function tick() {
        if (document.hidden || inFlight) return;
        inFlight = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);

        try {
            const target = typeof url === "function" ? url() : url;
            const res = await fetch(target, {
                headers: { Accept: "application/json" },
                signal: controller.signal,
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            onData(await res.json());
            store().status = "ok";
            store().lastOkAt = new Date();
            store().cycle++;
        } catch (err) {
            store().status = "error";
            console.warn("Polling gagal:", err);
        } finally {
            clearTimeout(timeout);
            inFlight = false;
        }
    }

    function schedule() {
        clearTimeout(timer);
        if (stopped) return;
        timer = setTimeout(async () => {
            await tick();
            schedule();
        }, store().intervalMs);
    }

    const onVisible = () => {
        if (!document.hidden) {
            tick();
            schedule();
        }
    };
    const onIntervalChanged = () => {
        tick();
        schedule();
    };

    return {
        start() {
            stopped = false;
            tick();
            schedule();
            document.addEventListener("visibilitychange", onVisible);
            window.addEventListener("poll-interval-changed", onIntervalChanged);
        },
        stop() {
            stopped = true;
            clearTimeout(timer);
            document.removeEventListener("visibilitychange", onVisible);
            window.removeEventListener(
                "poll-interval-changed",
                onIntervalChanged,
            );
        },
        refresh: tick,
    };
}
