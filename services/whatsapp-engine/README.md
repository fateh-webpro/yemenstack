# Yemen Stack WhatsApp Engine

هذا المجلد يحتوي على الهيكل المبدئي لمحرك Node.js الخاص بخدمة WhatsApp Gateway داخل منصة Yemen Stack.

Yemen Stack منصة أوسع يمكن أن تحتوي لاحقًا على خدمات أخرى مثل SMS أو Email أو Notifications أو Payments.
لذلك تم اختيار المسار `services/whatsapp-engine` بدل `node-engine` حتى تبقى خدمة الواتساب منفصلة عن هوية المنصة العامة.

في هذه المرحلة:
- لا يوجد إرسال واتساب فعلي.
- لا يوجد QR.
- لا يوجد whatsapp-web.js.
- لا يوجد اتصال فعلي بواتساب.

أوامر التشغيل:
- `node src/index.js`
- `node src/health.js`

متغيرات البيئة:
- `NODE_ENV`
- `ENGINE_NAME`
- `ENGINE_POLL_INTERVAL_MS`
- `LARAVEL_BASE_URL`
- `ENGINE_API_TOKEN`

تنبيه:
- لا يتم طباعة `ENGINE_API_TOKEN` كاملًا داخل السجلات.

محتوى هذه المرحلة:
- `src/index.js` لتشغيل الهيكل المبدئي وإرسال heartbeat.
- `src/health.js` لفحص جاهزية الهيكل.
- `src/config.js` لقراءة الإعدادات من `process.env`.
- `src/logger.js` لتسجيل JSON logs بسيطة.
- `ecosystem.config.cjs` لتجهيز التشغيل عبر PM2 لاحقًا.