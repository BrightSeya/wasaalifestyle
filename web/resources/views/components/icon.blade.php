@props(['name', 'class' => 'h-5 w-5'])

<svg {{ $attributes->class($class) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('dashboard') <rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="4" rx="1.5"/><rect x="13.5" y="10.5" width="7" height="10" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/> @break
        @case('calendar') <rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"/> @break
        @case('activities') <path d="M4 19V5m0 14h16"/><path d="m7 15 3-4 3 2 5-6"/><path d="M15 7h3v3"/> @break
        @case('sparkles') <path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3Z"/><path d="m19 16 .8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8L19 16Z"/> @break
        @case('bell') <path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/> @break
        @case('settings') <path d="M4 6h7m4 0h5M4 12h3m4 0h9M4 18h9m4 0h3"/><circle cx="13" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="15" cy="18" r="2"/> @break
        @case('users') <path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM17 3.2a4 4 0 0 1 0 7.6M21 20v-1.5a4 4 0 0 0-3-3.9"/> @break
        @case('clipboard') <rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4.5h6a1.5 1.5 0 0 0-3-1.5 1.5 1.5 0 0 0-3 1.5ZM9 11h6M9 15h6"/> @break
        @case('chart') <path d="M4 19V5m0 14h16"/><path d="M8 15v-3m4 3V8m4 7v-5m4 5V6"/> @break
        @case('logout') <path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/> @break
        @case('chevron') <path d="m7 10 5 5 5-5"/> @break
        @case('user') <circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/> @break
        @case('menu') <path d="M4 7h16M4 12h16M4 17h16"/> @break
        @case('sunrise') <path d="M3 18h18M5 15a7 7 0 0 1 14 0M12 3v3m-8 .5 2 2M20 6.5l-2 2M8 18v2m8-2v2"/> @break
        @case('camera') <path d="M4 7h3l1.5-2h7L17 7h3v12H4z"/><circle cx="12" cy="13" r="3.5"/> @break
        @case('close') <path d="m6 6 12 12M18 6 6 18"/> @break
        @case('arrow-right') <path d="M5 12h14M13 6l6 6-6 6"/> @break
        @case('arrow-down') <path d="M12 5v14m-6-6 6 6 6-6"/> @break
        @case('plus') <path d="M12 5v14M5 12h14"/> @break
        @case('refresh') <path d="M20 7v5h-5M4 17v-5h5"/><path d="M5.5 9A7 7 0 0 1 18 6.5L20 12M4 12l2 5.5A7 7 0 0 0 18.5 15"/> @break
        @case('moon') <path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/> @break
        @case('sun') <circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/> @break
    @endswitch
</svg>
