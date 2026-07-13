@php
    use App\Models\SiteSetting;

    $siteSettings = SiteSetting::currentOrFallback();
@endphp

<div>
    <section id="home" class="mx-auto w-full max-w-7xl px-6 py-16 lg:px-8 lg:py-24">
        <div class="grid items-center gap-10 lg:grid-cols-[1.1fr_0.9fr]">
            <div class="space-y-6">
                <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-1 text-sm text-slate-200">
                    Starter Kit عام مبني على Laravel و Livewire و Filament
                </span>

                <div class="space-y-4">
                    <h1 class="max-w-3xl text-4xl font-black leading-tight text-white lg:text-6xl">
                        منصة خدمات عامة احترافية
                    </h1>
                    <p class="max-w-2xl text-lg leading-8 text-slate-300">
                        أساس قابل للتخصيص لإدارة الخدمات والطلبات عبر لوحة إدارة وواجهة مستخدم تفاعلية.
                    </p>
                </div>

                <div class="flex flex-wrap gap-4">
                    <a
                        href="#services"
                        class="inline-flex items-center rounded-full bg-white px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-slate-200"
                    >
                        استعراض الأساس العام
                    </a>
                    <a
                        href="/admin"
                        class="inline-flex items-center rounded-full border border-white/20 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/10"
                    >
                        الانتقال إلى لوحة الإدارة
                    </a>
                </div>
            </div>

            <div class="rounded-[2rem] border border-white/10 bg-white/6 p-6 shadow-2xl shadow-slate-950/40 backdrop-blur">
                <div class="rounded-[1.5rem] bg-slate-900/80 p-6">
                    <img
                        src="{{ $siteSettings->brandLogoUrl() }}"
                        alt="{{ $siteSettings->resolvedBrandName() }}"
                        class="mx-auto h-36 w-auto"
                    >
                </div>
            </div>
        </div>
    </section>

    <section id="services" class="bg-slate-50">
        <div class="mx-auto w-full max-w-7xl px-6 py-16 lg:px-8">
            <div class="mb-8 max-w-2xl space-y-3">
                <p class="text-sm font-semibold text-slate-500">واجهة أولية قابلة للتوسعة</p>
                <h2 class="text-3xl font-bold text-slate-950">مكونات أساسية قابلة للبناء عليها</h2>
            </div>

            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 h-12 w-12 rounded-2xl bg-slate-950/95"></div>
                    <h3 class="mb-3 text-xl font-bold text-slate-950">إدارة الخدمات</h3>
                    <p class="text-sm leading-7 text-slate-600">
                        بنية أولية مرنة لتنظيم الخدمات والبيانات المرتبطة بها داخل لوحة إدارة مركزية.
                    </p>
                </article>

                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 h-12 w-12 rounded-2xl bg-emerald-500/90"></div>
                    <h3 class="mb-3 text-xl font-bold text-slate-950">استقبال الطلبات</h3>
                    <p class="text-sm leading-7 text-slate-600">
                        واجهة عامة مهيأة لاحقًا لاستقبال طلبات المستخدمين بطريقة واضحة وقابلة للتخصيص.
                    </p>
                </article>

                <article id="tracking" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 h-12 w-12 rounded-2xl bg-slate-700"></div>
                    <h3 class="mb-3 text-xl font-bold text-slate-950">تتبع الحالة</h3>
                    <p class="text-sm leading-7 text-slate-600">
                        مساحة أولية لتطوير تجربة متابعة الطلبات والحالات عبر واجهة تفاعلية منظمة.
                    </p>
                </article>
            </div>
        </div>
    </section>
</div>
