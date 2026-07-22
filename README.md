# Yemen Stack

## تعريف مختصر

Yemen Stack منصة قابلة للتوسع لتقديم خدمات تقنية متعددة، وتُعد خدمة WhatsApp Gateway أول خدمة تشغيلية ضمنها.

## الوضع الحالي

المشروع في مرحلة MVP متقدمة.
خدمة WhatsApp Gateway تعمل حاليًا بحساب تجريبي واحد على VPS، مع وجود لوحة إدارة، وLaravel API، ومحرك Node.js مستقل للإرسال وإدارة الجلسة.

## التقنيات الأساسية

- Laravel
- Filament
- MySQL
- Node.js
- whatsapp-web.js
- PM2
- Nginx
- PHP-FPM

## الوحدات الحالية

- الإدارة عبر Filament
- العملاء
- حسابات واتساب
- مفاتيح API
- الرسائل
- محاولات الإرسال
- سجلات Webhook
- محرك WhatsApp الخلفي

## تدفق الرسائل

`pending -> queued -> sent / failed`

## هيكل المشروع المختصر

- `app`
- `routes`
- `database`
- `services/whatsapp-engine`

## ملاحظات معمارية

- Yemen Stack هي المنصة الأساسية.
- WhatsApp Gateway خدمة ضمن المنصة وليست هوية المشروع بالكامل.
- Laravel يدير قاعدة البيانات ولوحة الإدارة وواجهات API.
- محرك `services/whatsapp-engine` يدير جلسة WhatsApp Web والـ QR والـ polling والإرسال الخلفي.
- المحرك الحالي يدير جلسة واحدة فقط في مرحلة MVP.
- دعم تعدد العملاء والجلسات والاشتراكات لم يُنفذ بعد.

## إعداد البيئة

تُستخدم متغيرات البيئة التالية بحسب الجزء المشغل:

- `APP_NAME`
- `APP_ENV`
- `APP_URL`
- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `ENGINE_API_TOKEN`
- `WHATSAPP_SESSION_ID`
- `WHATSAPP_CHROME_PATH`
- `ENABLE_REAL_WHATSAPP_SEND`
- `WHATSAPP_TEST_RECIPIENT`

لا تُحفظ أي قيم حقيقية لهذه المتغيرات داخل Git.

## تنبيه أمني

- لا تُضمَّن أي Tokens أو Secrets داخل Git.
- لا تتم مشاركة ملف `.env`.
- لا يتم حذف `.wwebjs_auth` دون سبب فني واضح ومخطط.
- لا تُستخدم بيانات الإنتاج في الاختبارات المحلية.
- لا يتم تشغيل أكثر من محرك على نفس جلسة WhatsApp.

## التشغيل المحلي

أوامر التشغيل المحلية الآمنة تشمل:

- `php artisan serve`
- `php artisan test`
- `npm run dev`
- `cd services/whatsapp-engine`
- `npm run health`
- `npm run whatsapp:qr`

الأمر `npm start` داخل `services/whatsapp-engine` يشغل المحرك الخلفي إذا كانت متغيرات البيئة المطلوبة مضبوطة.
لا ينبغي اعتباره أمر اختبار آمن ما لم تكن البيئة معروفة ومقصودة.

## تشغيل VPS

التشغيل على VPS يعتمد على:

- `services/whatsapp-engine/ecosystem.config.cjs`
- `pm2 start ecosystem.config.cjs`
- `pm2 save`
- `pm2 startup`

يجب ضبط متغيرات البيئة على السيرفر يدويًا، وعدم حفظ أي Token حقيقي داخل ملف PM2 المتتبع في Git.

## الاختبارات

التغطية الحالية محدودة.
لا توجد بعد اختبارات شاملة تغطي دورة الرسائل الكاملة أو سلوك محرك Node.js أو سيناريوهات الاستعادة والأمان.

## حالة المشروع

المشروع ما زال قيد التطوير.
الوظائف الأساسية لخدمة WhatsApp Gateway موجودة وتعمل ضمن MVP الحالي، لكن نظام الاشتراكات وتعدد العملاء والجلسات وإدارة القنوات المتعددة لم يكتمل بعد.