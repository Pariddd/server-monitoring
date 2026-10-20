export const STATUS = {
    FIRING: {
        fg: "text-firing",
        lamp: "bg-firing-lamp",
        lampCls: "lamp lamp-fire",
        card: "border-firing-border bg-firing-tint",
        seg: "bg-firing-tint border-firing-border text-firing",
        rank: 0,
    },
    PENDING: {
        fg: "text-pending",
        lamp: "bg-pending-lamp",
        lampCls: "lamp",
        card: "border-hairline bg-panel",
        seg: "bg-pending-tint border-[#E3C77D] text-pending",
        rank: 1,
    },
    NORMAL: {
        fg: "text-normal",
        lamp: "bg-normal-lamp",
        lampCls: "lamp",
        card: "border-hairline bg-panel",
        seg: "bg-normal-tint border-[#BBD7C6] text-normal",
        rank: 2,
    },
    NONAKTIF: {
        fg: "text-muted",
        lamp: "bg-[#9AA7AD]",
        lampCls: "lamp",
        card: "border-hairline bg-panel",
        seg: "bg-panel border-hairline text-muted",
        rank: 3,
    },
};

/** Titik polyline sparkline. */
export function spark(values, w, h, min, max) {
    if (!values || values.length < 2) return "";
    return values
        .map((v, i) => {
            const x = (i / (values.length - 1)) * w;
            const clamped = Math.min(max, Math.max(min, v));
            const y = h - ((clamped - min) / (max - min)) * h;
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(" ");
}

/** Posisi x pada bilah zona selebar `width`. */
export function zoneX(value, min, max, width = 300) {
    const clamped = Math.min(max, Math.max(min, value));
    return ((clamped - min) / (max - min)) * width;
}

export function mmss(seconds) {
    const s = Math.max(0, Math.floor(seconds));
    return `${String(Math.floor(s / 60)).padStart(2, "0")}:${String(s % 60).padStart(2, "0")}`;
}
