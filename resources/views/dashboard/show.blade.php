@extends('layouts.app')

@section('title', $source->name.' · Monitoring Server')

@section('content')
<div x-data="detailPage(@js($source->name))" class="flex flex-col gap-5">

    <nav aria-label="Jejak halaman" class="flex items-center gap-2.5">
        <a href="{{ route('dashboard') }}" class="inline-flex h-11 items-center gap-1.5 font-semibold text-trace no-underline">
            <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M10 3L5 8l5 5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Ringkasan
        </a>
        <span class="text-[#8D9AA0]">/</span>
        <span class="font-semibold">{{ $source->name }}</span>
    </nav>

    {{-- Identitas --}}
    <section aria-label="Identitas server" class="flex flex-wrap items-center gap-x-10 gap-y-5 rounded-md border border-hairline bg-panel px-6 py-5">
        <svg viewBox="0 0 360 84" width="360" height="84" class="max-w-full shrink-0" role="img"
             :aria-label="`Panel depan ${name}: lampu data ${stale ? 'mati' : 'berkedip'}, lampu alarm ${state === 'FIRING' ? 'menyala merah' : 'mati'}`">
            <rect x="4" y="12" width="352" height="62" rx="6" fill="#2B3438" stroke="#1E2528" stroke-width="2"/>
            <rect x="0" y="16" width="12" height="54" rx="2" fill="#3A464C"/><rect x="348" y="16" width="12" height="54" rx="2" fill="#3A464C"/>
            <circle cx="6" cy="26" r="2.5" fill="#1E2528"/><circle cx="6" cy="60" r="2.5" fill="#1E2528"/>
            <circle cx="354" cy="26" r="2.5" fill="#1E2528"/><circle cx="354" cy="60" r="2.5" fill="#1E2528"/>
            <circle cx="52" cy="38" r="7" fill="#34A06A"/>
            <circle :class="stale || $store.poll.status === 'error' ? '' : 'led'" cx="108" cy="38" r="7" :fill="stale ? '#556166' : '#7FD3A7'"/>
            <circle cx="164" cy="38" r="7" :fill="state === 'FIRING' ? '#E5482D' : '#556166'"/>
            <text x="52" y="62" text-anchor="middle" font-size="12" fill="#B9C4C8">Daya</text>
            <text x="108" y="62" text-anchor="middle" font-size="12" fill="#B9C4C8">Data</text>
            <text x="164" y="62" text-anchor="middle" font-size="12" fill="#B9C4C8">Alarm</text>
            <g stroke="#556166" stroke-width="3" stroke-linecap="round">
                <line x1="214" y1="26" x2="214" y2="58"/><line x1="228" y1="26" x2="228" y2="58"/><line x1="242" y1="26" x2="242" y2="58"/>
                <line x1="256" y1="26" x2="256" y2="58"/><line x1="270" y1="26" x2="270" y2="58"/><line x1="284" y1="26" x2="284" y2="58"/>
                <line x1="298" y1="26" x2="298" y2="58"/><line x1="312" y1="26" x2="312" y2="58"/><line x1="326" y1="26" x2="326" y2="58"/>
            </g>
        </svg>

        <div class="flex min-w-0 flex-[1_1_360px] flex-col gap-2">
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1.5">
                <h1 class="text-[38px] font-bold leading-none">{{ $source->name }}</h1>
                <span class="text-base text-muted">Host Windows</span>
                <span class="inline-flex items-center gap-2 text-[17px] font-bold" :class="S[state].fg">
                    <span :class="S[state].lampCls + ' ' + S[state].lamp"></span><span x-text="state"></span>
                </span>
            </div>
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-body">
                <span class="flex flex-col">
                    <span>Data terakhir diterima
                        <span class="font-mono font-semibold" x-text="src?.last_seen_at ? fmtIsoTime(src.last_seen_at) : '–'"></span>
                    </span>
                    <span class="text-sm text-muted">Dianggap mati bila tidak ada data &gt; <span x-text="src?.heartbeat_limit ?? 30"></span> dtk</span>
                </span>
                <svg viewBox="0 0 260 36" width="260" height="36" class="max-w-full" role="img"
                     :aria-label="stale ? 'Jejak denyut datar: tidak ada data masuk' : 'Jejak denyut: data masuk teratur'">
                    <template x-if="!stale">
                        <g class="trace"><path :d="tracePath" fill="none" stroke="#1E6B43" stroke-width="2" stroke-linejoin="round"/></g>
                    </template>
                    <template x-if="stale">
                        <line x1="0" y1="18" x2="260" y2="18" stroke="#B02A14" stroke-width="2" stroke-dasharray="6 4"/>
                    </template>
                </svg>
            </div>
            <div class="mt-1 flex flex-wrap gap-x-8 gap-y-3 border-t border-hairline pt-3">
                <div class="flex flex-col gap-0.5">
                    <span class="text-[13px] text-muted">IP terakhir diketahui</span>
                    <div class="flex items-center gap-2.5">
                        <span class="text-xl font-semibold" :class="src?.ip ? 'font-mono' : 'font-sans text-muted'" x-text="src?.ip || 'IP belum diketahui'"></span>
                        <template x-if="src?.ip">
                            <button type="button" @click="copyIp()" class="inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-control bg-panel px-3.5 font-semibold">
                                <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="5" y="5" width="8" height="8" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M3 10V4.5A1.5 1.5 0 0 1 4.5 3H10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                <span x-text="copied ? 'Tersalin' : 'Salin'"></span>
                            </button>
                        </template>
                    </div>
                    <template x-if="src?.ip_updated_at">
                        <span class="text-[13px] text-muted">diperbarui <span class="font-mono" x-text="fmtIsoTime(src.ip_updated_at)"></span></span>
                    </template>
                </div>
                <div class="flex flex-col gap-0.5"><span class="text-[13px] text-muted">Tipe sumber</span><span class="text-base font-semibold">Host Windows</span></div>
                <div class="flex flex-col gap-0.5"><span class="text-[13px] text-muted">Jalur data</span><span class="text-base font-semibold">Telegraf ke Laravel</span></div>
            </div>
        </div>
    </section>

    <template x-if="data?.disabled_rules?.length">
        <div class="rounded-md border border-hairline bg-[#EEF1F2] px-5 py-3 text-sm text-body">
            Aturan nonaktif, tidak dievaluasi: <span class="font-semibold" x-text="data.disabled_rules.join(', ')"></span>.
            Status di halaman ini tidak mencerminkan aturan tersebut.
        </div>
    </template>

    {{-- Alert host ini --}}
    <template x-for="a in data?.alerts ?? []" :key="a.id">
        <section aria-label="Alert host ini" class="flex flex-wrap items-center gap-x-6 gap-y-2.5 rounded-md border px-5 py-3.5"
                 :class="a.state === 'FIRING' ? 'border-firing-border bg-firing-tint' : 'border-hairline bg-panel'">
            <span class="inline-flex w-[104px] shrink-0 items-center gap-2 font-bold" :class="S[a.state].fg">
                <span :class="S[a.state].lampCls + ' ' + S[a.state].lamp"></span><span x-text="a.state"></span>
            </span>
            <span class="flex min-w-0 flex-[1_1_220px] flex-col">
                <span class="text-[17px] font-semibold" x-text="a.rule + (a.tag ? ` (${a.tag})` : '')"></span>
                <span class="text-sm text-muted" x-text="a.recover !== null ? `Ambang pulih ${a.recover}` : ''"></span>
            </span>
            <span class="font-mono text-[26px] font-semibold" :class="S[a.state].fg" x-text="a.value"></span>
            <span class="flex min-w-0 flex-[1_1_280px] flex-col gap-0.5 text-sm text-body">
                <span>Melewati ambang <span class="font-mono" x-text="fmtIsoTime(a.breach_at)"></span>
                    <template x-if="a.state === 'FIRING'"><span> · FIRING <span class="font-mono" x-text="fmtIsoTime(a.since)"></span></span></template>
                    <template x-if="a.state === 'PENDING' && a.pending"><span> · menunggu <span class="font-mono" x-text="`${Math.floor(held(a))}/${a.pending.for} dtk`"></span></span></template>
                </span>
                <template x-if="cooldown(a)">
                    <span>Pesan ulang dalam <span class="font-mono font-semibold" x-text="cooldown(a)"></span> bila masih FIRING (cooldown 10 mnt)</span>
                </template>
            </span>

            <template x-if="steps(a).length">
                <div class="mt-1 flex basis-full flex-col gap-2.5 border-t border-firing-border pt-3">
                    <span class="text-sm font-semibold text-body">Tahapan notifikasi</span>
                    <ol class="m-0 flex list-none flex-wrap gap-x-2 gap-y-3.5 p-0">
                        <template x-for="(s, i) in steps(a)" :key="s.key">
                            <li class="flex min-w-0 flex-[1_1_170px] flex-col gap-1.5">
                                <div class="flex items-center">
                                    <span class="inline-flex size-5 shrink-0 items-center justify-center rounded-full"
                                          :class="s.failed ? 'bg-firing' : (s.time ? 'bg-trace' : 'border-2 border-control bg-panel')">
                                        <template x-if="s.time && !s.failed">
                                            <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 6.3 L5 8.8 L9.5 3.6" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </template>
                                    </span>
                                    <template x-if="i < 3"><span class="mx-2 h-0.5 flex-1" :class="steps(a)[i + 1].time ? 'bg-trace' : 'bg-firing-border'"></span></template>
                                </div>
                                <span class="font-semibold" x-text="s.label"></span>
                                <span class="font-mono text-sm" x-text="s.time ? fmtTimeMs(s.time) : 'menunggu'"></span>
                                <span class="text-[13px] text-body"><span class="font-mono" x-text="s.key"></span><span x-text="s.delta ? ' · ' + s.delta : ''"></span></span>
                            </li>
                        </template>
                    </ol>
                    <template x-if="a.notification.message_id">
                        <span class="text-sm text-body">Telegram <span class="font-mono">message_id</span> <span class="font-mono" x-text="a.notification.message_id"></span></span>
                    </template>
                </div>
            </template>
        </section>
    </template>

        {{-- Metrik --}}
    <section aria-label="Grafik metrik" class="flex flex-col gap-4 rounded-md border border-hairline bg-panel px-6 py-5">
        <div class="flex flex-wrap items-center justify-between gap-x-5 gap-y-2.5">
            <h2 class="text-xl font-semibold">Metrik</h2>
            <div role="group" aria-label="Rentang waktu" class="inline-flex overflow-hidden rounded-md border border-control">
                <template x-for="([key, label], i) in ranges" :key="key">
                    <button type="button" @click="setRange(key)" :aria-pressed="range === key ? 'true' : 'false'"
                            class="h-11 cursor-pointer px-4.5 font-semibold"
                            :class="[range === key ? 'bg-graphite text-white' : 'bg-panel text-graphite hover:bg-[#DDE2E3]', i ? 'border-l border-control' : '']"
                            x-text="label"></button>
                </template>
            </div>
        </div>
        <span class="-mt-2 text-sm text-muted">
            Arahkan kursor, atau klik grafik lalu tekan ← →, untuk membaca nilai tiap titik. Kedua grafik berbagi waktu yang sama.
            Semua waktu dalam WIB (UTC+7).
        </span>

        <div class="flex flex-wrap gap-6">
            @foreach ([['cpu', 'CPU', 'lastCpu'], ['mem', 'Memori terpakai', 'lastMem']] as [$key, $title, $last])
                <div class="flex min-w-0 flex-[1_1_480px] flex-col gap-2">
                    <div class="flex items-baseline justify-between">
                        <span class="text-base font-semibold">{{ $title }}</span>
                        <span class="font-mono text-2xl font-semibold"
                              :class="{{ $last }} > (data?.thresholds.{{ $key }}.fire ?? 100) ? 'text-firing' : ''"
                              x-text="{{ $last }} === null ? '–' : fmtNum({{ $last }}) + '%'"></span>
                    </div>

                    {{-- Legenda di luar area grafik: tidak pernah tertutup data --}}
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-body">
                        <span class="inline-flex items-center gap-1.5">
                            <svg width="22" height="6" aria-hidden="true"><line x1="0" y1="3" x2="22" y2="3" stroke="#B02A14" stroke-width="2" stroke-dasharray="6 4"/></svg>
                            FIRING &gt; <span class="font-mono" x-text="(data?.thresholds.{{ $key }}.fire ?? '–') + '%'"></span>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <svg width="22" height="6" aria-hidden="true"><line x1="0" y1="3" x2="22" y2="3" stroke="#1E6B43" stroke-width="2" stroke-dasharray="2 4"/></svg>
                            pulih &lt; <span class="font-mono" x-text="(data?.thresholds.{{ $key }}.recover ?? '–') + '%'"></span>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <svg width="10" height="10" aria-hidden="true"><circle cx="5" cy="5" r="4" fill="#B02A14"/></svg>
                            titik melewati ambang
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <svg width="16" height="12" aria-hidden="true"><rect width="16" height="12" fill="rgba(229,72,45,0.15)" stroke="rgba(176,42,20,0.45)"/><path d="M0 12 L12 0 M6 12 L16 2" stroke="rgba(176,42,20,0.45)"/></svg>
                            DOWN = tidak ada data masuk (host, Telegraf, atau Laravel mati)
                        </span>
                        <template x-if="bucketNote">
                            <span class="text-muted">nilai = maksimum per <span x-text="bucketNote"></span></span>
                        </template>
                    </div>

                    <div class="relative h-64">
                        <canvas x-ref="{{ $key }}" role="img" aria-label="Grafik {{ strtolower($title) }}, persen terhadap waktu WIB"></canvas>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Disk + Layanan --}}
    <section aria-label="Disk dan layanan" class="flex flex-wrap items-stretch gap-5">
        <div class="flex min-w-0 flex-[1_1_460px] flex-col gap-1.5 rounded-md border border-hairline bg-panel px-6 py-5">
            <h2 class="mb-1.5 text-xl font-semibold">Ruang disk bebas</h2>
            <template x-for="d in data?.disks ?? []" :key="d.volume">
                <div class="flex items-center gap-3.5 border-t border-hairline py-3">
                    <span class="w-9 shrink-0 text-xl font-bold" x-text="d.volume"></span>
                    <svg viewBox="0 0 400 22" class="block h-auto min-w-0 flex-1" role="img" :aria-label="`Volume ${d.volume} bebas ${fmtNum(d.free)} persen`">
                        <rect x="0" y="5" width="400" height="12" rx="6" fill="#E1E6E7"/>
                        <rect x="0" y="5" :width="diskX(d.free)" height="12" rx="6" :fill="d.free < (data.thresholds.disk.fire ?? 10) ? '#B02A14' : '#0F5C73'"/>
                        <line :x1="diskX(data.thresholds.disk.fire)" :x2="diskX(data.thresholds.disk.fire)" y1="0" y2="22" stroke="#B02A14" stroke-width="2.5"/>
                        <line :x1="diskX(data.thresholds.disk.recover)" :x2="diskX(data.thresholds.disk.recover)" y1="0" y2="22" stroke="#1E6B43" stroke-width="2.5"/>
                    </svg>
                    <span class="w-[72px] shrink-0 text-right font-mono text-[22px] font-semibold" x-text="fmtNum(d.free) + '%'"></span>
                    <span class="inline-flex w-[92px] shrink-0 items-center gap-2 font-bold" :class="S[d.state].fg">
                        <span :class="S[d.state].lampCls + ' ' + S[d.state].lamp"></span><span x-text="d.state"></span>
                    </span>
                </div>
            </template>
            <span class="border-t border-hairline pt-1.5 text-sm text-muted">
                Setiap volume dinilai sendiri. Penanda merah = ambang FIRING
                <span x-text="data?.thresholds.disk.fire ?? 10"></span>%, hijau = pulih
                <span x-text="data?.thresholds.disk.recover ?? 12"></span>%.
            </span>
        </div>

        <div class="flex min-w-0 flex-[1_1_460px] flex-col gap-2 rounded-md border border-hairline bg-panel px-6 py-5">
            <h2 class="mb-1.5 text-xl font-semibold">Layanan Windows dipantau</h2>
            <div class="flex flex-wrap gap-x-4 gap-y-1.5 py-1.5 text-sm text-muted">
                <span class="flex-[2_1_200px]">Layanan</span><span class="flex-[1_1_120px]">Status</span><span class="flex-[1_1_200px]">Status terakhir berubah</span>
            </div>
            <template x-for="s in data?.services ?? []" :key="s.name">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-hairline py-3">
                    <span class="flex-[2_1_200px] font-semibold" x-text="s.name"></span>
                    <span class="inline-flex flex-[1_1_120px] items-center gap-2 font-bold" :class="s.running ? 'text-normal' : 'text-firing'">
                        <span class="lamp" :class="s.running ? 'bg-normal-lamp' : 'bg-firing-lamp'"></span><span x-text="s.status"></span>
                    </span>
                    <span class="flex-[1_1_200px] font-mono text-sm text-body" x-text="fmtIsoTime(s.changed_at)"></span>
                </div>
            </template>
            <span class="text-sm text-muted">Aturan: tidak running selama 20 dtk menjadi FIRING, pulih begitu running kembali.</span>
        </div>
    </section>

    {{-- Riwayat --}}
    <section aria-label="Riwayat event" class="flex flex-col gap-1.5 rounded-md border border-hairline bg-panel px-6 py-5">
        <h2 class="mb-1.5 text-xl font-semibold">Riwayat event {{ $source->name }}</h2>
        <template x-for="(e, i) in data?.events ?? []" :key="i">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-hairline py-2.5">
                <span class="w-[92px] shrink-0 font-mono text-sm text-muted" x-text="fmtIsoTime(e.t_detected)"></span>
                <span class="flex-[1_1_260px] font-semibold">
                    <span x-text="e.rule + (e.tag ? ` (${e.tag})` : '')"></span> ·
                    <span x-text="e.from"></span> →
                    <span :class="e.to === 'FIRING' ? 'text-firing' : 'text-resolved'" x-text="e.to"></span>
                </span>
                <span class="flex-[1_1_260px] text-sm text-body" x-text="`Nilai ${e.value === null ? '–' : fmtNum(e.value)} · data ${fmtIsoTime(e.t_breach)}`"></span>
            </div>
        </template>
        <template x-if="data && data.events.length === 0">
            <span class="border-t border-hairline pt-2.5 text-muted">Belum ada event FIRING atau RESOLVED.</span>
        </template>
        <span class="pt-1.5 text-sm text-muted">Menampilkan 20 event FIRING/RESOLVED terakhir. Transisi PENDING disembunyikan.</span>
    </section>
</div>
@endsection