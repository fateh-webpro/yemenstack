@php
    use App\Models\SiteSetting;

    $siteSettings = SiteSetting::currentOrFallback();
@endphp

<div>
    <section id="home" class="mx-auto w-full max-w-7xl px-6 py-16 lg:px-8 lg:py-24">
        <div class="grid items-center gap-10 lg:grid-cols-[1.1fr_0.9fr]">
            <div class="space-y-6">
                <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-1 text-sm text-slate-200">
                    حلول رقمية متكاملة
                </span>

                <div class="space-y-4">
                    <h1 class="max-w-3xl text-4xl font-black leading-tight text-white lg:text-6xl">
                        يمن ستاك
                    </h1>
                    <p class="max-w-2xl text-lg leading-8 text-slate-300">
                        شركة تقنية تقدم حلولًا رقمية ومنتجات برمجية تساعد الجهات والأعمال على تنظيم عملياتها وربط أنظمتها بخدمات ذكية قابلة للتوسع.
                    </p>
                </div>

                <div class="flex flex-wrap gap-4">
                    <a
                        href="#services"
                        class="inline-flex items-center rounded-full bg-white px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-slate-200"
                    >
                        استعراض الخدمة الحالية
                    </a>
                    <a
                        href="/admin"
                        class="inline-flex items-center rounded-full border border-white/20 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/10"
                    >
                        الدخول إلى لوحة الإدارة
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
            <div class="mb-10 max-w-3xl space-y-3">
                <p class="text-sm font-semibold text-slate-500">الخدمة الحالية من يمن ستاك</p>
                <h2 class="text-3xl font-bold text-slate-950">WhatsApp Gateway</h2>
                <p class="text-lg font-semibold text-slate-700">بوابة واتساب لإدارة الأرقام والربط عبر API</p>
                <p class="text-sm leading-7 text-slate-600">
                    خدمة داخلية من يمن ستاك لإدارة أكثر من رقم واتساب، توليد QR مستقل لكل رقم، متابعة حالة الجلسات، حفظ سجلات الرسائل، وتجهيز التكامل مع الأنظمة عبر API و Webhooks لاحقًا.
                </p>
            </div>

            <div id="features" class="mb-8 max-w-2xl space-y-3">
                <p class="text-sm font-semibold text-slate-500">مميزات الخدمة الحالية</p>
                <h2 class="text-3xl font-bold text-slate-950">مكونات أساسية قابلة للتوسع لاحقًا</h2>
            </div>

            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 h-12 w-12 rounded-2xl bg-slate-950/95"></div>
                    <h3 class="mb-3 text-xl font-bold text-slate-950">إدارة أرقام واتساب متعددة</h3>
                    <p class="text-sm leading-7 text-slate-600">
                        لوحة موحدة لإدارة عدة أرقام واتساب مع فصل واضح بين الحسابات، وتجهيز QR مستقل لكل رقم داخل الخدمة الحالية.
                    </p>
                </article>

                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 h-12 w-12 rounded-2xl bg-emerald-500/90"></div>
                    <h3 class="mb-3 text-xl font-bold text-slate-950">API مخصص وسجل للرسائل والمحاولات</h3>
                    <p class="text-sm leading-7 text-slate-600">
                        تصور أولي لربط كل حساب عبر API مخصص مع حفظ سجلات الرسائل والمحاولات بشكل منظم وقابل للمراجعة.
                    </p>
                </article>

                <article id="tracking" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 h-12 w-12 rounded-2xl bg-slate-700"></div>
                    <h3 class="mb-3 text-xl font-bold text-slate-950">متابعة الجلسات والتوسع لاحقًا</h3>
                    <p class="text-sm leading-7 text-slate-600">
                        متابعة حالة الجلسات داخل WhatsApp Gateway مع قابلية التوسع لاحقًا لخدمات رقمية أخرى من يمن ستاك وتهيئة Webhooks مستقبلية.
                    </p>
                </article>
            </div>
        </div>
    </section>
</div>