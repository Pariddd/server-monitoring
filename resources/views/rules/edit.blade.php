@extends('layouts.app')

@section('title', 'Aturan · Monitoring Server')
@section('no_polling', '1')


@section('content')
@php
    $groups = [
        'Resource host' => ['cpu_high', 'mem_high', 'disk_low', 'service_down'],
        'Ruang server (ESP32)' => ['temp_high', 'humidity_high'],
        'Heartbeat' => ['heartbeat_host', 'heartbeat_esp32'],
    ];
    $num = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $opText = ['>' => '>', '<' => '<', '!=' => '≠', '==' => '='];
    $input = 'h-11 rounded-md border bg-white px-3 font-mono text-graphite';
@endphp

<div class="flex flex-wrap items-start gap-6">
    <div class="flex min-w-0 flex-[1_1_720px] flex-col gap-5">
        <div class="flex flex-col gap-1">
            <h1 class="text-[28px] font-bold leading-tight">Aturan alert</h1>
            <p class="text-body">Perubahan berlaku pada evaluasi berikutnya, paling lambat 10 dtk. Nilai default mengikuti parameter skripsi.</p>
        </div>

        @if (session('status'))
            <div role="status" class="rounded-md border border-[#BBD7C6] bg-normal-tint px-5 py-3 font-semibold text-normal">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-md border border-firing-border bg-firing-tint px-5 py-3 text-firing">
                <span class="font-semibold">Perubahan belum disimpan.</span> Periksa {{ $errors->count() }} isian bertanda merah di bawah.
            </div>
        @endif

        <form method="POST" action="{{ route('rules.update') }}" class="flex flex-col gap-5">
            @csrf
            @method('PUT')

            @foreach ($groups as $title => $keys)
                <section class="rounded-md border border-hairline bg-panel">
                    <h2 class="px-5 py-3.5 text-xl font-semibold">{{ $title }}</h2>

                    @foreach ($keys as $key)
                        @continue(! isset($rules[$key]))
                        @php
                            $rule = $rules[$key];
                            $def = $defaults[$key];
                            $id = $rule->id;
                            $isHeartbeat = $rule->kind === 'heartbeat';
                            $editThreshold = ! $isHeartbeat && ($def['editable_threshold'] ?? true);
                            $diffs = [];
                            if ($editThreshold && (float) $rule->threshold !== (float) $def['threshold']) $diffs[] = 'ambang '.$num($rule->threshold).' (default '.$num($def['threshold']).')';
                            if ($editThreshold && (float) $rule->recover_threshold !== (float) $def['recover_threshold']) $diffs[] = 'pulih '.$num($rule->recover_threshold).' (default '.$num($def['recover_threshold']).')';
                            if ($rule->for_seconds !== $def['for_seconds']) $diffs[] = ($isHeartbeat ? 'batas ' : 'durasi ').$rule->for_seconds.' dtk (default '.$def['for_seconds'].')';
                            if ($rule->cooldown_seconds !== $defaultCooldown) $diffs[] = 'cooldown '.$rule->cooldown_seconds.' dtk (default '.$defaultCooldown.')';
                            if (! $rule->is_enabled) $diffs[] = 'nonaktif';
                            $checked = old("rules.$id.is_enabled", $rule->is_enabled ? '1' : '0') === '1';
                        @endphp

                        <div class="flex flex-col gap-3 border-t border-hairline px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex flex-col">
                                    <span class="text-[17px] font-semibold">{{ $rule->name }}</span>
                                    <span class="text-[13px] text-muted">Diubah {{ $rule->updated_at?->setTimezone('Asia/Jakarta')->locale('id')->translatedFormat('d M Y H:i') }} WIB</span>
                                </div>
                                  <label x-data="{ on: @js($checked) }" class="inline-flex min-h-11 cursor-pointer items-center gap-2.5">
                                    <input type="hidden" name="rules[{{ $id }}][is_enabled]" value="0">
                                    <input type="checkbox" name="rules[{{ $id }}][is_enabled]" value="1" x-model="on"
                                           class="size-5 cursor-pointer accent-[#34A06A]" @checked($checked)>
                                    <span class="inline-flex min-w-[88px] items-center gap-2 font-bold"
                                          :class="on ? 'text-normal' : 'text-muted'">
                                        <span class="lamp" :class="on ? 'bg-normal-lamp' : 'bg-[#9AA7AD]'"></span>
                                        <span x-text="on ? 'Aktif' : 'Nonaktif'">{{ $checked ? 'Aktif' : 'Nonaktif' }}</span>
                                    </span>
                                </label>
                            </div>

                            <div class="flex flex-wrap gap-x-6 gap-y-3">
                                @if ($editThreshold)
                                    @foreach (['threshold' => ['Ambang pemicu', $def['operator']], 'recover_threshold' => ['Ambang pulih', $def['recover_operator']]] as $field => [$label, $op])
                                        <label class="flex flex-col gap-1">
                                            <span class="text-sm text-muted">{{ $label }}</span>
                                            <span class="flex items-center gap-2">
                                                <span class="w-4 font-mono font-semibold">{{ $opText[$op] }}</span>
                                                <input type="number" step="any" name="rules[{{ $id }}][{{ $field }}]"
                                                       value="{{ old("rules.$id.$field", $num($rule->$field)) }}"
                                                       class="{{ $input }} w-24 {{ $errors->has("rules.$id.$field") ? 'border-firing' : 'border-control' }}"
                                                       @if ($errors->has("rules.$id.$field")) aria-invalid="true" @endif>
                                                <span class="text-body">{{ $def['unit'] }}</span>
                                            </span>
                                            @error("rules.$id.$field")<span class="max-w-64 text-sm font-semibold text-firing">{{ $message }}</span>@enderror
                                        </label>
                                    @endforeach
                                @elseif (! $isHeartbeat)
                                    <div class="flex flex-col gap-1">
                                        <span class="text-sm text-muted">Kondisi</span>
                                        <span class="flex h-11 items-center font-semibold">Status ≠ Running · pulih saat Running</span>
                                    </div>
                                @endif

                                <label class="flex flex-col gap-1">
                                    <span class="text-sm text-muted">{{ $isHeartbeat ? 'Batas tanpa data' : 'Durasi tahan' }}</span>
                                    <span class="flex items-center gap-2">
                                        <input type="number" min="0" step="1" name="rules[{{ $id }}][for_seconds]"
                                               value="{{ old("rules.$id.for_seconds", $rule->for_seconds) }}"
                                               class="{{ $input }} w-24 {{ $errors->has("rules.$id.for_seconds") ? 'border-firing' : 'border-control' }}">
                                        <span class="text-body">dtk</span>
                                    </span>
                                    @error("rules.$id.for_seconds")<span class="max-w-64 text-sm font-semibold text-firing">{{ $message }}</span>@enderror
                                </label>

                                <label class="flex flex-col gap-1">
                                    <span class="text-sm text-muted">Cooldown pesan ulang</span>
                                    <span class="flex items-center gap-2">
                                        <input type="number" min="60" step="1" name="rules[{{ $id }}][cooldown_seconds]"
                                               value="{{ old("rules.$id.cooldown_seconds", $rule->cooldown_seconds) }}"
                                               class="{{ $input }} w-28 {{ $errors->has("rules.$id.cooldown_seconds") ? 'border-firing' : 'border-control' }}">
                                        <span class="text-body">dtk</span>
                                    </span>
                                    @error("rules.$id.cooldown_seconds")<span class="max-w-64 text-sm font-semibold text-firing">{{ $message }}</span>@enderror
                                </label>
                            </div>

                            @if ($diffs)
                                <div class="flex items-start gap-2 rounded-md border border-[#E3C77D] bg-pending-tint px-3 py-2 text-sm text-pending">
                                    <span class="lamp mt-1 bg-pending-lamp"></span>
                                    <span><span class="font-semibold">Berbeda dari parameter skripsi:</span> {{ implode(' · ', $diffs) }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endforeach

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="h-11 cursor-pointer rounded-md bg-trace px-6 font-semibold text-white hover:bg-[#0C4A5D]">Simpan perubahan</button>
                <span class="text-sm text-muted">Menonaktifkan aturan mengembalikan statusnya ke NORMAL tanpa pesan Telegram.</span>
            </div>
        </form>

        <form method="POST" action="{{ route('rules.reset') }}"
              onsubmit="return confirm('Kembalikan semua aturan ke parameter skripsi? Semua aturan juga akan diaktifkan.')">
            @csrf
            <button type="submit" class="h-11 cursor-pointer rounded-md border border-control bg-panel px-5 font-semibold text-graphite hover:bg-[#DDE2E3]">
                Kembalikan ke default skripsi
            </button>
        </form>
    </div>

    <aside class="flex min-w-[280px] flex-[0_1_340px] flex-col gap-4 rounded-md border border-hairline bg-panel px-5 py-4">
        <h2 class="text-xl font-semibold">Cara aturan bekerja</h2>
        <ol class="m-0 flex list-none flex-col gap-2.5 p-0 text-body">
            <li><span class="font-semibold text-normal">NORMAL</span> → nilai melewati ambang pemicu → <span class="font-semibold text-pending">PENDING</span>.</li>
            <li><span class="font-semibold text-pending">PENDING</span> → tetap melewati ambang selama durasi tahan → <span class="font-semibold text-firing">FIRING</span> (pesan Telegram). Kembali di bawah ambang → NORMAL tanpa pesan.</li>
            <li><span class="font-semibold text-firing">FIRING</span> → melewati ambang pulih → <span class="font-semibold text-resolved">RESOLVED</span> (pesan Telegram).</li>
            <li>Selama masih FIRING, pesan diulang setiap cooldown.</li>
        </ol>
        <div class="flex flex-col gap-1.5 border-t border-hairline pt-3 text-sm text-body">
            <span class="font-semibold text-graphite">Histeresis</span>
            <span>Contoh CPU: FIRING di atas 85%, baru pulih di bawah 80%. Nilai di antara 80–85% tidak mengubah status, sehingga alert tidak bolak-balik saat nilai berayun di sekitar ambang.</span>
            <svg viewBox="0 0 300 40" class="mt-1 h-auto w-full" role="img" aria-label="Zona histeresis: pulih di bawah 80, ambang 85">
                <rect x="0" y="10" width="160" height="14" fill="#E1EEE6"/><rect x="160" y="10" width="40" height="14" fill="#FBEFD0"/><rect x="200" y="10" width="100" height="14" fill="#F7E4DF"/>
                <line x1="160" x2="160" y1="8" y2="28" stroke="#1E6B43" stroke-width="2"/><line x1="200" x2="200" y1="8" y2="28" stroke="#B02A14" stroke-width="2"/>
                <text x="156" y="38" text-anchor="end" font-size="11" fill="#1E6B43" font-weight="600">pulih 80</text>
                <text x="204" y="38" font-size="11" fill="#B02A14" font-weight="600">ambang 85</text>
            </svg>
        </div>
        <div class="border-t border-hairline pt-3 text-sm text-body">
            Aturan memori dan disk sebaiknya <span class="font-semibold">diaktifkan lagi sebelum pengujian resmi</span>, supaya hasil uji mencakup semua aturan.
        </div>
    </aside>
</div>
@endsection