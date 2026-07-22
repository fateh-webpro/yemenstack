# Yemen Stack WhatsApp Engine

## التعريف

هذا المجلد يحتوي محرك Node.js الخاص بخدمة WhatsApp Gateway داخل منصة Yemen Stack.
المحرك ليس هو المشروع كله، بل خدمة تشغيلية مستقلة داخل مسار `services/whatsapp-engine`.

## مسؤولية المحرك

المحرك الحالي مسؤول عن:

- إدارة جلسة WhatsApp Web عبر `LocalAuth`
- إظهار QR عند الحاجة
- تحديث حالة حساب واتساب في Laravel
- قراءة الرسائل `pending`
- تنفيذ `claim` لتحويلها إلى `queued`
- قراءة الرسائل `queued`
- الإرسال الحقيقي المقيّد عند تفعيل ذلك صراحة
- تسجيل `mark-sent` أو `mark-failed`
- الاستعادة من بعض أخطاء Puppeteer المؤقتة
- الإغلاق الآمن `graceful shutdown`
- التشغيل الخلفي عبر PM2

## حدود المرحلة الحالية

- المحرك الحالي يدير جلسة WhatsApp واحدة فقط.
- دعم عدة جلسات أو عدة أرقام داخل العملية نفسها لم يُنفذ بعد.
- Laravel ما زال هو مصدر الحقيقة للرسائل والحالات والسجلات.
- المحرك يتكامل مع Laravel عبر HTTP داخلي باستخدام Bearer token خاص بالمحرك.

## الملفات الأساسية

- `src/index.js`
  - المحرك الخلفي الأساسي طويل التشغيل.
  - يبدأ جلسة WhatsApp، وينتظر `ready`، ثم يدير polling والإرسال الخلفي.
- `src/whatsappSession.js`
  - مخصص لإعداد الجلسة والـ QR والتحقق من الجاهزية فقط.
  - ليس هو مسار التشغيل الإنتاجي الأساسي.
- `src/sendOneQueuedMessage.js`
  - أداة اختبار يدوي محدودة لإرسال رسالة `queued` واحدة فقط عند تفعيل الإرسال الحقيقي صراحة.
- `src/pendingMessages.js`
  - يقرأ الرسائل `pending` ويطلب `claim` لها.
- `src/queuedMessages.js`
  - مسار `simulation/debug` لقراءة `queued` وتعليمها `sent` دون إرسال حقيقي.
  - ليس هو مسار الإنتاج الرئيسي بعد اكتمال محرك `index.js`.
- `src/realMessageSender.js`
  - يحتوي منطق الإرسال الحقيقي لرسالة `queued` واحدة والتعامل مع نجاح الإرسال أو فشله.
- `src/laravelClient.js`
  - العميل الداخلي المسؤول عن استدعاء Laravel API من داخل المحرك.
- `src/config.js`
  - تحميل الإعدادات من `process.env` مع قيم افتراضية محافظة.
- `src/logger.js`
  - JSON logger بسيط مناسب للـ logs التشغيلية.
- `src/health.js`
  - فحص صحة بسيط للمحرك دون بدء جلسة WhatsApp.

## Scripts الفعلية

الـ scripts الحالية في `package.json` هي:

- `npm start`
  - يشغل `src/index.js`
  - هذا هو مسار التشغيل الخلفي الأساسي للمحرك.
- `npm run dev`
  - يشغل `src/index.js`
  - مطابق حاليًا لـ `start`.
- `npm run health`
  - يشغل `src/health.js`
  - فحص سريع لا يبدأ الجلسة.
- `npm run poll:pending`
  - يشغل `src/pendingMessages.js`
  - يقرأ الرسائل `pending` ويطلب `claim` فقط.
- `npm run process:queued`
  - يشغل `src/queuedMessages.js`
  - مسار simulation/debug لتمييز الرسائل `queued` كأنها `sent` دون إرسال حقيقي.
- `npm run whatsapp:qr`
  - يشغل `src/whatsappSession.js`
  - يستخدم لإعداد الجلسة وإظهار QR والتحقق من الاتصال.
- `npm run send:one`
  - يشغل `src/sendOneQueuedMessage.js`
  - اختبار يدوي محدود جدًا لإرسال رسالة `queued` واحدة فقط.

## تدفق العمل الحالي

المحرك يتكامل مع Laravel API وفق التدفق التالي:

1. Laravel ينشئ الرسائل عبر API بحالة `pending`.
2. المحرك يقرأ `pending` ثم يطلب `claim` لتحويلها إلى `queued`.
3. المحرك يقرأ `queued`.
4. عند تفعيل الإرسال الحقيقي، يحاول الإرسال عبر WhatsApp Web.
5. بعد ذلك يرسل إلى Laravel نتيجة `mark-sent` أو `mark-failed`.

## LocalAuth والجلسة

- الجلسة تُحفظ محليًا داخل `.wwebjs_auth`.
- لا يتم حذف هذا المجلد أثناء التشغيل العادي.
- لا يجب تشغيل نفس الجلسة محليًا وعلى VPS في الوقت نفسه.
- لا يجب تشغيل أكثر من محرك على نفس `WHATSAPP_SESSION_ID`.

## الاستعادة من الأخطاء المؤقتة

المحرك يحتوي آلية استعادة تلقائية محدودة من أخطاء اتصال أو Puppeteer مؤقتة، مثل:

- `Attempted to use detached Frame`
- `sendIq called before startComms`
- `Execution context was destroyed`
- `Target closed`
- `Connection closed`

الهدف من هذه الآلية هو إعادة تهيئة العميل الحالي دون حذف `LocalAuth` أو إجبار النظام على طلب QR جديد ما دامت الجلسة ما زالت سليمة.
هذه الآلية لا تعني أن جميع الأخطاء قابلة للاستعادة، ولا ينبغي اعتبارها بديلًا عن المتابعة التشغيلية.

## متغيرات البيئة المستخدمة فعليًا

المتغيرات المقروءة من `src/config.js` هي:

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
- `ENGINE_MARK_FAILED_PATH_TEMPLATE`
- `WHATSAPP_SESSION_ID`
- `WHATSAPP_CHROME_PATH`
- `WHATSAPP_HEADLESS`
- `WHATSAPP_QR_TERMINAL_SMALL`
- `ENABLE_REAL_WHATSAPP_SEND`
- `WHATSAPP_TEST_RECIPIENT`
- `ENGINE_FETCH_LIMIT`
- `WHATSAPP_SEND_LIMIT`
- `WHATSAPP_RESTART_DELAY_MS`
- `WHATSAPP_RESTART_TIMEOUT_MS`
- `WHATSAPP_MAX_RESTART_ATTEMPTS`

لا يتم وضع أي قيم حقيقية لهذه المتغيرات داخل الملفات المتتبعة.

## ملاحظات تشغيلية مهمة

- لا يتم حذف `.wwebjs_auth` أثناء التشغيل العادي.
- لا يتم حذف `.wwebjs_cache` كإجراء افتراضي داخل الكود.
- لا يتم وضع `ENGINE_API_TOKEN` الحقيقي داخل `ecosystem.config.cjs` المتتبع.
- لا ينبغي تشغيل نفس session محليًا وعلى VPS في الوقت نفسه.
- لا ينبغي تشغيل محركين على نفس الجلسة.
- `send:one` و`process:queued` أدوات تشغيلية خاصة بالاختبار أو التشخيص، وليست المسار الإنتاجي الرئيسي.

## PM2

ملف PM2 المتتبع هو:

- `ecosystem.config.cjs`

ويُستخدم لتشغيل:

- `src/index.js`

مع إعداد تشغيل بعملية واحدة `fork` و`instances: 1`.
هذا مناسب لأن الجلسة الحالية تعتمد على عميل WhatsApp واحد داخل عملية واحدة.

## ملاحظات أمنية

- لا تُحفظ أي Tokens أو Secrets داخل Git.
- لا تتم مشاركة ملف `.env` أو أي ملف تشغيل يحتوي قيمًا حقيقية.
- لا يُستخدم رقم اختبار حقيقي داخل الوثائق المتتبعة.
- لا ينبغي استخدام بيانات الإنتاج أثناء الاختبارات اليدوية أو المحلية.