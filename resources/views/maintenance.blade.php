@php
    $siteName = $siteSettings->resolvedSiteName();
    $brandName = $siteSettings->resolvedBrandName();
    $brandLogoUrl = $siteSettings->brandLogoUrl();
    $faviconUrl = $siteSettings->faviconUrl();
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>الصيانة - {{ $siteName }}</title>
        @if (filled($faviconUrl))
            <link rel="icon" href="{{ $faviconUrl }}">
        @endif
        <style>
            body {
                margin: 0;
                font-family: Tahoma, Arial, sans-serif;
                background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
                color: #e5e7eb;
            }

            .page {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
            }

            .card {
                width: 100%;
                max-width: 560px;
                background: rgba(15, 23, 42, 0.88);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 24px;
                padding: 40px 32px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
            }

            .logo {
                max-width: 180px;
                max-height: 96px;
                margin: 0 auto 24px;
                display: block;
                background: rgba(255, 255, 255, 0.96);
                border-radius: 18px;
                padding: 12px;
            }

            .brand {
                margin: 0 0 8px;
                font-size: 15px;
                color: #cbd5e1;
            }

            .title {
                margin: 0 0 16px;
                font-size: 32px;
                color: #ffffff;
            }

            .message {
                margin: 0;
                font-size: 17px;
                line-height: 1.9;
                color: #dbe4f0;
            }
        </style>
    </head>
    <body>
        <div class="page">
            <section class="card">
                @if (filled($brandLogoUrl))
                    <img src="{{ $brandLogoUrl }}" alt="{{ $brandName }}" class="logo">
                @endif
                <p class="brand">{{ $siteName }}</p>
                <h1 class="title">الموقع قيد الصيانة حاليًا</h1>
                <p class="message">نعمل على تحسين الخدمة، يرجى العودة لاحقًا.</p>
            </section>
        </div>
    </body>
</html>
