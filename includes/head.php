<?php
// includes/head.php
// Global head tags, fonts, Tailwind config, and theme initialization
$pageTitle = $pageTitle ?? 'Asterisko - Inventory Management System';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#0C2340">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Asterisko POS">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="icon" type="image/png" href="../assets/logo.png">
<link rel="apple-touch-icon" href="../assets/logo.png">
<link rel="manifest" href="/manifest.json">

<!-- Fonts: Plus Jakarta Sans -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<!-- Tailwind CSS CDN -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    asterisko: {
                        navy: '#0C2340',
                        'navy-dark': '#071526',
                        'navy-light': '#132E52',
                        'navy-border': '#15345C',
                        orange: '#F28C28',
                        'orange-hover': '#E07B18',
                        'orange-light': '#FFF0DB',
                        canvas: '#F4F7FB'
                    }
                },
                fontFamily: {
                    sans: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif']
                },
                transitionTimingFunction: {
                    snappy: 'cubic-bezier(0.16, 1, 0.3, 1)'
                },
                keyframes: {
                    'ast-fade-up':     { from: { opacity: '0', transform: 'translateY(14px)' }, to: { opacity: '1', transform: 'none' } },
                    'ast-fade-in':     { from: { opacity: '0' }, to: { opacity: '1' } },
                    'ast-scale-in':    { from: { opacity: '0', transform: 'translateY(10px) scale(0.97)' }, to: { opacity: '1', transform: 'none' } },
                    'ast-slide-right': { from: { opacity: '0', transform: 'translateX(-12px)' }, to: { opacity: '1', transform: 'none' } },
                    'ast-bar-grow':    { from: { transform: 'scaleX(0)' }, to: { transform: 'scaleX(1)' } },
                    'ast-pulse-ring': {
                        '0%':   { boxShadow: '0 0 0 0 rgba(16, 185, 129, 0.55)' },
                        '70%':  { boxShadow: '0 0 0 7px rgba(16, 185, 129, 0)' },
                        '100%': { boxShadow: '0 0 0 0 rgba(16, 185, 129, 0)' }
                    },
                    'ast-pulse-ring-orange': {
                        '0%':   { boxShadow: '0 0 0 0 rgba(242, 140, 40, 0.55)' },
                        '70%':  { boxShadow: '0 0 0 6px rgba(242, 140, 40, 0)' },
                        '100%': { boxShadow: '0 0 0 0 rgba(242, 140, 40, 0)' }
                    },
                    'ast-toast-in':    { from: { opacity: '0', transform: 'translateY(16px) scale(0.96)' }, to: { opacity: '1', transform: 'none' } },
                    'ast-logo-pop': {
                        '0%':   { opacity: '0', transform: 'scale(0.4) rotate(-180deg)' },
                        '60%':  { opacity: '1', transform: 'scale(1.12) rotate(12deg)' },
                        '80%':  { transform: 'scale(0.96) rotate(-4deg)' },
                        '100%': { opacity: '1', transform: 'scale(1) rotate(0deg)' }
                    },
                    'ast-logo-spin':   { from: { transform: 'rotate(0deg)' }, to: { transform: 'rotate(360deg)' } },
                    'ast-logo-twinkle': {
                        '0%, 86%, 100%': { transform: 'rotate(0deg) scale(1)' },
                        '90%':           { transform: 'rotate(60deg) scale(1.12)' },
                        '95%':           { transform: 'rotate(120deg) scale(1)' }
                    },
                    'ast-logo-glow': {
                        '0%, 100%': { boxShadow: '0 0 0 0 rgba(242, 140, 40, 0.45), 0 10px 30px rgba(0, 0, 0, 0.35)' },
                        '50%':      { boxShadow: '0 0 0 10px rgba(242, 140, 40, 0), 0 10px 30px rgba(0, 0, 0, 0.35)' }
                    },
                    'ast-text-reveal': { from: { opacity: '0', transform: 'translateX(-8px)', letterSpacing: '0.25em' }, to: { opacity: '1', transform: 'none' } }
                },
                animation: {
                    'ast-fade-up':           'ast-fade-up 520ms cubic-bezier(0.16, 1, 0.3, 1) backwards',
                    'ast-fade-in':           'ast-fade-in 280ms ease backwards',
                    'ast-scale-in':          'ast-scale-in 340ms cubic-bezier(0.16, 1, 0.3, 1) backwards',
                    'ast-slide-right':       'ast-slide-right 280ms cubic-bezier(0.16, 1, 0.3, 1) backwards',
                    'ast-bar-grow':          'ast-bar-grow 900ms cubic-bezier(0.16, 1, 0.3, 1) 350ms backwards',
                    'ast-pulse-ring':        'ast-pulse-ring 2.2s ease-out infinite',
                    'ast-pulse-ring-orange': 'ast-pulse-ring-orange 2s ease-out infinite',
                    'ast-toast-in':          'ast-toast-in 380ms cubic-bezier(0.16, 1, 0.3, 1) backwards',
                    'ast-logo-pop':          'ast-logo-pop 800ms cubic-bezier(0.16, 1, 0.3, 1) backwards',
                    'ast-logo-pop-glow':     'ast-logo-pop 900ms cubic-bezier(0.16, 1, 0.3, 1) 100ms backwards, ast-logo-glow 3s ease-in-out 1.2s infinite',
                    'ast-logo-spin':         'ast-logo-spin 700ms cubic-bezier(0.16, 1, 0.3, 1) 1',
                    'ast-logo-twinkle':      'ast-logo-twinkle 7s ease-in-out 1.5s infinite',
                    'ast-logo-twinkle-fast': 'ast-logo-twinkle 6s ease-in-out 1.5s infinite',
                    'ast-text-reveal':       'ast-text-reveal 600ms cubic-bezier(0.16, 1, 0.3, 1) backwards',
                    'fade-in':               'ast-scale-in 280ms cubic-bezier(0.16, 1, 0.3, 1) backwards'
                }
            }
        }
    };

    // Immediate Dark Mode initialization
    try {
        const savedTheme = localStorage.getItem('theme');
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (savedTheme === 'dark' || (!savedTheme && systemPrefersDark)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    } catch (e) {}
</script>

<!-- Asterisko Enhanced CSS -->
<link rel="stylesheet" href="../assets/app-enhancements.css?v=<?= @filemtime(__DIR__ . '/../assets/app-enhancements.css') ?: '4' ?>">

<!-- Asterisko animations & UI polish (Tailwind @apply - processed by the Tailwind CDN above) -->
<style type="text/tailwindcss">
        /* ===== Page entrance: sections fade up one after another ===== */
        main > * { @apply animate-ast-fade-up; }
        main > *:nth-child(2) { @apply [animation-delay:60ms]; }
        main > *:nth-child(3) { @apply [animation-delay:120ms]; }
        main > *:nth-child(4) { @apply [animation-delay:180ms]; }
        main > *:nth-child(5) { @apply [animation-delay:240ms]; }
        main > *:nth-child(n+6) { @apply [animation-delay:300ms]; }
        main .grid > .stat-card { @apply animate-ast-fade-up; }
        main .grid > .stat-card:nth-child(1) { @apply [animation-delay:80ms]; }
        main .grid > .stat-card:nth-child(2) { @apply [animation-delay:140ms]; }
        main .grid > .stat-card:nth-child(3) { @apply [animation-delay:200ms]; }
        main .grid > .stat-card:nth-child(4) { @apply [animation-delay:260ms]; }

        /* ===== Top bar ===== */
        header.sticky { @apply !bg-white/90 backdrop-blur-md backdrop-saturate-150 shadow-[0_1px_0_rgba(12,35,64,0.04),0_4px_16px_rgba(12,35,64,0.04)] dark:!bg-[#0C2340]/90; }
        #global-connectivity-pill { @apply transition-transform hover:-translate-y-px; }
        #global-connectivity-pill .bg-emerald-500 { @apply animate-ast-pulse-ring; }
        header button[onclick="toggleTheme()"] svg { @apply transition-transform duration-300 ease-snappy; }
        header button[onclick="toggleTheme()"]:hover svg { @apply rotate-[25deg] scale-110; }

        /* ===== Page headings ===== */
        main h1 { @apply tracking-tight !text-[#0C2340] dark:!text-white; }

        /* ===== White content cards ===== */
        main .bg-white.rounded-2xl { @apply border-[#E8EEF5] shadow-[0_1px_2px_rgba(12,35,64,0.04),0_6px_20px_rgba(12,35,64,0.04)] transition-shadow duration-300 hover:shadow-[0_2px_4px_rgba(12,35,64,0.05),0_12px_28px_rgba(12,35,64,0.07)] dark:border-[#15345C] dark:shadow-[0_6px_20px_rgba(0,0,0,0.22)]; }

        /* ===== Stat cards ===== */
        .stat-card { @apply transition-[transform,box-shadow,border-color] duration-300 ease-snappy hover:-translate-y-[3px] hover:shadow-[0_12px_28px_rgba(12,35,64,0.09)] dark:hover:shadow-[0_12px_28px_rgba(0,0,0,0.35)]; }
        .stat-card:hover { border-color: color-mix(in srgb, var(--stat-accent, #F28C28) 40%, transparent); }
        .stat-card::after { @apply origin-left animate-ast-bar-grow; }
        .stat-card .rounded-xl { @apply transition-transform duration-300 ease-snappy; }
        .stat-card:hover .rounded-xl { @apply scale-110 -rotate-[4deg]; }
        .stat-card .text-3xl { @apply tabular-nums; }

        /* ===== Buttons ===== */
        .btn-primary, .btn-secondary, .btn-danger, button[type="submit"]:not(.btn-icon):not(.btn-link) { @apply relative overflow-hidden whitespace-nowrap shrink-0; }
        .btn-primary:active, .btn-secondary:active, button[type="submit"]:not(.btn-secondary):not(.btn-danger):not(.btn-icon):not(.btn-link):active { @apply !translate-y-0 !scale-[0.97] !shadow-[0_2px_8px_rgba(242,140,40,0.25)]; }
        .btn-primary::after, button[type="submit"]:not(.btn-secondary):not(.btn-danger):not(.btn-icon):not(.btn-link)::after { @apply content-[''] absolute top-0 -left-[60%] w-2/5 h-full -skew-x-[20deg] pointer-events-none bg-gradient-to-r from-transparent via-white/30 to-transparent transition-[left] duration-[600ms] ease-in-out; }
        .btn-primary:hover::after, button[type="submit"]:not(.btn-secondary):not(.btn-danger):not(.btn-icon):not(.btn-link):hover::after { @apply left-[130%]; }
        button:focus-visible, a:focus-visible { @apply outline outline-2 outline-offset-2 outline-[#F28C28]; }
        button:disabled { @apply opacity-60 cursor-not-allowed; }

        /* ===== Tables ===== */
        table thead th { @apply sticky top-0 z-[1] whitespace-nowrap; }
        table tbody tr { @apply transition-[background-color,box-shadow] duration-150; }
        table tbody tr:hover { @apply bg-[#FFF8EF] shadow-[inset_3px_0_0_#F28C28]; }
        table tbody tr:last-child td { @apply !border-b-0; }
        table tbody td button, table tbody td a { @apply transition-transform duration-150 ease-snappy hover:scale-105; }

        /* ===== Status badges: dot in front, pulsing on Low / Pending ===== */
        .badge-good, .badge-active, .badge-confirmed, .badge-low, .badge-warning, .badge-pending, .badge-danger, .badge-spoilage, .badge-expired { @apply gap-1.5 whitespace-nowrap; }
        .badge-good::before, .badge-active::before, .badge-confirmed::before, .badge-low::before, .badge-warning::before, .badge-pending::before, .badge-danger::before, .badge-spoilage::before, .badge-expired::before { @apply content-[''] w-1.5 h-1.5 rounded-full bg-current shrink-0; }
        .badge-low::before, .badge-warning::before, .badge-pending::before { @apply animate-ast-pulse-ring-orange; }

        /* ===== Forms ===== */
        .grid > * { @apply min-w-0; }
        input, select, textarea { @apply transition-[border-color,box-shadow,background-color] duration-150 max-w-full min-w-0 box-border; }
        input[type="date"] { @apply max-w-full !min-w-0 appearance-none box-border; -webkit-appearance: none; }
        input[type="date"]::-webkit-date-and-time-value { text-align: left; margin: 0; }
        input:not([type="checkbox"]):not([type="radio"]):not(:focus):hover, select:not(:focus):hover, textarea:not(:focus):hover { @apply border-slate-300 dark:border-[#1C3E69]; }
        input[type="checkbox"], input[type="radio"] { @apply accent-[#F28C28] cursor-pointer; }

        /* ===== Modals: fade + scale in every time they open ===== */
        .modal-overlay { @apply bg-[#0C2340]/50 backdrop-blur-[6px]; }
        .modal-overlay:not(.hidden) { @apply animate-ast-fade-in; }
        .modal-overlay:not(.hidden) > div { @apply animate-ast-scale-in max-h-[calc(100vh-2rem)] overflow-y-auto; }
        .modal-overlay button[onclick^="close"] svg { @apply transition-transform duration-300 ease-snappy; }
        .modal-overlay button[onclick^="close"]:hover svg { @apply rotate-90; }

        /* ===== Offline toast: slide up instead of bouncing ===== */
        #offline-toast-notification { @apply !animate-ast-toast-in; }

        /* ===== Login page ===== */
        body > .max-w-4xl.rounded-2xl { @apply animate-ast-scale-in [animation-duration:600ms] shadow-[0_30px_60px_-20px_rgba(12,35,64,0.35)] md:min-h-[540px]; }
        body > .max-w-4xl.rounded-2xl > div:first-child { @apply overflow-hidden max-md:!py-8; }
        body > .max-w-4xl.rounded-2xl > div:first-child > div:first-child > * { @apply animate-ast-fade-up [animation-duration:600ms]; }
        body > .max-w-4xl.rounded-2xl > div:first-child > div:first-child > *:nth-child(2) { @apply [animation-delay:250ms]; }
        body > .max-w-4xl.rounded-2xl > div:first-child > div:first-child > *:nth-child(3) { @apply [animation-delay:330ms]; }
        body > .max-w-4xl.rounded-2xl > div:first-child > div:first-child > *:nth-child(4) { @apply animate-ast-bar-grow origin-center [animation-delay:450ms]; }
        body > .max-w-4xl.rounded-2xl > div:last-child > *, body > .max-w-4xl.rounded-2xl form > * { @apply animate-ast-fade-up; }
        body > .max-w-4xl.rounded-2xl > div:last-child > *:nth-child(1) { @apply [animation-delay:200ms]; }
        body > .max-w-4xl.rounded-2xl form > *:nth-child(2) { @apply [animation-delay:280ms]; }
        body > .max-w-4xl.rounded-2xl form > *:nth-child(3) { @apply [animation-delay:340ms]; }
        body > .max-w-4xl.rounded-2xl form > *:nth-child(4) { @apply [animation-delay:400ms]; }
        body > .max-w-4xl.rounded-2xl form > *:nth-child(5) { @apply [animation-delay:460ms]; }
        body > .max-w-4xl.rounded-2xl > div:last-child > *:nth-last-child(2) { @apply [animation-delay:520ms]; }
        body > .max-w-4xl.rounded-2xl > div:last-child > *:last-child { @apply [animation-delay:580ms]; }
        /* Login logo: pop in + orange glow ring, asterisk twinkles, spins on hover */
        body > .max-w-4xl.rounded-2xl > div:first-child > div:first-child > .w-24 { @apply animate-ast-logo-pop-glow max-md:!w-[4.5rem] max-md:!h-[4.5rem] max-md:!mb-3; }
        body > .max-w-4xl.rounded-2xl > div:first-child img { @apply animate-ast-logo-twinkle-fast; }
        body > .max-w-4xl.rounded-2xl > div:first-child > div:first-child > .w-24:hover img { @apply animate-ast-logo-spin; }
        body > .max-w-4xl.rounded-2xl > div:first-child h1 { @apply max-md:!text-2xl; }
        #theme-toggle svg { @apply transition-transform duration-300 ease-snappy; }
        #theme-toggle:hover svg { @apply rotate-[25deg]; }

        /* ===== Room for the mobile tab bar (tab bar itself is in includes/sidebar.php) ===== */
        body:has(#mobile-tabbar) { @apply max-lg:pb-[calc(88px+env(safe-area-inset-bottom))]; }
        #offline-toast-notification { @apply max-lg:!bottom-[calc(86px+env(safe-area-inset-bottom))]; }

        /* ===== Tablet and phone layout ===== */
        main { @apply max-lg:!px-5 max-sm:!p-4; }
        main > * + * { @apply max-sm:!mt-4; }
        main h1 { @apply max-sm:!text-2xl; }
        main .grid:has(> .stat-card) { @apply max-sm:!grid-cols-2 max-sm:!gap-3 max-[360px]:!grid-cols-1; }
        main .grid > .stat-card { @apply max-sm:!p-3.5; }
        main .grid > .stat-card .text-3xl { @apply max-sm:!text-2xl; }
        main .grid > .stat-card .w-10.h-10 { @apply max-sm:!w-8 max-sm:!h-8; }
        main .grid > .stat-card .uppercase { @apply max-sm:!text-[10px] max-sm:!tracking-[0.04em]; }
        .overflow-x-auto > table { @apply max-sm:min-w-[560px]; }
        table thead th { @apply max-sm:!px-3 max-sm:!py-2.5; }
        table tbody td { @apply max-sm:!p-3; }
        .modal-overlay:not(.hidden) { @apply max-sm:!items-end max-sm:!p-0; }
        .modal-overlay:not(.hidden) > div { @apply max-sm:!max-w-full max-sm:!rounded-b-none max-sm:!rounded-t-[20px] max-sm:max-h-[92vh] max-sm:!p-5 max-sm:animate-ast-fade-up; }
        .modal-overlay .grid { @apply max-sm:!grid-cols-1; }
        /* Mobile & tablet button ergonomics */
        .modal-overlay form > div:last-child button { @apply max-sm:flex-1 max-sm:min-h-[48px] max-sm:text-sm; }
        header.sticky > div { @apply max-sm:!py-2.5 max-sm:!gap-2; }
        #global-connectivity-pill { @apply max-[360px]:!hidden; }

        /* ===== Reduced motion + printing ===== */
        *, *::before, *::after { @apply motion-reduce:!animate-none motion-reduce:!transition-none print:!animate-none print:!transition-none; }
</style>

<!-- Dexie.js (IndexedDB Offline Store) & Offline Engine -->
<script src="https://cdn.jsdelivr.net/npm/dexie@3.2.4/dist/dexie.min.js"></script>
<script src="../assets/js/offline-engine.js"></script>

<!-- PWA Service Worker Registration -->
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js')
                .then(reg => console.log('✓ Service Worker registered successfully (Scope:', reg.scope, ')'))
                .catch(err => console.warn('Service Worker registration failed:', err));
        });
    }
</script>
