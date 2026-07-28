@php
    use App\Models\SiteSetting;
    use Illuminate\Support\Carbon;

    $siteSettings = SiteSetting::currentOrFallback();
    $isInvalid = in_array($state, ['invalid', 'expired', 'revoked', 'used'], true);
    $isSuccess = in_array($state, ['connected', 'used_connected'], true);
    $isQrReady = $state === 'qr_required' && filled($qrSvg);
    $isAuthenticated = $state === 'authenticated';
    $isRecoverableError = in_array($state, ['error', 'disconnected', 'logged_out'], true);
    $isUsedConnected = $state === 'used_connected';
@endphp

<style>
    .pairing-qr-card {
        width: min(88vw, 380px);
        max-width: 380px;
        margin-inline: auto;
        padding: 1rem;
        background: #ffffff;
        border-radius: 1rem;
    }

    .pairing-qr-wrapper {
        width: min(82vw, 340px);
        max-width: 340px;
        margin-inline: auto;
    }

    .pairing-qr-wrapper svg,
    .pairing-qr-wrapper img,
    .pairing-qr-wrapper canvas {
        display: block;
        width: 100% !important;
        max-width: 100% !important;
        height: auto !important;
        margin-inline: auto;
    }

    @media (min-width: 640px) {
        .pairing-qr-wrapper {
            max-width: 360px;
        }
    }

    @media (max-width: 640px) {
        .pairing-qr-card {
            width: min(88vw, 340px);
            padding: 0.75rem;
        }

        .pairing-qr-wrapper {
            width: min(82vw, 320px);
        }
    }
</style>

<div @if ($shouldPoll) wire:poll.5s="refreshSnapshot" @endif>
    <section class="overflow-x-hidden px-4 py-8 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
        <div class="mx-auto flex min-h-full w-full max-w-3xl items-center justify-center">
            <div class="w-full max-w-2xl rounded-[2rem] border border-white/10 bg-slate-950/75 p-6 shadow-2xl shadow-slate-950/40 backdrop-blur sm:p-8 lg:p-10">
                <div class="mx-auto mb-6 flex w-full max-w-md flex-col items-center text-center">
                    <img
                        src="{{ $siteSettings->brandLogoUrl() }}"
                        alt="{{ $siteSettings->resolvedBrandName() }}"
                        class="mb-4 h-10 w-auto rounded-xl bg-white/95 p-2 shadow-sm"
                        style="height: 100px; width: auto;"
                    >
                    <h1 class="text-3xl font-black leading-tight text-white sm:text-4xl">ربط حساب واتساب</h1>
                    <p class="mt-4 text-sm leading-8 text-slate-200 sm:text-base">
                        {{ $message }}
                    </p>

                    @if ($expiresAt && ! $isInvalid && ! $isSuccess)
                        <p class="mt-4 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs text-slate-300 sm:text-sm">
                            تنتهي صلاحية الرابط في: {{ Carbon::parse($expiresAt)->format('Y-m-d H:i') }}
                        </p>
                    @endif
                </div>

                @if ($isSuccess)
                    <div class="flex min-h-[20rem] flex-col items-center justify-center rounded-[1.75rem] border border-emerald-200 bg-emerald-50 px-6 py-8 text-center">
                        <div class="mb-5 h-16 w-16 rounded-full bg-emerald-500/10 p-4 text-emerald-700">
                            <svg viewBox="0 0 24 24" fill="none" class="h-full w-full" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold text-emerald-900">{{ $message }}</h2>
                        <p class="mt-3 max-w-md text-sm leading-7 text-emerald-800">
                            @if ($isUsedConnected)
                                لإجراء عملية ربط جديدة، يرجى إنشاء رابط جديد من لوحة التحكم.
                            @else
                                يمكنك إغلاق هذه الصفحة الآن.
                            @endif
                        </p>
                    </div>
                @elseif ($isInvalid)
                    <div class="flex min-h-[20rem] flex-col items-center justify-center rounded-[1.75rem] border border-rose-200 bg-rose-50 px-6 py-8 text-center">
                        <div class="mb-5 h-16 w-16 rounded-full bg-rose-500/10 p-4 text-rose-700">
                            <svg viewBox="0 0 24 24" fill="none" class="h-full w-full" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6 18 18M18 6 6 18" />
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold text-rose-900">رابط الربط غير صالح</h2>
                        <p class="mt-3 max-w-md text-sm leading-7 text-rose-800">
                            يرجى طلب رابط جديد من الإدارة لإكمال الربط.
                        </p>
                    </div>
                @elseif ($isQrReady)
                    <div class="rounded-[1.75rem] border border-slate-200 bg-white px-5 py-6 text-center shadow-sm sm:px-6 sm:py-7">
                        <div class="pairing-qr-card border border-slate-200 shadow-sm shadow-slate-200/80">
                            <div class="pairing-qr-wrapper">
                                <img src="{{ $qrSvg }}" alt="WhatsApp QR code" class="pairing-qr-image">
                            </div>
                        </div>

                        <div class="mx-auto mt-6 max-w-lg rounded-2xl bg-slate-900 px-5 py-4 text-sm leading-8 text-slate-100">
                            <p>افتح واتساب على الهاتف، ثم انتقل إلى:</p>
                            <p>الإعدادات ثم الأجهزة المرتبطة ثم ربط جهاز</p>
                            <p>بعدها امسح رمز QR الظاهر.</p>
                        </div>
                    </div>
                @else
                    <div class="flex min-h-[20rem] flex-col items-center justify-center rounded-[1.75rem] border border-slate-200 bg-white px-6 py-8 text-center shadow-sm">
                        <div class="mb-5 h-16 w-16 animate-spin rounded-full border-4 border-slate-200 border-t-emerald-500"></div>
                        <h2 class="text-2xl font-bold text-slate-900">
                            @if ($isAuthenticated)
                                تم مسح الرمز، جارٍ إكمال الاتصال...
                            @elseif ($isRecoverableError)
                                جارٍ محاولة إكمال الربط...
                            @else
                                جارٍ تجهيز رمز الربط...
                            @endif
                        </h2>
                        <p class="mt-3 max-w-md text-sm leading-7 text-slate-600">
                            {{ $message }}
                        </p>
                    </div>
                @endif
            </div>
        </div>
    </section>
</div>