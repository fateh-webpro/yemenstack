@php
    $isInvalid = in_array($state, ['invalid', 'expired', 'revoked', 'used'], true);
    $isSuccess = $state === 'connected';
    $isQrReady = $state === 'qr_required' && filled($qrSvg);
    $isAuthenticated = $state === 'authenticated';
    $isRecoverableError = in_array($state, ['error', 'disconnected', 'logged_out'], true);
@endphp

<div @if ($shouldPoll) wire:poll.5s="refreshSnapshot" @endif>
    <section class="mx-auto flex min-h-[calc(100vh-12rem)] w-full max-w-4xl items-center px-4 py-8 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
        <div class="w-full overflow-hidden rounded-[2rem] border border-white/10 bg-slate-950/70 shadow-2xl shadow-slate-950/40 backdrop-blur">
            <div class="grid gap-0 lg:grid-cols-[0.9fr_1.1fr]">
                <div class="bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.2),_transparent_55%),linear-gradient(180deg,_rgba(15,23,42,0.94)_0%,_rgba(2,6,23,0.98)_100%)] p-8 text-white lg:p-10">
                    <p class="mb-3 text-sm font-semibold text-emerald-200/80">Yemen Stack</p>
                    <h1 class="text-3xl font-black leading-tight">ربط حساب واتساب</h1>
                    <p class="mt-4 text-sm leading-8 text-slate-200">
                        {{ $message }}
                    </p>

                    @if ($expiresAt && ! $isInvalid && ! $isSuccess)
                        <p class="mt-6 text-xs text-slate-300">
                            تنتهي صلاحية الرابط في: {{ \Illuminate\Support\Carbon::parse($expiresAt)->format('Y-m-d H:i') }}
                        </p>
                    @endif
                </div>

                <div class="bg-white p-6 sm:p-8 lg:p-10 text-slate-900">
                    @if ($isSuccess)
                        <div class="flex min-h-[24rem] flex-col items-center justify-center rounded-[1.75rem] border border-emerald-200 bg-emerald-50 px-6 text-center">
                            <div class="mb-5 h-16 w-16 rounded-full bg-emerald-500/10 p-4 text-emerald-700">
                                <svg viewBox="0 0 24 24" fill="none" class="h-full w-full" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                                </svg>
                            </div>
                            <h2 class="text-2xl font-bold text-emerald-900">تم ربط حساب واتساب بنجاح.</h2>
                            <p class="mt-3 max-w-md text-sm leading-7 text-emerald-800">
                                يمكن الآن إغلاق هذه الصفحة بأمان.
                            </p>
                        </div>
                    @elseif ($isInvalid)
                        <div class="flex min-h-[24rem] flex-col items-center justify-center rounded-[1.75rem] border border-rose-200 bg-rose-50 px-6 text-center">
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
                        <div class="rounded-[1.75rem] border border-slate-200 bg-slate-50 p-5 sm:p-6 shadow-sm">
                            <div class="pairing-qr-wrapper mx-auto w-[min(82vw,360px)] max-w-[360px] rounded-[1rem] bg-white p-4 shadow-sm shadow-slate-200/80 max-sm:w-[min(88vw,320px)] max-sm:p-3">
                                <img src="{{ $qrSvg }}" alt="رمز ربط واتساب" class="pairing-qr-image mx-auto block h-auto w-full max-w-full">
                            </div>
                            <div class="mt-6 rounded-2xl bg-slate-900 px-5 py-4 text-sm leading-8 text-slate-100">
                                <p>افتح واتساب على الهاتف، ثم انتقل إلى:</p>
                                <p>الإعدادات ثم الأجهزة المرتبطة ثم ربط جهاز</p>
                                <p>بعدها امسح رمز QR الظاهر.</p>
                            </div>
                        </div>
                    @else
                        <div class="flex min-h-[24rem] flex-col items-center justify-center rounded-[1.75rem] border border-slate-200 bg-slate-50 px-6 text-center">
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
        </div>
    </section>
</div>