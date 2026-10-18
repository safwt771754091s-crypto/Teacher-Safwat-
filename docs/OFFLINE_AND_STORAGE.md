# تخزين الكتب والتطبيق القابل للتثبيت

المنصة مصممة لتكون **خفيفة**: لا يحمل كل جهاز المنهج كاملًا، بل تخزَّن الكتب مركزيًا في
**Supabase Storage**، ويجلب التطبيق كل كتاب عند الحاجة. وهي **قابلة للتثبيت** على الهاتف
كتطبيق، لكنها **تعمل بالشبكة فقط** (بلا Service Worker).

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

### إنشاء الحاوية من الطرفية

المفتاح القابل للنشر (publishable/anon) **لا يستطيع** إنشاء الحاويات — ترفضه سياسات
RLS (HTTP 403). تحتاج مفتاحًا مرتفع الصلاحية، ثم شغّل السكربت الجاهز:

```bash
# عبر رمز الوصول الشخصي (Account → Access Tokens)
SUPABASE_ACCESS_TOKEN=sbp_xxx bash scripts/create_supabase_bucket.sh

# أو عبر مفتاح service_role الخاص بالمشروع (Project Settings → API)
SUPABASE_SERVICE_ROLE_KEY=eyJ... bash scripts/create_supabase_bucket.sh
```

السكربت `scripts/create_supabase_bucket.sh` **idempotent**: إن وُجدت الحاوية مسبقًا
يعتبر ذلك نجاحًا. وبديلًا عنه يمكنك إنشاء الحاوية يدويًا من اللوحة:
**Storage → New bucket → الاسم `textbooks` → Public**.

### لماذا لا يتغيّر أي منطق عند التبديل؟
الخدمة `ContentExtractionService::withLocalFile()` تحلّ الملف إلى مسار محلي: إن كان
القرص محليًا استخدم `path()` مباشرةً، وإن كان بعيدًا (Supabase/S3) نزّله إلى ملف مؤقت ثم
حذفه بعد الانتهاء. لذلك يعمل استخراج نص PDF وOCR مع أي قرص دون تعديل.

## 2) التطبيق القابل للتثبيت (PWA)

`web/index.html` يعرّف `manifest.webmanifest`، فتصبح المنصة قابلة للتثبيت على الهاتف
(Add to Home Screen) وتُفتح في **وضع مستقل** (`display: standalone`). الأيقونات في
`web/assets/icons/`: 192 و512، وأيقونة maskable لأندرويد، وأيقونة 180 لأيفون
(`apple-touch-icon`). أعد توليدها بـ `bash scripts/gen_pwa_icons.sh`.

> المنصة **لا تعمل دون اتصال** ولا تستخدم **Service Worker**؛ الوصول إلى الـ API يحتاج
> الشبكة، لكن التطبيق يُثبَّت ويعمل كتطبيق عادي.

## 3) النسخ الكاملة للملفات

- **نسخة HTML للطباعة** لكل كتاب: `GET /textbooks/{id}/download?format=html`
  (تشمل نصّ الكتاب نفسه، صفحةً صفحة). أضف `?pages=0` لإسقاط نصّ الصفحات.
- **PDF** لكل كتاب: `GET /textbooks/{id}/download?format=pdf`.
- **كتب الصف كاملة**: `GET /grades/{id}/download`.
