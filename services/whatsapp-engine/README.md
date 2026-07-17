# Yemen Stack WhatsApp Engine

هذا المجلد يحتوي على الهيكل المبدئي لمحرك Node.js الخاص بخدمة WhatsApp Gateway داخل منصة Yemen Stack.

Yemen Stack منصة أوسع يمكن أن تحتوي لاحقًا على خدمات أخرى مثل SMS أو Email أو Notifications أو Payments.
لذلك تم اختيار المسار `services/whatsapp-engine` بدل `node-engine` حتى تبقى خدمة الواتساب منفصلة عن هوية المنصة العامة.

في هذه المرحلة:
- لا يوجد إرسال واتساب فعلي.
- لا يوجد QR.
- لا يوجد whatsapp-web.js.
- لا يوجد اتصال فعلي بواتساب.
- حالة `sent` هنا ناتجة عن simulation فقط.
- دورة الرسالة الحالية هي: `pending -> queued -> sent`.
- يقرأ المحرك الرسائل `pending` ثم يحاول `claim` لها.
- يقرأ المحرك الرسائل `queued` ثم يحاول تعليمها كـ `sent` في وضع المحاكاة.

أوامر التشغيل:
- `node src/index.js`
- `node src/health.js`
- `node src/pendingMessages.js`
- `node src/queuedMessages.js`

متغيرات البيئة:
- `NODE_ENV`
- `ENGINE_NAME`
- `ENGINE_POLL_INTERVAL_MS`
- `LARAVEL_BASE_URL`
- `ENGINE_API_TOKEN`
- `ENGINE_PENDING_MESSAGES_PATH`
- `ENGINE_QUEUED_MESSAGES_PATH`
- `ENGINE_CLAIM_MESSAGE_PATH_TEMPLATE`
- `ENGINE_MARK_SENT_PATH_TEMPLATE`
- `ENGINE_FETCH_LIMIT`

تنبيه:
- لا يتم طباعة `ENGINE_API_TOKEN` كاملًا داخل السجلات.

أمثلة:
- بدون توكن سيطبع المحرك تحذيرًا ويتجاوز القراءة والمعالجة.
- مع توكن صالح يملك `messages:read` و `messages:send` يمكن تنفيذ `node src/pendingMessages.js` ثم `node src/queuedMessages.js`.

محتوى هذه المرحلة:
- `src/index.js` لتشغيل heartbeat ثم `pending claim` ثم `queued simulated send`.
- `src/health.js` لفحص جاهزية الهيكل.
- `src/config.js` لقراءة الإعدادات من `process.env`.
- `src/logger.js` لتسجيل JSON logs بسيطة.
- `src/laravelClient.js` لطلب الرسائل وتنفيذ `claim` و `mark-sent` عبر Laravel.
- `src/pendingMessages.js` لقراءة الرسائل المعلقة ومحاولة `claim`.
- `src/queuedMessages.js` لقراءة الرسائل `queued` وتعليمها كـ `sent` في وضع المحاكاة فقط.
- `ecosystem.config.cjs` لتجهيز التشغيل عبر PM2 لاحقًا.