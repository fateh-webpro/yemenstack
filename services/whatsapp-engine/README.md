# Yemen Stack WhatsApp Engine

هذا المجلد يحتوي على الهيكل المبدئي لمحرك Node.js الخاص بخدمة WhatsApp Gateway داخل منصة Yemen Stack.

Yemen Stack منصة أوسع يمكن أن تحتوي لاحقًا على خدمات أخرى مثل SMS أو Email أو Notifications أو Payments.
لذلك تم اختيار المسار `services/whatsapp-engine` بدل `node-engine` حتى تبقى خدمة الواتساب منفصلة عن هوية المنصة العامة.

في هذه المرحلة:
- لا يوجد إرسال واتساب فعلي.
- لا يوجد QR مخزن في قاعدة البيانات.
- لا يوجد ربط فعلي مع دورة الرسائل `queued` للإرسال الحقيقي.
- لا يتم استخدام whatsapp-web.js للإرسال الآن، بل فقط للجلسة و QR.
- حالة `sent` في الرسائل ما زالت ناتجة عن simulation فقط.
- QR يظهر في terminal فقط.
- الجلسة تحفظ محليًا داخل `.wwebjs_auth` وهي مستثناة من Git.
- الحالات التي يتم تحديثها هي:
  `connecting`, `qr_required`, `connected`, `disconnected`, `logged_out`, `error`.

أوامر التشغيل:
- `node src/index.js`
- `node src/health.js`
- `node src/pendingMessages.js`
- `node src/queuedMessages.js`
- `node src/whatsappSession.js`
- `npm run whatsapp:qr`

متغيرات البيئة:
- `NODE_ENV`
- `ENGINE_NAME`
- `ENGINE_POLL_INTERVAL_MS`
- `LARAVEL_BASE_URL`
- `ENGINE_API_TOKEN`
- `ENGINE_PENDING_MESSAGES_PATH`
- `ENGINE_QUEUED_MESSAGES_PATH`
- `ENGINE_ACCOUNT_STATUS_PATH`
- `ENGINE_CLAIM_MESSAGE_PATH_TEMPLATE`
- `ENGINE_MARK_SENT_PATH_TEMPLATE`
- `ENGINE_FETCH_LIMIT`
- `WHATSAPP_SESSION_ID`
- `WHATSAPP_CHROME_PATH` ?????: `C:\Program Files\Google\Chrome\Application\chrome.exe`
- `WHATSAPP_HEADLESS`
- `WHATSAPP_QR_TERMINAL_SMALL`

تنبيه:
- لا يتم طباعة `ENGINE_API_TOKEN` كاملًا داخل السجلات.

محتوى هذه المرحلة:
- `src/index.js` لتشغيل heartbeat ثم `pending claim` ثم `queued simulated send`.
- `src/health.js` لفحص جاهزية الهيكل.
- `src/config.js` لقراءة الإعدادات من `process.env`.
- `src/logger.js` لتسجيل JSON logs بسيطة.
- `src/laravelClient.js` لطلب الرسائل وتنفيذ `claim` و `mark-sent` وتحديث حالة رقم الواتساب عبر Laravel.
- `src/pendingMessages.js` لقراءة الرسائل المعلقة ومحاولة `claim`.
- `src/queuedMessages.js` لقراءة الرسائل `queued` وتعليمها كـ `sent` في وضع المحاكاة فقط.
- `src/whatsappSession.js` لتشغيل جلسة WhatsApp واحدة وإظهار QR وتحديث حالة الحساب فقط.
- `ecosystem.config.cjs` لتجهيز التشغيل عبر PM2 لاحقًا.
## Real Send Test

This stage adds a very limited real WhatsApp send path for manual testing only.

Rules:
- Real send is disabled by default.
- Real send works only when `ENABLE_REAL_WHATSAPP_SEND=true`.
- Real send works only when `WHATSAPP_TEST_RECIPIENT` is configured.
- The script sends one queued message only.
- The script is not used by `src/index.js`.
- This is an initial manual test path, not a production workflow.

Command:
- `npm run send:one`

Environment variables:
- `ENABLE_REAL_WHATSAPP_SEND`
- `WHATSAPP_TEST_RECIPIENT`
- `WHATSAPP_SEND_LIMIT`
- `ENGINE_MARK_FAILED_PATH_TEMPLATE`

Behavior:
- The script reads queued messages with limit `1`.
- The script sends only when the queued message recipient matches `WHATSAPP_TEST_RECIPIENT`.
- On success it calls `mark-sent` with real provider payload.
- On failure it calls `mark-failed` with the error details.
- The script does not run automatically from the engine loop.