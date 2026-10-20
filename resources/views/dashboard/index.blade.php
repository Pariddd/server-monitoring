@extends('layouts.app')

@section('title', 'Ringkasan · Monitoring Server')

@section('content')
<div x-data="summaryPage" class="flex flex-wrap items-start gap-6">

    {{-- ================= KOLOM UTAMA ================= --}}
    <div class="flex min-w-0 flex-[1_1_640px] flex-col gap-5">

        {{-- Ringkasan --}}
        <section aria-label="Ringkasan" class="flex flex-col gap-3.5 rounded-md border border-hairline bg-panel px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-x-5 gap-y-3">
                <div class="flex items-baseline gap-2.5">
                    <span class="font-mono text-[44px] font-semibold leading-none" x-text="data ? data.counts.total : '–'">–</span>
                    <span class="text-base text-muted">server dipantau</span>
                </div>
                <a href="#ruang-server" class="inline-flex min-h-11 flex-wrap items-center gap-x-2.5 gap-y-1 rounded-md border border-control bg-white px-3.5 py-1.5 text-graphite no-underline">
                    <span class="text-muted">Ruang server</span>
                    <template x-if="roomState">
                        <span class="inline-flex items-center gap-2 font-bold" :class="S[roomState].fg">
                            <span :class="S[roomState].lampCls + ' ' + S[roomState].lamp"></span><span x-text="roomState"></span>
                        </span>
                    </template>
                    <span class="text-sm text-body" x-text="roomReason"></span>
                </a>
            </div>

            <div role="group" aria-label="Komposisi status server, klik untuk memfilter" class="flex">
                <template x-for="g in segs" :key="g.k">
                    <button type="button"
                            class="segb flex h-11 min-w-0 cursor-pointer items-center justify-center gap-2 overflow-hidden whitespace-nowrap rounded border-solid font-bold"
                            :class="S[g.k].seg" :style="segStyle(g)"
                            :aria-pressed="filter === g.k ? 'true' : 'false'"
                            :tabindex="g.n ? 0 : -1" :aria-hidden="g.n ? 'false' : 'true'"
                            @click="setFilter(filter === g.k ? 'ALL' : g.k)">
                        <span :class="S[g.k].lampCls + ' ' + S[g.k].lamp" style="width:8px;height:8px"></span>
                        <span x-text="g.k"></span>
                        <span class="font-mono font-semibold" x-text="g.n"></span>
                    </button>
                </template>
            </div>

            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span class="text-muted">Perlu perhatian</span>
                <template x-for="n in attn.slice(0, 5)" :key="n.key">
                    <a :href="n.href" class="inline-flex h-11 items-center gap-2 rounded-md border border-control bg-white px-3.5 font-bold text-graphite no-underline">
                        <span :class="S[n.state].lampCls + ' ' + S[n.state].lamp" style="width:8px;height:8px"></span>
                        <span x-text="n.name"></span>
                        <span class="text-[13px] font-semibold" :class="S[n.state].fg" x-text="n.state"></span>
                    </a>
                </template>
                <template x-if="attn.length > 5">
                    <button type="button" @click="setFilter('ATTN'); $refs.servers.scrollIntoView()"
                            class="inline-flex h-11 cursor-pointer items-center rounded-md border border-control bg-panel px-3.5 font-semibold text-trace"
                            x-text="`+${attn.length - 5} lainnya, tampilkan di daftar`"></button>
                </template>
                <template x-if="data && attn.length === 0">
                    <span class="font-semibold text-normal">Semua server dan ruang server normal</span>
                </template>
            </div>
        </section>

        {{-- Alert aktif --}}
        <section aria-label="Alert aktif" class="flex flex-col rounded-md border border-hairline bg-panel">
            <div class="flex items-baseline justify-between px-5 pb-2.5 pt-4">
                <h2 class="text-xl font-semibold">Alert aktif
                    <span class="font-mono text-base font-medium text-muted" x-text="alerts.length"></span>
                </h2>
                <span class="text-sm text-muted">Urut: FIRING dulu, lalu PENDING</span>
            </div>

             <template x-if="data?.disabled_rules?.length">
                <div class="border-t border-hairline bg-[#EEF1F2] px-5 py-2.5 text-sm text-body">
                    Aturan nonaktif, tidak dievaluasi: <span class="font-semibold" x-text="data.disabled_rules.join(', ')"></span>. Aktifkan lagi sebelum pengujian.
                </div>
            </template>

            <template x-for="a in alertsShown" :key="a.id">
                <a :href="alertHref(a)" class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-hairline px-5 py-3 text-graphite no-underline"
                   :class="a.state === 'FIRING' ? 'bg-firing-tint' : ''">
                    <span class="inline-flex w-[104px] shrink-0 items-center gap-2 font-bold" :class="S[a.state].fg">
                        <span :class="S[a.state].lampCls + ' ' + S[a.state].lamp"></span><span x-text="a.state"></span>
                    </span>
                    <span class="flex min-w-0 flex-[1_1_220px] flex-col">
                        <span class="text-base font-semibold" x-text="a.rule"></span>
                        <span class="text-sm text-muted" x-text="alertSource(a)"></span>
                    </span>
                    <span class="min-w-[88px] font-mono text-xl font-semibold" :class="S[a.state].fg" x-text="a.value"></span>
                    <span class="flex min-w-0 flex-[1_1_250px] flex-col gap-1.5 text-sm text-body">
                        <span x-text="alertTime(a)"></span>
                        <template x-if="a.state === 'PENDING' && a.pending">
                            <span class="block h-1.5 w-full overflow-hidden rounded-full bg-[#E6D8B0]" role="img"
                                  :aria-label="`${Math.floor(held(a))} dari ${a.pending.for} detik`">
                                <span class="block h-full rounded-full bg-pending transition-[width] duration-1000 ease-linear"
                                      :style="`width:${pendingPct(a)}%`"></span>
                            </span>
                        </template>
                    </span>
                </a>
            </template>

            <template x-if="alerts.length > 4">
                <button type="button" @click="showAllAlerts = !showAllAlerts" :aria-expanded="showAllAlerts ? 'true' : 'false'"
                        class="flex h-11 w-full cursor-pointer items-center border-t border-hairline bg-transparent px-5 text-left font-semibold text-trace"
                        x-text="showAllAlerts ? 'Ringkas daftar alert' : `Tampilkan ${alerts.length - 4} alert lainnya`"></button>
            </template>

            <template x-if="!data">
                <div class="flex flex-col items-center gap-1 border-t border-hairline px-5 pb-7 pt-6 text-center">
                    <x-mascot mood="fast" label="Maskot unit rak memindai cepat saat data dimuat" />
                    <span class="text-xl font-bold">Memuat data server</span>
                    <span class="text-muted">Mengambil metrik 10 detik terakhir.</span>
                </div>
            </template>
            <template x-if="data && alerts.length === 0">
                <div class="flex flex-col items-center gap-1 border-t border-hairline px-5 pb-7 pt-6 text-center">
                    <x-mascot mood="scan" label="Maskot unit rak berjaga, matanya menyapu kiri dan kanan" />
                    <span class="text-xl font-bold">Tidak ada alert aktif</span>
                    <span class="text-muted">Semua aturan dalam kondisi normal.</span>
                </div>
            </template>
        </section>

        {{-- Daftar server --}}
        <section aria-label="Daftar server" x-ref="servers" class="flex flex-col gap-3.5">
            <div class="flex flex-wrap items-center justify-between gap-x-5 gap-y-2.5">
                <div class="flex flex-wrap items-baseline gap-x-3.5 gap-y-1.5">
                    <h2 class="text-xl font-semibold">Server
                        <span class="font-mono text-base font-medium text-muted" x-text="`${hosts.length} dari ${hostsAll.length}`"></span>
                    </h2>
                    <template x-if="heldDiff">
                        <span role="status" class="inline-flex items-center gap-1.5 text-sm text-body">
                            <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><rect x="2" y="1.5" width="3" height="9" rx="1" fill="currentColor"/><rect x="7" y="1.5" width="3" height="9" rx="1" fill="currentColor"/></svg>
                            Urutan ditahan selagi kursor di daftar, diperbarui setelah kursor keluar
                        </span>
                    </template>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <template x-for="c in chips" :key="c.k">
                        <button type="button" @click="setFilter(c.k)" :aria-pressed="filter === c.k ? 'true' : 'false'"
                                class="inline-flex h-11 cursor-pointer items-center gap-1.5 rounded-md border px-3.5 font-semibold"
                                :class="filter === c.k ? 'border-graphite bg-graphite text-white' : 'border-control bg-panel text-graphite hover:bg-[#DDE2E3]'">
                            <template x-if="c.lamp"><span class="lamp" :class="c.lamp" style="width:8px;height:8px"></span></template>
                            <span x-text="c.label"></span>
                            <span class="font-mono font-medium" x-text="c.n"></span>
                        </button>
                    </template>
                    <label class="flex items-center">
                        <span class="sr-only">Cari server</span>
                        <input type="search" x-model="q" placeholder="Cari nama atau IP"
                               class="h-11 w-56 rounded-md border border-control bg-white px-3 text-graphite">
                    </label>
                </div>
            </div>

            <div x-ref="grid" @mouseenter="holdOn()" @mouseleave="holdOff()" @focusin="focusIn()" @focusout="focusOut($event)"
                 class="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(250px,1fr))]">
                <template x-for="h in hosts" :key="h.id">
                    <a :href="hostHref(h)" class="flex flex-col gap-3 rounded-md border px-4 py-3.5 text-graphite no-underline"
                       :class="[S[h.state].card, flash[h.id] ? 'flash-once' : '']">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <svg width="46" height="22" viewBox="0 0 46 22" aria-hidden="true" class="shrink-0">
                                    <rect x="1" y="1" width="44" height="20" rx="3" fill="#2B3438" stroke="#1E2528" stroke-width="1.5"/>
                                    <circle cx="10" cy="11" r="3" fill="#34A06A"/>
                                    <circle :class="pollOk ? 'led' : ''" cx="20" cy="11" r="3" fill="#7FD3A7"/>
                                    <circle cx="30" cy="11" r="3" :fill="h.state === 'FIRING' ? '#E5482D' : '#556166'"/>
                                    <rect x="36" y="8" width="6" height="6" rx="1" fill="#556166"/>
                                </svg>
                                <div class="flex min-w-0 flex-col">
                                    <span class="truncate text-[19px] font-bold leading-tight" x-text="h.name"></span>
                                    <span class="truncate text-[13px] text-muted" :class="h.ip ? 'font-mono' : 'font-sans'"
      :title="h.ip || 'IP belum diketahui'" x-text="h.ip || 'IP belum diketahui'"></span>
                                </div>
                            </div>
                            <span class="inline-flex shrink-0 items-center gap-1.5 text-sm font-bold" :class="S[h.state].fg">
                                <span :class="S[h.state].lampCls + ' ' + S[h.state].lamp"></span><span x-text="h.state"></span>
                            </span>
                        </div>

                        <div class="flex items-end justify-between gap-2.5">
                            <div class="flex flex-col">
                                <span class="text-[13px] text-muted">CPU</span>
                                <span class="font-mono text-[28px] font-semibold leading-tight" :class="h.cpu > th.cpu ? 'text-firing' : ''" x-text="pct(h.cpu)"></span>
                            </div>
                            <svg viewBox="0 0 120 34" class="h-[34px] w-[120px]" role="img" aria-label="Tren CPU 3 menit terakhir">
                                <line x1="0" x2="120" :y1="34 - th.cpu * 0.34" :y2="34 - th.cpu * 0.34" stroke="#B02A14" stroke-width="1" stroke-dasharray="3 3"/>
                                <polyline :points="spark(h.cpu_spark, 120, 34, 0, 100)" fill="none" stroke="#0F5C73" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
                            </svg>
                        </div>

                        <div class="flex flex-col gap-1.5 text-sm">
                            <div class="flex items-center gap-2.5">
                                <span class="w-[60px] shrink-0 text-muted">Memori</span>
                                <span class="relative h-1.5 min-w-0 flex-1 rounded-full bg-hairline" role="img" :aria-label="`Memori terpakai ${pct(h.mem)}`">
                                    <span class="absolute inset-y-0 left-0 rounded-full" :class="h.mem > th.mem ? 'bg-firing' : 'bg-trace'" :style="`width:${barWidth(h.mem)}%`"></span>
                                    <span class="absolute inset-y-0 w-[1.5px] bg-firing" :style="`left:${th.mem}%`"></span>
                                </span>
                                <span class="w-10 shrink-0 text-right font-mono font-medium" x-text="pct(h.mem)"></span>
                            </div>
                                                        <template x-for="d in cardDisks(h)" :key="d.volume">
                                <div class="flex items-center gap-2.5">
                                    <span class="w-[60px] shrink-0 text-muted" x-text="`Disk ${d.volume}`"></span>
                                    <span class="relative h-1.5 min-w-0 flex-1 rounded-full bg-hairline" role="img" :aria-label="`Disk ${d.volume} bebas ${pct1(d.free)}`">
                                        <span class="absolute inset-y-0 left-0 rounded-full" :class="d.free < th.disk ? 'bg-firing' : 'bg-trace'" :style="`width:${barWidth(d.free)}%`"></span>
                                        <span class="absolute inset-y-0 w-[1.5px] bg-firing" :style="`left:${th.disk}%`"></span>
                                    </span>
                                    <span class="w-12 shrink-0 text-right font-mono font-medium" x-text="pct1(d.free)"></span>
                                </div>
                            </template>
                            <template x-if="h.disks.length > 3">
                                <span class="text-[13px] text-muted" x-text="`+${h.disks.length - 3} volume lain, lihat detail`"></span>
                            </template>
                            <template x-if="h.disks.length === 0">
                                <span class="text-[13px] text-muted">Belum ada data disk</span>
                            </template>
                        </div>

                        <div class="flex items-center justify-between gap-2 border-t border-hairline pt-2.5 text-[13px] text-body">
                            <span>Layanan <span class="font-mono font-medium" x-text="`${h.services.running}/${h.services.total}`"></span> running</span>
                             <span class="font-mono" :title="'Data terakhir diterima'" x-text="h.last_seen_at ? fmtIsoTime(h.last_seen_at) : '–'"></span>
                        </div>

                        <template x-if="h.note">
                            <div class="text-sm font-semibold" :class="S[h.state].fg" x-text="h.note"></div>
                        </template>
                    </a>
                </template>
            </div>

            <template x-if="data && hosts.length === 0">
                <div class="flex flex-col items-center gap-1.5 rounded-md border border-hairline bg-panel px-5 py-7 text-center">
                    <x-mascot mood="down" label="Maskot unit rak melihat ke bawah, tidak menemukan server" />
                    <span class="text-lg font-semibold">Tidak ada server yang cocok</span>
                    <span class="text-muted">Ubah kata pencarian atau pilih filter status lain.</span>
                    <button type="button" @click="resetFilter()" class="mt-2 h-11 cursor-pointer rounded-md bg-trace px-5 font-semibold text-white">Hapus filter</button>
                </div>
            </template>
        </section>
    </div>

    {{-- ================= RUANG SERVER ================= --}}
    <aside id="ruang-server" aria-label="Lingkungan ruang server"
           class="sticky top-4 flex min-w-[300px] flex-[0_1_340px] flex-col self-start rounded-md border border-hairline bg-panel">
        <div class="flex flex-col gap-1.5 px-5 pb-3 pt-4">
            <h2 class="text-xl font-semibold">Ruang server</h2>
            <template x-if="room">
                <span class="text-sm text-body">
                    <span x-text="room.name"></span><template x-if="room.ip"><span> · <span class="font-mono" x-text="room.ip"></span></span></template>
                    · data <span class="font-mono" x-text="room.seen_seconds === null ? '–' : Math.round(room.seen_seconds + elapsed)"></span> dtk lalu
                    (batas <span x-text="room.heartbeat_limit"></span>)
                </span>
            </template>
        </div>

        <template x-if="!room">
            <div class="border-t border-hairline px-5 pb-5 pt-4 text-sm text-body">
                Belum ada perangkat ESP32 terdaftar. Suhu dan kelembaban akan tampil di sini setelah ESP32 mengirim data.
            </div>
        </template>

        <template x-if="room">
            <div>
                <template x-for="b in [
                        { key: 'temperature', label: 'Suhu', unit: ' °C', min: 20, max: 32 },
                        { key: 'humidity', label: 'Kelembaban', unit: '% RH', min: 45, max: 65 } ]" :key="b.key">
                    <div class="flex flex-col gap-2.5 border-t border-hairline px-5 pb-4 pt-3.5" x-data="{ get m() { return room[b.key] } }">
                        <div class="flex items-end justify-between gap-2.5">
                            <div class="flex flex-col">
                                <span class="font-semibold" x-text="b.label"></span>
                                <span class="font-mono text-[34px] font-semibold leading-tight" :class="m.state !== 'NORMAL' ? S[m.state].fg : ''"
                                      x-text="m.value === null ? '–' : fmtNum(m.value) + b.unit"></span>
                            </div>
                            <div class="flex flex-col items-end gap-1.5">
                                <span class="inline-flex items-center gap-2 font-bold" :class="S[m.state].fg">
                                    <span :class="S[m.state].lampCls + ' ' + S[m.state].lamp"></span><span x-text="m.state"></span>
                                </span>
                                <svg viewBox="0 0 100 32" width="100" height="32" role="img" :aria-label="`Tren ${b.label.toLowerCase()} 3 menit terakhir`">
                                    <polyline :points="spark(m.spark, 100, 32, b.min, b.max)" fill="none" stroke="#0F5C73" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
                                </svg>
                            </div>
                        </div>
                        <svg viewBox="0 0 300 48" class="block h-auto w-full" role="img"
                             :aria-label="`${b.label} ${fmtNum(m.value)}; pulih di bawah ${m.recover}, ambang ${m.threshold}`">
                            <rect x="0" y="12" :width="zoneX(m.recover, b.min, b.max)" height="14" fill="#E1EEE6"/>
                            <rect :x="zoneX(m.recover, b.min, b.max)" y="12" :width="zoneX(m.threshold, b.min, b.max) - zoneX(m.recover, b.min, b.max)" height="14" fill="#FBEFD0"/>
                            <rect :x="zoneX(m.threshold, b.min, b.max)" y="12" :width="300 - zoneX(m.threshold, b.min, b.max)" height="14" fill="#F7E4DF"/>
                            <rect x="0.75" y="12.75" width="298.5" height="12.5" rx="3" fill="none" stroke="#9AA7AD" stroke-width="1.5"/>
                            <line :x1="zoneX(m.recover, b.min, b.max)" :x2="zoneX(m.recover, b.min, b.max)" y1="12" y2="30" stroke="#1E6B43" stroke-width="2"/>
                            <line :x1="zoneX(m.threshold, b.min, b.max)" :x2="zoneX(m.threshold, b.min, b.max)" y1="12" y2="30" stroke="#B02A14" stroke-width="2"/>
                            <template x-if="m.value !== null">
                                <g :style="`transform:translateX(${zoneX(m.value, b.min, b.max)}px);transition:transform .6s ease-out`">
                                    <line x1="0" y1="6" x2="0" y2="26" stroke="#1E2528" stroke-width="3"/><path d="M-5 0 L5 0 L0 7 Z" fill="#1E2528"/>
                                </g>
                            </template>
                            <text x="0" y="44" font-size="12" fill="#5B676C" font-family="IBM Plex Mono,monospace" x-text="b.min"></text>
                            <text :x="zoneX(m.recover, b.min, b.max) - 4" y="44" text-anchor="end" font-size="12" fill="#1E6B43" font-weight="600" x-text="`pulih ${m.recover}`"></text>
                            <text :x="zoneX(m.threshold, b.min, b.max) + 4" y="44" font-size="12" fill="#B02A14" font-weight="600" x-text="`ambang ${m.threshold}`"></text>
                            <text x="300" y="44" text-anchor="end" font-size="12" fill="#5B676C" font-family="IBM Plex Mono,monospace" x-text="b.max"></text>
                        </svg>
                        <template x-if="m.pending">
                            <div class="flex items-center gap-2.5 text-sm text-body">
                                <span class="whitespace-nowrap">Menunggu <span class="font-mono font-semibold" x-text="`${Math.min(m.pending.for, Math.floor(m.pending.held + elapsed))}/${m.pending.for} dtk`"></span></span>
                                <span class="block h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-[#E6D8B0]">
                                    <span class="block h-full rounded-full bg-pending" :style="`width:${Math.min(100, (m.pending.held + elapsed) / m.pending.for * 100)}%`"></span>
                                </span>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </aside>
</div>
@endsection