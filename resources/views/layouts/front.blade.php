<!DOCTYPE html>
<html lang="ar" dir="rtl">
    @php
        use App\Models\SiteSetting;

        $siteSettings = SiteSetting::currentOrFallback();
        $siteName = $siteSettings->resolvedSiteName();
        $brandName = $siteSettings->resolvedBrandName();
        $brandLogoUrl = $siteSettings->brandLogoUrl();
        $faviconUrl = $siteSettings->faviconUrl();
    @endphp
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? $siteName }}</title>
        @if (filled($faviconUrl))
            <link rel="icon" href="{{ $faviconUrl }}">
        @endif

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100">
        <div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(15,42,95,0.45),_transparent_35%),linear-gradient(180deg,_#020617_0%,_#0f172a_52%,_#e2e8f0_52%,_#f8fafc_100%)]">
            <header class="border-b border-white/10 bg-slate-950/75 backdrop-blur">
                <div class="mx-auto flex w-full max-w-7xl items-center justify-between gap-6 px-6 py-4 lg:px-8">
                    <a href="/" class="flex items-center gap-3">
                        <img
                            src="{{ $brandLogoUrl }}"
                            alt="{{ $brandName }}"
                            class="h-14 w-auto rounded-lg bg-white/95 p-2 shadow-sm"
                        >
                        <div class="hidden sm:block">
                            <p class="text-sm text-slate-300">{{ $brandName }}</p>
                            <p class="text-lg font-semibold text-white">{{ $siteName }}</p>
                        </div>
                    </a>

                    <nav class="hidden items-center gap-6 text-sm text-slate-200 lg:flex">
                        <a href="#home" class="transition hover:text-white">الرئيسية</a>
                        <a href="#services" class="transition hover:text-white">الخدمة الحالية</a>
                        <a href="#features" class="transition hover:text-white">المميزات</a>
                        <a href="/admin" class="transition hover:text-white">لوحة الإدارة</a>
                    </nav>

                    <a
                        href="/admin"
                        class="inline-flex items-center rounded-full border border-emerald-400/40 bg-emerald-400/10 px-4 py-2 text-sm font-medium text-emerald-200 transition hover:bg-emerald-400/20 hover:text-white"
                    >
                        لوحة الإدارة
                    </a>
                </div>
            </header>

            <main>
                {{ $slot }}
            </main>

            <footer id="contact" class="border-t border-slate-200 bg-slate-50">
                <div class="mx-auto flex w-full max-w-7xl flex-col gap-3 px-6 py-6 text-sm text-slate-600 lg:flex-row lg:items-center lg:justify-between lg:px-8">
                    <p>جميع الحقوق محفوظة</p>
                    <p>{{ $siteName }}</p>
                </div>
            </footer>
        </div>

        @livewireScripts
    </body>
</html>