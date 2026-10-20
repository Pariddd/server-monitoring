<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Monitoring Server')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ground font-sans text-[15px] text-graphite antialiased">

{{-- Halaman yang tidak melakukan polling (mis. Aturan) cukup menulis @section('no_polling', '1') --}}
@php($polling = ! View::hasSection('no_polling'))

<header class="bg-graphite text-panel">
    <div class="mx-auto flex min-h-14 max-w-[1320px] flex-wrap items-center gap-x-7 px-4 sm:px-8">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 text-panel no-underline">
            <svg width="38" height="20" viewBox="0 0 38 20" aria-hidden="true"><rect x="1" y="1" width="36" height="18" rx="3" fill="#2B3438" stroke="#8D9AA0" stroke-width="1.5"/><rect x="8" y="6" width="8" height="8" rx="3" fill="#34A06A"/><rect x="22" y="6" width="8" height="8" rx="3" fill="#34A06A"/></svg>
            <span class="text-[22px] font-bold">Monitoring Server</span>
        </a>

        <nav aria-label="Navigasi utama" class="flex gap-1">
            @php($nav = [['dashboard', 'Ringkasan'], ['rules.edit', 'Aturan']])
            @foreach ($nav as [$name, $label])
                @php($active = request()->routeIs($name) || ($name === 'dashboard' && request()->routeIs('servers.*')))
                <a href="{{ Route::has($name) ? route($name) : '#' }}"
                   @if ($active) aria-current="page" @endif
                   @class([
                       'flex h-14 items-center px-3.5 no-underline',
                       'font-semibold text-white shadow-[inset_0_-3px_0_#F8F9F9]' => $active,
                       'font-medium text-[#B9C4C8] hover:text-white' => ! $active,
                   ])>{{ $label }}</a>
            @endforeach
        </nav>

        <div x-data class="ml-auto flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm text-[#B9C4C8]">
            @if ($polling)
                <span>Data terakhir
                    <span class="font-mono font-medium text-white" x-text="$fmt.clock($store.poll.lastOkAt)">–</span> WIB
                    <template x-if="$store.poll.status === 'error'">
                        <span class="font-semibold text-pending-lamp"> · polling gagal, data tertahan</span>
                    </template>
                </span>
                <label class="flex items-center gap-1.5">
                    <span>polling</span>
                    <select :value="$store.poll.intervalMs" @change="$store.poll.setInterval($event.target.value)"
                            class="min-h-11 cursor-pointer rounded-md border border-[#556166] bg-graphite px-2 font-semibold text-white">
                        <template x-for="[ms, label] in $store.poll.options" :key="ms">
                            <option :value="ms" x-text="label" :selected="ms === $store.poll.intervalMs"></option>
                        </template>
                    </select>
                </label>
                <button type="button" @click="$dispatch('poll-refresh')"
                        class="min-h-11 cursor-pointer rounded-md border border-[#556166] px-3 font-semibold text-white hover:bg-[#2B3438]">
                    Perbarui
                </button>
            @endif
            <span x-data="utcClock" class="font-mono" x-text="now + ' WIB'" aria-label="Jam WIB"></span>
        </div>
    </div>
</header>

@if ($polling)
    {{-- Garis polling: terisi selama satu interval polling; berhenti dan kuning saat gagal --}}
    <div class="h-[3px] overflow-hidden bg-[#2B3438]" aria-hidden="true">
        <div x-data
             x-effect="const c = $store.poll.cycle, s = $store.poll.status;
                       $el.classList.remove('poll-run');
                       if (s === 'ok') { void $el.offsetWidth; $el.classList.add('poll-run'); }"
             :class="$store.poll.status === 'error' ? 'bg-pending-lamp' : 'bg-[#7FB8C9]'"
             class="h-full origin-left" :style="`animation-duration:${$store.poll.intervalMs}ms`"></div>
    </div>
@else
    <div class="h-[3px] bg-[#2B3438]" aria-hidden="true"></div>
@endif

<main class="mx-auto max-w-[1320px] px-4 pb-14 pt-6 sm:px-8">
    @yield('content')
</main>

</body>
</html>