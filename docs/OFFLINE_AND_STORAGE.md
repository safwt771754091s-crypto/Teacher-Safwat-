# تخزين الكتب والعمل بدون إنترنت

المنصة مصممة لتكون **خفيفة** وتعمل **بدون إنترنت**: لا يحمل كل جهاز المنهج كاملًا، بل
تخزَّن الكتب مركزيًا في **Supabase Storage**، ويجلب التطبيق كل كتاب عند الحاجة ويحفظه
محليًا إن أراد الطالب.

## 1) تخزين مركزي في Supabase Storage

يُرفع كل ملف (PDF كتاب، أو صورة صفحة) إلى القرص المُعدّ في `TEXTBOOK_DISK`. محليًا
الافتراضي `public`؛ في الإنتاج اضبطه على `supabase`.

```env
TEXTBOOK_DISK=supabase
SUPABASE_S3_BUCKET=textbooks
SUPABASE_S3_REGION=ap-south-1
SUPABASE_S3_ACCESS_KEY_ID=...
SUPABASE_S3_SECRET_ACCESS_KEY=...
```

القرص `supabase` مُعرَّف في `backend/config/filesystems.php` كمشغّل `s3` متوافق مع
بروتوكول S3 الخاص بـ Supabase (endpoint:
`https://<ref>.storage.supabase.co/storage/v1/s3`، `use_path_style_endpoint=true`).
أنشئ الحاوية باسم `textbooks` واجعلها **public** (أو اقرأها عبر روابط موقّعة).

مفاتيح S3 تُنشأ من لوحة Supabase: **Project Settings → Storage → S3 access keys**.

### لماذا لا يتغيّر أي منطق عند التبديل؟
الخدمة `ContentExtractionService::withLocalFile()` تحلّ الملف إلى مسار محلي: إن كان
القرص محليًا استخدم `path()` مباشرةً، وإن كان بعيدًا (Supabase/S3) نزّله إلى ملف مؤقت ثم
حذفه بعد الانتهاء. لذلك يعمل استخراج نص PDF وOCR مع أي قرص دون تعديل.

## 2) التطبيق الخفيف (PWA)

`web/index.html` يعرّف `manifest.webmanifest` ويسجّل `web/sw.js`، فتصبح المنصة قابلة
للتثبيت على الهاتف (Add to Home Screen) وتعمل كتطبيق.

`web/sw.js`:

- يخزّن هيكل التطبيق (`ts-shell-v3`) ليُفتح بلا شبكة.
- يخزّن استجابات الـ API المقروءة (`ts-runtime-v3`) بأسلوب **network-first** (الشبكة أولًا،
  ثم النسخة المحفوظة).
- يقرأ الكتب المحفوظة يدويًا من `ts-books-v1`.

## 3) حفظ كتاب للعمل بدون إنترنت

في «دروسي» يظهر على كل كتاب زر **📥 حفظ بدون إنترنت** (`web/assets/offline.js`). عند
الضغط ينزّل:

1. دروس الكتاب: `GET /textbooks/{id}/lessons`
2. قائمة الصفوف والمواد والكتب: `GET /grades`، `/grades/{id}/subjects`، `/subjects/{id}/textbooks`
3. لكل درس: `GET /lessons/{id}` و`GET /lessons/{id}/exercises`

كل ذلك يُخزَّن في `ts-books-v1`، ويُسجَّل الكتاب في `localStorage` تحت `ts_offline_books`.
عند انقطاع الشبكة يخدمها `sw.js` من الذاكرة، ويظهر شريط **بدون إنترنت** في الشريط العلوي.

لتحديث كتاب محفوظ: أعد الضغط على الزر (يصبح «✅ محفوظ بدون إنترنت» ثم أزل الحفظ وأعد
الحفظ) لسحب أحدث نسخة.

## 4) النسخ الكاملة للملفات

- **نسخة HTML بدون إنترنت** لكل كتاب: `GET /textbooks/{id}/download?format=html`
  (تشمل نصّ الكتاب نفسه، صفحةً صفحة). أضف `?pages=0` لإسقاط نصّ الصفحات.
- **PDF** لكل كتاب: `GET /textbooks/{id}/download?format=pdf`.
- **كتب الصف كاملة**: `GET /grades/{id}/download`.

هذه النسخ للملف الواحد تكمّل الحفظ داخل التطبيق: من أراد ملفًا واحدًا يحمّله، ومن أراد
تجربة تطبيق خفيفة يستخدم زر الحفظ بدون إنترنت.
