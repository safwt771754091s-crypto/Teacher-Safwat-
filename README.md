# معلمي الذكي اليمني

**معلم منزلي رقمي للمنهج اليمني** — منصة تعليمية حقيقية تشرح دروس كتاب المنهج اليمني،
تلخّصها، تقرؤها بالصوت، وتحلّ تمارينها خطوة بخطوة، مع ألعاب تعليمية للابتدائي.

> الطالب يختار الصف والمادة والدرس، والمنصة تستخرج المحتوى من الكتاب المرقمن أو صور
> الصفحات، ثم تبني الشرح والتلخيص والأسئلة **اعتمادًا على مصدر الكتاب نفسه**، لا على
> ذاكرة النموذج وحده.

يغطي النظام اليوم **المنهج اليمني كاملًا**: الصفوف من الأول الأساسي إلى الثالث الثانوي
(12 صفًا)، ومواد كل صف بأسمائها الرسمية. صفوف المرحلة الأساسية الأولى أُسست ببنية كاملة
(دروس، نقاط، تمارين، ملخصات، صفحات مصدر)، وبقية الصفوف تُزرع من **فهرس المنهج الرسمي**
كمنهج أولي قابل للتوسيع.

> **ملاحظة مهمة**: محتوى الصفوف غير المؤلَّفة بعد يظهر كـ«محتوى هيكل أولي من فهرس المنهج»
> مع ملاحظة ظاهرة داخل الدرس، ولا يُقدَّم كنص كتاب موثّق. عند رفع الطبعة الرسمية (PDF/صور)
> واعتمادها بشريًا يستبدل المحتوى تلقائيًا عبر مسار الاستخراج والمراجعة.

---

## المحتوى

- [المعمارية](#المعمارية)
- [وكلاء الذكاء الاصطناعي](docs/AI_AGENTS.md)
- [كيف نضمن أن الشرح من الكتاب الصحيح](#كيف-نضمن-أن-الشرح-من-الكتاب-الصحيح)
- [قاعدة البيانات](#قاعدة-البيانات)
- [التشغيل السريع](#التشغيل-السريع)
- [الحسابات التجريبية](#الحسابات-التجريبية)
- [واجهات API](#واجهات-api)
- [الاختبارات](#الاختبارات)
- [لوحات التحكم](#لوحات-التحكم)
- [خطة التنفيذ](#خطة-التنفيذ)

---

## المعمارية

```
project/
├── backend/    Laravel 13 (PHP 8.4) — REST API + Sanctum + قاعدة بيانات
├── web/        تطبيق ويب (HTML/CSS/JS خفيف، RTL، يعمل على الهاتف والكمبيوتر)
└── scripts/    bootstrap_env.sh (تهيئة كاملة) و run_demo.sh (تشغيل)
```

- **الخادم**: Laravel 13، مصادقة عبر Laravel Sanctum (توكن Bearer).
- **الواجهة**: تطبيق صفحة واحدة (SPA) بلا أدوات بناء، عربي RTL بالكامل، متجاوب
  (شريط سفلي على الهاتف، قائمة جانبية على الكمبيوتر)، قابل للتثبيت كـ PWA.
- **الذكاء الاصطناعي**: خدمة `AiTeacherService` تعمل بنمط **RAG مبسّط**: تسترجع نقاط
  الدرس من مصدره المخزّن وتصوغ الشرح منها، مع تسجيل كل استدعاء في `ai_usage_logs`.

### خدمات النطاق (`app/Services`)

| الخدمة | المسؤولية |
|---|---|
| `ContentExtractionService` | استخراج النص من PDF (smalot/pdfparser) والصفحات المصوّرة |
| `AiTeacherService` | الشرح والتلخيص المُسنَد إلى مصدر الكتاب (grounded) |
| `ExerciseService` | تصحيح التمارين: حساب عددي حقيقي + مطابقة إجابات مرجعية (يدعم الأرقام العربية) |
| `GamificationService` | النقاط والنجوم والشارات وسجل اللعب |
| `MathQuestionBank` | توليد أسئلة الرياضيات إجرائيًا حسب المرحلة (أرقام عربية ٠-٩) للألعاب |
| `GameBank` | توليد لعبة قابلة للعب لكل مادة (رياضيات/علوم/إنجليزي/استرجاع الدروس) |
| `EnglishGlossary` | قراءة الكلمات الإنجليزية من الكتاب وترجمتها للعربية (Hello — مرحبًا) |
| `TextbookExportService` | تصدير الكتاب/الصف كاملًا إلى PDF (mPDF) أو HTML للعمل دون إنترنت |
| `AudioService` | تجهيز نص القراءة الصوتية ومدة القراءة |
| `OfflineAiService` | بوابة واحدة لمزوّد سحابي اختياري؛ تُرجع بديلًا حتميًا عند غياب الاتصال |

### وكلاء الذكاء الاصطناعي (`app/Agents`)

كل مهمة ينفّذها **وكيل** له عقد واحد (`Agent`) وحالة موحّدة (`ok` / `needs_content` /
`error`). كل التشغيلات تُسجَّل في `agent_runs`. الوكلاء الستة الحاليون: استيراد الكتب،
بناء المنهج، شرح الدروس، حل التمارين، التسجيل الصوتي، ومراجعة المحتوى — **كلهم يعملون
بدون إنترنت**. التفاصيل الكاملة وخارطة الطريق (السيرة، القرآن، الحديث، الدعوة) في
[`docs/AI_AGENTS.md`](docs/AI_AGENTS.md).

## كيف نضمن أن الشرح من الكتاب الصحيح

هذه النقطة جوهرية في المشروع، وهي مطبّقة في الكود:

1. **إدخال الكتب المعتمدة**: رفع PDF أو صور الصفحات مع تحديد الصف والمادة والطبعة والفصل.
2. **استخراج المحتوى** مع الاحتفاظ برقم الصفحة (`textbook_pages.extracted_text`).
3. **مراجعة بشرية**: لكل صفحة `status` (raw / extracted / reviewed)، ومسار اعتماد
   للملخصات (`summaries.status = approved`).
4. **الاسترجاع من المصدر**: `AiTeacherService::explain()` يبني الشرح من نقاط الدرس
   المخزّنة فقط، ويعيد `grounded: true` و`pages: [..]` بأرقام صفحات المصدر.
5. **الفصل بين محتوى الكتاب والإضافات**: كل نقطة تحمل `is_from_textbook` و`source_page`.
6. **التحقق من الإجابات**: التمارين العددية تُصحّح بحساب فعلي (`ExerciseService`)،
   والإجابات المرجعية تُطابق نصيًا/عدديًا — لا يُترك الحكم للنموذج دون تحقق.
7. **إذا كان المحتوى غير معتمد**: يعيد النظام `needs_content: true` بدل اختراع شرح.

## قاعدة البيانات

المنهج هو مركز النظام. الجداول الأساسية (26+ جدولًا):

- **الحسابات**: `users` (بدور owner/admin/teacher/parent/student)، `student_profiles`،
  `parent_links`، `teacher_assignments`، `roles`.
- **المنهج**: `grades`، `subjects`، `textbooks`، `textbook_pages`، `lessons`،
  `lesson_sections`، `summaries`.
- **التقويم**: `exercises`، `exercise_options`، `attempts`، `learning_progress`.
- **التحفيز**: `game_activities`، `game_sessions`، `achievements`، `student_achievements`.
- **التشغيل**: `audio_assets`، `ai_usage_logs`، `settings`.

الصلاحيات مفروضة على الخادم عبر وسيط `EnsureRole` (`role:owner`، `role:student`، …)، وليس
في الواجهة فقط. الطالب لا يستطيع الوصول إلى نقاط نهاية المعلم أو المالك، ولا إلى بيانات طالب آخر.

### المنهج الدراسي الكامل (المرجع الرسمي)
يُحمَّل المنهج اليمني كاملًا (الصفوف 1–12: الأساسي والثانوي) من الملف المرجعي
`backend/database/data/yemeni_curriculum.json` — وهو منقول من فهرس المنهج الرسمي
(وزارة التربية والتعليم). يغطي:

- **12 صفًا**، **118 مادة**، **354 وحدة**، **1198 درسًا** موزّعة على المراحل.
- لكل درس: مصدر الصفحات، نقاط الشرح، ملخص معتمد، ومجموعة تمارين من محتوى الدرس نفسه.
- الصف الأول (المواد الأساسية) مؤلَّف بالكامل في `CurriculumSeeder`، وبقية الصفوف/المواد
  في `FullCurriculumSeeder`. كلاهما قابل لإعادة التشغيل دون تكرار.

### شرح بلغتين: عربي وإنجليزي
الشرح دائمًا بالعربية. وفي مواد **اللغة الإنجليزية** يقرأ المعلم الكلمات الإنجليزية من
الكتاب ثم يترجمها، مثل: «Hello — مرحبًا»، ويشمل ذلك القراءة الصوتية (تقرأ الكلمة
الإنجليزية بالإنجليزية ثم الترجمة بالعربية) وتمارين ترجمة.

### الألعاب التفاعلية في كل المواد
لكل مادة لعبة قابلة للعب (118 مادة → 123 لعبة)، مولَّدة من المنهج نفسه بحيث يكون كل سؤال
قابلًا للحل دون نموذج لغوي:

- **الرياضيات**: عمليات وأعداد وكسور وهندسة إجرائية (أرقام عربية ٠-٩).
- **العلوم/الفيزياء/الكيمياء/الأحياء**: أسئلة من دروس المادة.
- **الإنجليزية**: مفردات — الكلمة الإنجليزية ومعناها العربي.
- **بقية المواد** (إسلامية، عربي، اجتماعيات، حاسوب، رياضة، فنية…): استرجاع عناوين الدروس.

### تحميل الكتاب كاملًا (PDF أو نسخة بدون إنترنت)
يمكن تنزيل **الكتاب كاملًا** (كل الدروس: الشرح المُسنَد إلى صفحات الكتاب، الملخص،
التمارين مع الجواب وخطوات الحل) في ملف واحد — أو تنزيل **كتب الصف كاملًا** في ملف واحد:

| الطريقة | المسار |
|---|---|
| كتاب واحد (PDF) | `GET /textbooks/{id}/download` أو `?format=pdf` |
| كتاب واحد (HTML للعمل دون إنترنت) | `GET /textbooks/{id}/download?format=html` |
| كتب الصف كاملًا (PDF) | `GET /grades/{id}/download` |
| كتب الصف كاملًا (HTML) | `GET /grades/{id}/download?format=html` |

المستند يشمل **نصّ الكتاب نفسه** (الصفحات المُستخرجة من PDF/الصور، صفحةً صفحة) متى ما رُفعت،
مضافًا إليه الشرح المُسنَد إلى صفحات الكتاب، والملخص، والتمارين مع الجواب وخطوات الحل.
المحتوى نفسه المُسنَد إلى الكتاب، فلا يخترع النظام شيئًا. لإخراج الشرح والتمارين فقط دون
نصّ الصفحات أضف `?pages=0`. توليد PDF عبر `mPDF` (يدعم العربية واتجاه RTL)، وواجهة الطالب
فيها زرّا **تحميل الكتاب كامل** و**نسخة بدون إنترنت** على كل كتاب، وزر **تحميل كتب الصف كامل (PDF)**
أعلى صفحة «دروسي».

لتحديث المنهج: عدّل ملف JSON ثم `php artisan db:seed --class=FullCurriculumSeeder`.

## التشغيل السريع

```bash
# تهيئة كاملة (تثبيت PHP/MariaDB/Redis + إنشاء القاعدة + البذر + التشغيل)
bash scripts/bootstrap_env.sh

# لإعادة بذر المنهج من الصفر
FRESH=1 bash scripts/bootstrap_env.sh
```

الافتراضي:
- الواجهة البرمجية: `http://127.0.0.1:12000/api/v1`
- التطبيق: `http://127.0.0.1:12001/`

لتشغيله على نطاق عام بدّل العنوان:

```bash
API_PUBLIC_URL=https://api.example.ye bash scripts/run_demo.sh
```

## الحسابات التجريبية

| الدور | البريد | كلمة المرور |
|---|---|---|
| المالك / الإدارة (المعلم صفوت) | `owner@school.ye` | `password` |
| معلم | `teacher@school.ye` | `password` |
| ولي أمر | `parent@school.ye` | `password` |
| طالب | `student@school.ye` | `password` |

## واجهات API

| الطريقة | المسار | الوصف | الصلاحية |
|---|---|---|---|
| POST | `/auth/register` `/auth/login` `/auth/logout` | المصادقة | عام |
| GET | `/grades`، `/grades/{id}/subjects`، `/subjects/{id}/textbooks`، `/textbooks/{id}/lessons` | تصبط المنهج | عام |
| GET | `/lessons/{id}`، `/lessons/{id}/pages/{page}` | تفاصيل الدرس ومصدر صفحة | عام |
| POST | `/lessons/{id}/explain`، `/ask` | المعلم الذكي (مُسنَد للمصدر) | طالب+ |
| GET | `/lessons/{id}/summary` | الملخص المعتمد | طالب+ |
| POST | `/lessons/{id}/narrate` | نص القراءة الصوتية | طالب+ |
| GET | `/lessons/{id}/exercises` | تمارين الدرس | طالب+ |
| POST | `/exercises/{id}/submit`، GET `/hint` `/solution` | التحقق/التلميح/الحل | طالب+ |
| GET | `/games`، POST `/games/{id}/complete` | الألعاب والنتائج | طالب |
| GET | `/progress`، `/achievements` | التقدم ونقاط الضعف | طالب |
| GET | `/parent/dashboard`، POST `/parent/link-child` | متابعة الأبناء | ولي أمر |
| GET | `/textbooks/{id}/pages` | عرض صفحات الكتاب وحالة كل صفحة (خام/مستخرج/مُراجَع) | معلم/مالك |
| PATCH | `/pages/{id}` | تحرير نص الصفحة المستخرج واعتمادها | معلم/مالك |
| POST | `/textbooks`، `/textbooks/{id}/upload-pdf`، `/upload-page`، `/draft-lessons`، `/publish`، `/lessons/{id}/generate-summary`، `/summaries/{id}/approve`، `/exercises/{id}/verify` | إدارة المحتوى | معلم/مالك |
| GET | `/agents`، `/agents/targets`، `/agents/runs`، POST `/agents/{key}/run` | وكلاء الذكاء الاصطناعي (شرح/حل/سرد متاح للطالب، والاستيراد والمنهج والمراجعة للمعلم+ ) | طالب+ |
| GET | `/owner/overview`، `/owner/users`، `/owner/ai-usage`، POST `/owner/assign-role` | الإدارة | مالك |

## الاختبارات

```bash
cd backend && php artisan test
```

تغطي الاختبارات: المصادقة وفرض الأدوار، المنهج ومصادر الصفحات، إسناد شرح المعلم الذكي
(ومنع الاختراع)، تصحيح التمارين (بما فيها الأرقام العربية)، الألعاب والنقاط، لوحة ولي
الأمر، وإدارة المحتوى، وطبقة الوكلاء (التسجيل، الجاهزية بدون إنترنت، منع الاختراع،
وفرض صلاحيات الوكلاء).

## لوحات التحكم

- **الطالب**: دروسي (اختيار صف/مادة/كتاب/درس)، المعلم الصوتي، التمارين، الألعاب،
  الإنجازات، ونقاط الضعف.
- **ولي الأمر**: وقت التعلم، الدروس المتقنة، متوسط الإتقان، الأخطاء المتكررة، وتوصيات منزلية.
- **المعلم/المشرف**: إدارة الكتب والدروس، رفع الصفحات، توليد الملخصات واعتمادها،
  اكتشاف الدروس الصعبة.
- **المالك/الإدارة**: المستخدمون والأدوار، نظرة عامة، وتكاليف/سجل استخدام الذكاء الاصطناعي.

## خطة التنفيذ

- **المرحلة 1 — المنهج والكتب** ✅: قاعدة البيانات، الصفوف والمواد والكتب، رفع الصفحات،
  ربط كل درس بمصدره.
- **المرحلة 2 — المعلم الذكي** ✅: التلخيص، شرح النقاط، حل التمارين خطوة بخطوة، التلميحات والتحقق.
- **المرحلة 3 — الصوت** ✅ (نص القراءة + النطق في الواجهة عبر Web Speech API).
- **المرحلة 4 — ألعاب الابتدائي** ✅ (رياضيات/علوم/قراءة/تصنيف، مستويات، تغذية راجعة فورية).
- **المرحلة 5 — الاختبار والنشر** 🔄: اختبارات آلية ✔، اختبار الصلاحيات ✔، يتبقى:
  دمج محرك TTS عربي على الخادم، ونشر الإنتاج.

### مسار استخراج الكتاب واعتماده (جاهز للاستخدام الآن)
1. **إنشاء كتاب** لصف ومادة: `POST /textbooks`.
2. **رفع المصدر**: PDF (`POST /textbooks/{id}/upload-pdf` — يستخرج النص لكل صفحة) أو صور
   صفحات (`/upload-page`).
3. **مراجعة الصفحات**: لوحة «مراجعة الصفحات» تعرض كل صفحة وحالتها، وتتيح تحرير النص
   واعتماده (`GET /textbooks/{id}/pages` + `PATCH /pages/{id}`).
4. **توليد مسودات الدروس** من الصفحات (`/draft-lessons`)، **توليد الملخص** واعتماده
   (`/generate-summary` + `/summaries/{id}/approve`) الذي ينشر الدرس.
5. **النشر**: `POST /textbooks/{id}/publish`.
كل خطوة تحفظ رقم الصفحة المصدر، ولا يُقدَّم أي محتوى غير موجود في المصدر.

---

## النشر (الواجهة + الـ API)

المنصة منشورة على جزأين:

- **واجهة الويب** (موقع ثابت): GitHub Pages من فرع `gh-pages`
  → <https://safwt771754091s-crypto.github.io/Teacher-Safwat-/>
- **الـ API** (Laravel + قاعدة بيانات): خدمة دائمة عبر Docker على Render، وقاعدة
  البيانات **Supabase PostgreSQL**.

> ملاحظة: يمكن تشغيل الـ API من هذه البيئة مباشرةً؛ الواجهة المنشورة على GitHub Pages
> تشير إليه عبر `PROD_API_ORIGIN` في `web/assets/config.js`. للاستقلال الكامل انشر
> الـ API على Render كما هو موضح أدناه ثم حدّث `PROD_API_ORIGIN` إلى رابط الخدمة.

### 1) قاعدة البيانات (Supabase PostgreSQL)

أنشئ مشروع Supabase، ثم خُذ رابط **Session pooler** (متوافق مع IPv4):

```
postgresql://postgres.<ref>:<password>@aws-0-<region>.pooler.supabase.com:5432/postgres
```

المنصة تدعم `DB_URL` مباشرةً (يُستخدم تلقائيًا عند ضبطه). عند الإقلاع يشغّل الـ API
الترحيلات والزرع تلقائيًا (`backend/docker/entrypoint.sh`). للتحقق من الاتصال والمخطط:

```bash
php artisan platform:db-status
```

### 2) نشر الـ API دائمًا (Render)

المستودع يحتوي `render.yaml` (Blueprint) و`backend/Dockerfile`. اضغط زر النشر ثم اربط
المستودع؛ سيبني Render صورة Docker تثبّت PHP 8.4 و`pdo_pgsql`، وتشغّل الترحيل والزرع
عند الإقلاع. صحة الخدمة تُفحص على `/api/v1/health`.

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy)

**متغيرات البيئة المطلوبة** (تُضبط في لوحة Render، ولا تُحفظ في المستودع):

| المتغير | الوصف |
|---|---|
| `DB_URL` | رابط Supabase Session pooler أعلاه |
| `APP_KEY` | ناتج `php artisan key:generate --show` |
| `OWNER_EMAIL` / `OWNER_PASSWORD` | بيانات المالك الأول (يُنشأ تلقائيًا عند الإقلاع) |
| `CORS_ALLOWED_ORIGINS` | أصل الواجهة، مثل `https://safwt771754091s-crypto.github.io` |
| `LLM_PROVIDER` / `LLM_API_KEY` / `LLM_MODEL` | اختياري: لتشغيل المعلم الذكي على نموذج لغوي |

> الأمان: لا تُزرع الحسابات التجريبية في بيئة الإنتاج (`APP_ENV=production`)، ويُنشأ
> المالك فقط من `OWNER_EMAIL`/`OWNER_PASSWORD` عبر الأمر `platform:bootstrap-owner`.

### 3) ربط الواجهة بالـ API وإعادة نشرها

بعد ظهور رابط خدمة Render (مثل `https://yemeni-smart-teacher-api.onrender.com`)، اضبطه
في `web/assets/config.js` داخل الثابت `PROD_API_ORIGIN`، ثم أعِد نشر فرع `gh-pages`:

```bash
git worktree add --detach /tmp/ghpages
cd /tmp/ghpages && git checkout --orphan gh-pages
find . -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -r /workspace/project/web/. .
git add -A && git commit -m "deploy(web): point at permanent API" && git push origin gh-pages
```

> بديل: أي مستضيف Docker يدعم PHP (Railway / Fly.io / VPS). على خطة Render المجانية تنام
> الخدمة بعد فترة خمول وتستيقظ عند أول طلب. للتحقق من الاتصال: `php artisan platform:db-status`.


---

## English summary

**Yemeni Smart Teacher** is a curriculum-centric Arabic (RTL) learning platform. A student
picks grade → subject → lesson; the platform grounds every explanation, summary, and
exercise answer in the *actual textbook* (stored per page), rather than model memory. It
ships a Laravel 13 REST API (Sanctum roles: owner/admin/teacher/parent/student), a
dependency-free responsive RTL web app, an exercise engine that verifies numeric answers
by real arithmetic and reference answers (Arabic-Indic digits supported), gamification, an
audio/read-aloud layer, and parent/teacher/owner dashboards. The **entire Yemeni
curriculum** (grades 1–12, all official subjects) is seeded. Platform owner/branding is
configured via `config/platform.php` (owner = المعلم صفوت). Run `bash scripts/bootstrap_env.sh`.
