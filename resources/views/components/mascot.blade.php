@props(['mood' => 'scan', 'label' => ''])
<svg viewBox="0 0 200 124" width="200" height="124" role="img" aria-label="{{ $label }}">
    <rect x="6" y="108" width="188" height="4" rx="2" fill="#D3D8DA"/>
    <line x1="100" y1="30" x2="100" y2="16" stroke="#556166" stroke-width="3"/>
    @if ($mood === 'scan')
        <circle class="led" cx="100" cy="12" r="5" fill="#34A06A"/>
    @elseif ($mood === 'fast')
        <circle class="led-fast" cx="100" cy="12" r="5" fill="#E0A526"/>
    @else
        <circle cx="100" cy="12" r="5" fill="#7F9BAB"/>
    @endif
    <rect x="14" y="30" width="172" height="74" rx="6" fill="#2B3438" stroke="#1E2528" stroke-width="2"/>
    <rect x="5" y="36" width="10" height="62" rx="2" fill="#3A464C"/><rect x="185" y="36" width="10" height="62" rx="2" fill="#3A464C"/>
    <circle cx="10" cy="46" r="2.5" fill="#1E2528"/><circle cx="10" cy="88" r="2.5" fill="#1E2528"/>
    <circle cx="190" cy="46" r="2.5" fill="#1E2528"/><circle cx="190" cy="88" r="2.5" fill="#1E2528"/>
    <rect x="44" y="56" width="42" height="28" rx="9" fill="#151A1C"/><rect x="114" y="56" width="42" height="28" rx="9" fill="#151A1C"/>
    @if ($mood === 'down')
        <line x1="52" y1="48" x2="84" y2="44" stroke="#556166" stroke-width="2.5" stroke-linecap="round"/>
        <line x1="116" y1="44" x2="148" y2="48" stroke="#556166" stroke-width="2.5" stroke-linecap="round"/>
        <rect x="49" y="72" width="12" height="9" rx="4" fill="#CFE9DB"/><rect x="119" y="72" width="12" height="9" rx="4" fill="#CFE9DB"/>
        <path d="M88 94 Q100 90 112 94" fill="none" stroke="#8D9AA0" stroke-width="2.5" stroke-linecap="round"/>
    @else
        <line x1="52" y1="46" x2="84" y2="46" stroke="#556166" stroke-width="2.5" stroke-linecap="round"/>
        <line x1="116" y1="46" x2="148" y2="46" stroke="#556166" stroke-width="2.5" stroke-linecap="round"/>
        <g class="eye"><rect x="46" y="58" width="38" height="24" rx="8" fill="#34A06A" opacity="0.85"/><rect class="{{ $mood === 'fast' ? 'pupil-fast' : 'pupil' }}" x="59" y="64" width="12" height="12" rx="5" fill="#D9F5E5"/></g>
        <g class="eye"><rect x="116" y="58" width="38" height="24" rx="8" fill="#34A06A" opacity="0.85"/><rect class="{{ $mood === 'fast' ? 'pupil-fast' : 'pupil' }}" x="129" y="64" width="12" height="12" rx="5" fill="#D9F5E5"/></g>
        @if ($mood === 'fast')
            <path d="M92 93 H108" fill="none" stroke="#8D9AA0" stroke-width="2.5" stroke-linecap="round"/>
        @else
            <path d="M88 93 Q100 98 112 93" fill="none" stroke="#8D9AA0" stroke-width="2.5" stroke-linecap="round"/>
        @endif
    @endif
</svg>