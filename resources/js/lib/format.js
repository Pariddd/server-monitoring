const pad = (n) => String(n).padStart(2, "0");

export const TZ_LABEL = "WIB";
export const TZ_OFFSET_MS = 7 * 3600 * 1000; // WIB = UTC+7, tanpa daylight saving

function shifted(input) {
    const ms =
        input instanceof Date
            ? input.getTime()
            : typeof input === "number"
              ? input
              : Date.parse(input);
    return new Date(ms + TZ_OFFSET_MS); // dibaca dengan getUTC* agar tidak bergantung zona waktu browser
}

/** "22:07:58" */
export function fmtClock(input) {
    if (input === null || input === undefined) return "–";
    const d = shifted(input);
    return `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())}`;
}

/** ISO UTC -> "22:07:58 WIB" */
export function fmtTime(iso) {
    return iso ? `${fmtClock(iso)} ${TZ_LABEL}` : "–";
}

/** ISO UTC berpresisi ms -> "22:07:58,215 WIB" */
export function fmtTimeMs(iso) {
    if (!iso) return "–";
    const d = shifted(iso);
    return `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())},${String(d.getUTCMilliseconds()).padStart(3, "0")} ${TZ_LABEL}`;
}

/** epoch ms -> "22:05" (label sumbu grafik) */
export function fmtHm(ms) {
    const d = shifted(ms);
    return `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
}

/** epoch ms -> "2026-10-20 22:07:58 WIB" (tooltip) */
export function fmtFull(ms) {
    const d = shifted(ms);
    return (
        `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())} ` +
        `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())} ${TZ_LABEL}`
    );
}

export function fmtNum(value, decimals = 1) {
    return value === null || value === undefined
        ? "–"
        : Number(value).toFixed(decimals).replace(".", ",");
}

// Nama lama tetap tersedia agar berkas lain tidak perlu diubah.
export const fmtClockUTC = fmtClock;
export const fmtIsoTime = fmtTime;
