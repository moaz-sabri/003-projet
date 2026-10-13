# القالب التجاري الخفيف

> قالب ويب تجاري مستقل، قابل للتثبيت كتطبيق PWA، بواجهة عربية كاملة (RTL)،
> ولوحة إدارة لمدير واحد، وقاعدة بيانات SQLite واحدة.
> يبدأ كنموذج لمطعم/مقهى، ويمكن إعادة استخدامه لمعرض سيارات أو متجر أو أي نشاط
> مشابه بتعديل كلاس إعدادات واحد.

الإصدار 1.0 · PHP 8.1+ · SQLite · PWA · بلا مكتبات خارجية · بلا اشتراكات

---

## المحتويات

1. [ما الذي يقدّمه هذا القالب](#1-ما-الذي-يقدمه-هذا-القالب)
2. [التشغيل السريع](#2-التشغيل-السريع)
3. [النشر على استضافة عادية](#3-النشر-على-استضافة-عادية)
4. [بنية المشروع](#4-بنية-المشروع)
5. [تخصيص القالب لمشروع آخر](#5-تخصيص-القالب-لمشروع-آخر)
6. [الأمان](#6-الأمان)
7. [النسخ الاحتياطي والاستعادة](#7-النسخ-الاحتياطي-والاستعادة)
8. [الأوامر المتاحة](#8-الأوامر-المتاحة)
9. [واجهة الـ API](#9-واجهة-الـ-api)
10. [الاختبارات](#10-الاختبارات)
11. [المشاكل الشائعة](#11-المشاكل-الشائعة)

---

## 1. ما الذي يقدّمه هذا القالب

### للزبون

- قائمة منتجات بالتصنيفات، بحث بسيط، وصفحة تفاصيل عند الحاجة فقط.
- سلة بأسعار وكميات، وطلب باسم بسيط — **بلا حساب وبلا كلمة مرور**.
- تحديد الموقع من رمز QR، أو اختياره يدويًا إن لم يكن QR مفعّلًا.
- تطبيق PWA قابل للتثبيت على الهاتف.
- **تصفح يعمل دون اتصال** للمنتجات التي جرت تحميلها، مع توضيح أن البيانات قديمة.
- **لا يمكن إرسال الطلب دون اتصال**، ولا يظهر أي تأكيد قبل حفظ الطلب فعليًا على الخادم.

### للمدير

- لوحة إدارة واحدة خفيفة: الرئيسية، التصنيفات، المنتجات، الطلبات، المواقع ورموز QR، إعدادات العرض.
- توليد رموز QR للمواقع وللطلبات محليًا (بدون أي خدمة خارجية مدفوعة).
- صفحة طباعة جاهزة لكل رموز المواقع.
- تحديث دوري للطلبات الجديدة (بدون WebSocket).
- نسخة احتياطية بضغطة واحدة، وتنزيلها من اللوحة.
- تغيير كلمة المرور، وتعديل الاسم والشعار ورسالة الترحيب.

### للمطوّر

- بدون Composer، بلا npm، بلا خطوة بناء — انسخ الملفات وارفعها.
- مولّد رموز QR كامل بلغة PHP خالصة (ISO/IEC 18004، مع Reed–Solomon).
- مولّد أيقونات PWA بصيغة PNG بلا GD (كتابة PNG يدوية).
- قاعدة بيانات SQLite واحدة بمعاملات واستعلامات محضّرة.
- 190 اختبارًا آليًا تغطي معايير القبول (`php tests/run.php`).

---

## 2. التشغيل السريع

### أ) بالطرفية (محليًا)

```bash
# 1) تهيئة النسخة (إنشاء قاعدة البيانات + حساب المدير)
php bin/console install --url=http://localhost:8000 --user=admin --pass="كلمة_مرور_قوية"

# 2) توليد أيقونات التطبيق
php bin/console icons

# 3) (اختياري) بيانات تجريبية للتجربة السريعة
php bin/console seed:demo

# 4) تشغيل خادم محلي (مع راوتر يحمي المسارات الحساسة)
php -S localhost:8000 bin/serve.php
```

> **مهم:** استخدم `bin/serve.php` دائمًا ولا تستخدم `-t .` مباشرةً.
> خادم PHP المدمج لا يقرأ `.htaccess`، لذا يطبّق الراوتر نفس قواعد الحماية برمجيًا:
> منع الوصول إلى `src/` و `storage/` و `bin/` و `tests/`، ومنع تنفيذ PHP داخل `app/uploads/`.
> بدون الراوتر يصبح ملف `storage/config/local.php` (وفيه تجزئة كلمة مرور المدير) قابلًا للقراءة عبر المتصفح.

ثم افتح:

| الوجهة | الرابط |
| --- | --- |
| واجهة الزبون | <http://localhost:8000/app/index.html> |
| لوحة الإدارة | <http://localhost:8000/admin/> |

### ب) مع Docker

```bash
docker compose up -d --build

# تهيئة النسخة
docker compose exec app php bin/console install \
    --url=http://localhost:8080 --user=admin --pass="كلمة_مرور_قوية"

# توليد الأيقونات
docker compose exec app php bin/console icons
```

ثم افتح <http://localhost:8080/setup/> أو روابط المشروع على المنفذ 8080.

> Docker للتطوير فقط. بيئة الإنتاج لا تحتاجه إطلاقًا.

### ج) بدون وصول للطرفية (استضافة عادية)

ارفع الملفات كما هي، ثم افتح:

```
https://your-site.com/setup/
```

ستظهر صفحة تهيئة واحدة تطلب رابط الموقع واسم المستخدم وكلمة المرور،
وتُنشئ قاعدة البيانات والأيقونات تلقائيًا، ثم تتوقف عن العمل.
**احذف مجلد `setup/` بعد التهيئة.**

---

## 3. النشر على استضافة عادية

### المتطلبات

| المتطلب | الحد الأدنى | الملاحظة |
| --- | --- | --- |
| PHP | 8.1 | مُختبَر على 8.4 |
| SQLite3 + PDO | أي إصدار حديث | `pdo_sqlite` |
| mbstring | — | للنصوص العربية |
| GD (اختياري) | — | لتصغير الصور وإعادة ترميزها |
| fileinfo (اختياري) | — | للتحقق من الصور |

### الخطوات

1. **ارفع كل الملفات** إلى جذر الموقع (أو مجلد فرعي — القالب يدعم ذلك).
2. **اضبط الصلاحيات**:

   ```bash
   chmod 755 .
   chmod -R 775 storage
   chmod 775 storage/config
   ```

3. **افتح صفحة التهيئة**: `https://your-site.com/setup/` ثم احذف مجلد `setup/`.
   أو نفّذ محليًا: `php bin/console install --url=https://your-site.com --user=admin --pass=...`
4. **اضبط رابط الموقع** في `storage/config/local.php` (يُستخدم في رموز QR):

   ```php
   'app' => ['base_url' => 'https://your-site.com'],
   ```

5. **تأكد من الحماية**: إن كان خادمك Apache، ملفات `.htaccess` المرفقة تحجب
   `src/` و `storage/` و `bin/` و `tests/` وتمنع تنفيذ أي سكربت داخل `app/uploads/`.
   إن كنت تستخدم Nginx، استعمل الإعداد المرفق في `docker/nginx.conf` كمرجع.
6. **فعّل HTTPS** — الجلسة والكوكيز تعمل بشكل صحيح مع `Secure` فقط عبر HTTPS.

> **مهم:** لا يمكن تشغيل النسخة دون امتداد `pdo_sqlite`. تحقّق من ذلك أولًا:
> `php -m | grep pdo_sqlite`

---

## 4. بنية المشروع

```
project/
├── sw.js                     Service Worker على جذر الموقع (ليشمل /api/ أيضًا)
│
├── app/                     واجهة الزبون (PWA)
│   ├── index.html           هيكل الصفحة (SPA بسيطة)
│   ├── manifest.webmanifest بيان التطبيق
│   ├── assets/icons/        أيقونات التطبيق (مولّدة)
│   ├── css/app.css
│   ├── js/                  api.js · store.js · ui.js · app.js
│   └── uploads/             الصور المرفوعة (يُمنع تنفيذ أي سكربت هنا)
│
├── admin/                   لوحة الإدارة
│   ├── index.php            غلاف PHP (جلسة + CSP + إعدادات)
│   └── assets/              admin.css · admin.js
│
├── api/                     واجهة JSON
│   ├── index.php            نقطة الدخول الوحيدة
│   └── routes/
│       ├── public.php       مسارات الزبون
│       └── admin.php        مسارات الإدارة (تتطلب جلسة + CSRF)
│
├── src/                     كود الخادم (غير متاح عبر الويب)
│   ├── Config/ProjectConfig.php     ★ كل التخصيص البنيوي هنا
│   ├── Core/                قاعدة البيانات · HTTP · الأمان · QR · المال
│   ├── Repositories/        الوصول للبيانات
│   ├── Services/            منطق الطلبات والكتالوج و QR والنسخ الاحتياطي
│   ├── Tools/               مولّد الأيقونات والبيانات التجريبية
│   └── bootstrap.php
│
├── storage/                 (غير متاح عبر الويب — محمي بـ .htaccess)
│   ├── config/local.php     ★ أسرار النسخة (لا يُرفع إلى Git)
│   ├── database/app.sqlite  قاعدة البيانات
│   ├── uploads/  backups/  logs/  tmp/
│
├── tests/                   190 اختبارًا
│   ├── run.php  Assert.php
│   └── suites/              orders · security · catalog · pwa
│
├── setup/index.php          معالج التهيئة (يُحذف بعد الاستخدام)
├── bin/console              أوامر الصيانة
├── docker/                  إعدادات nginx و php.ini و fpm
├── Dockerfile · compose.yaml
└── README.md
```

### طبقة البيانات

| الجدول | المحتوى |
| --- | --- |
| `settings` | إعدادات العرض القابلة للتعديل من اللوحة |
| `categories` | التصنيفات وترتيبها وحالتها |
| `products` | المنتجات والأسعار والصور والحقول الإضافية (JSON) |
| `locations` | المواقع (طاولات/معارض) ورموزها |
| `orders` | الطلبات: الرقم، الزبون، الموقع، الحالة، الإجمالي، مفتاح التكرار |
| `order_items` | عناصر الطلب مع **لقطة** الاسم والسعر وقت الطلب |
| `order_events` | سجل تغييرات الحالة |
| `daily_counters` | ترقيم الطلبات لكل يوم |
| `login_attempts` | محاولات تسجيل الدخول (لتقييد المحاولات) |

> كل الأسعار تُخزَّن كأعداد صحيحة (`price_minor`) لتفادي أخطاء الفاصلة العائمة،
> وكل التواريخ تُكتب بتوقيت النسخة عبر `Database::now()` (لا UTC).

---

## 5. تخصيص القالب لمشروع آخر

كل التخصيص البنيوي في ملف واحد: **`src/Config/ProjectConfig.php`**.
لا يوجد محرر حقول في لوحة الإدارة — تعديل البنية = تعديل الكود = إصدار نسخة جديدة.

### مثال: تحويل القالب إلى معرض سيارات مستعملة

```php
// أ. الهوية
public const DEFAULT_APP_NAME  = 'معرض النور';
public const DEFAULT_APP_TAGLINE = 'سيارات مستعملة بضمان';
public const CURRENCY           = 'ر.س';

// ب. نوع النشاط والمواقع
public const BUSINESS_TYPE     = 'car_dealer';
public const LOCATION_SINGULAR  = 'سيارة';
public const LOCATION_PLURAL    = 'سيارات';
public const LOCATION_REQUIRED  = false;      // الطلب لا يحتاج مركبة محددة
public const LOCATION_SOURCE    = 'qr_or_picker';

// ج. حقول المنتج
public const PRODUCT_EXTRA_FIELDS = [
    ['key' => 'year',       'label' => 'سنة الصنع',  'type' => 'number', 'show_in_card' => true],
    ['key' => 'mileage',    'label' => 'الممشى',    'type' => 'text',   'show_in_card' => true],
    ['key' => 'transmission','label'=> 'ناقل الحركة','type' => 'select', 'options' => [
        ['value' => 'auto', 'label' => 'أوتوماتيك'],
        ['value' => 'manual', 'label' => 'يدوي'],
    ]],
    ['key' => 'fuel',       'label' => 'الوقود',     'type' => 'text'],
];

// د. أنواع الطلبات
public const ORDER_TYPES = [
    'test_drive' => [
        'label' => 'حجز تجربة قيادة',
        'requires_location' => false,
        'default' => true,
        'fields' => [
            ['key' => 'preferred_date', 'label' => 'التاريخ المناسب', 'type' => 'text', 'required' => true],
            ['key' => 'customer_phone', 'label' => 'رقم الهاتف', 'type' => 'tel', 'required' => true],
        ],
    ],
];

// هـ. عند تغيير أسماء الحقول، احذف قاعدة البيانات القديمة وأعد التهيئة:
php bin/console migrate:fresh
```

> **القاعدة:** بعد أي تعديل بنيوي في `ProjectConfig` نفّذ
> `php bin/console migrate:fresh` لأن أسماء الحقول تتغيّر في جداول JSON.

---

## 6. الأمان

| المجال | الإجراء المطبَّق |
| --- | --- |
| كلمة مرور المدير | `password_hash()` (bcrypt/argon) في `storage/config/local.php` خارج المسارات العامة |
| الجلسات | كوكي `HttpOnly` + `SameSite=Strict` + `Secure` (مع HTTPS) + تجديد المعرّف بعد الدخول + انتهاء خمول ٢ ساعة |
| محاولات الدخول | قفل بعد 6 محاولات فاشلة لمدة 15 دقيقة |
| CSRF | رمز مرتبط بالجلسة مطلوب في كل عملية تعديل |
| SQL Injection | استعلامات محضّرة (PDO) في كل مكان |
| XSS | إخراج نصي مُهرَّر في الواجهتين + `strip_tags` على مدخلات الزبون |
| تلاعب بالأسعار | الخادم يحسب الإجمالي من قاعدة البيانات ويتجاهل أي سعر يرسله العميل |
| رفع الملفات | فحص النوع الحقيقي عبر `getimagesize` + حجم + أبعاد + اسم عشوائي + منع التنفيذ |
| الوصول للملفات | `.htaccess` / nginx يمنع `src/` و `storage/` و `bin/` و `tests/` وتنفيذ PHP داخل الرفع |
| حماية المتصفح | CSPصارمة للوحة الإدارة + `nosniff` + `frame-ancestors 'none'` |
| الطلبات | حد ٢٠ طلب/دقيقة لكل عنوان IP + رفض.location غير صالح على الخادم |
| تكرار الطلبات | مفتاح `Idempotency-Key` + قيد فريد في قاعدة البيانات |
| رموز المتابعة | 32 محرفًا عشوائيًا من `random_bytes` + صلاحية محدودة زمنيًا |
| الأسرار | مستثناة في `.gitignore` — لا تُرفع أبدًا |

### التحقق السريع من الحماية

```bash
# يجب أن يعيد 404
curl -I https://your-site.com/storage/database/app.sqlite
curl -I https://your-site.com/src/Config/ProjectConfig.php
curl -I https://your-site.com/app/uploads/test.php
```

---

## 7. النسخ الاحتياطي والاستعادة

### من لوحة الإدارة

**الأمان والنسخ ← إنشاء نسخة الآن** ثم **تنزيل**.

### من الطرفية

```bash
php bin/console backup      # ينشئ storage/backups/backup-YYYYmmdd-HHMMSS.sqlite
```

تُنشأ النسخة عبر `VACUUM INTO`، أي نسخة سليمة حتى أثناء العمل.
صلاحيات الملف `600` والمجلد محمي من الوصول عبر الويب.

### الاستعادة

1. أوقف الاستقبال مؤقتًا (اختياري).
2. انسخ ملف النسخة إلى `storage/database/app.sqlite`.
3. احذف `app.sqlite-wal` و `app.sqlite-shm` إن وُجدا.
4. أعد التشغيل.

نصيحة: اجعل نسخة يومية تلقائية عبر Cron:

```cron
0 3 * * * cd /path/to/project && php bin/console backup > /dev/null
```

---

## 8. الأوامر المتاحة

```bash
php bin/console install --url=... --user=... --pass=...   تهيئة النسخة لأول مرة
php bin/console migrate                                     تحديث مخطط قاعدة البيانات
php bin/console migrate:fresh                               حذف كل البيانات وإعادة الإنشاء
php bin/console admin:password --pass=...                   تعيين كلمة مرور المدير
php bin/console backup                                      نسخة احتياطية الآن
php bin/console icons                                       توليد أيقونات PWA
php bin/console qr "نص" --out=file.svg                      توليد رمز QR
php bin/console seed:demo                                   بيانات تجريبية
php bin/console status                                      حالة النسخة
php tests/run.php                                           تشغيل الاختبارات
```

### أدوات الفحص والمراجعة

جميعها تعمل بلا اعتماديات، وكلها تعطي رقم نجاح/فشل:

```bash
php tests/run.php                              # 223 اختبار وحدة وتكامل
php tests/http/check.php <url>                 # 32 فحص نشر على نسخة منشورة
php tools/workflow-test.php <url>              # 67 خطوة سير عمل كامل (يحفظ كوكي)
php tools/security-probe.php <url>             # 49 فحصًا أمنيًا حيًّا
php tools/code-review.php                      # 25 مراجعة كود وأداء وقاعدة بيانات
php tools/restore-test.php                     # 13 فحص نسخ احتياطي واستعادة
php tools/clean-install-test.php               # تثبيت نظيف من الصفر ثم فحص كامل
php tools/audit-backup.php                     # نسخة احتياطية كاملة قبل أي تعديل
```

> **تنبيه:** شغّل `security-probe.php` على خادم تجريبي فقط —
> الأداة تُرسل محاولات دخول فاشلة عمدًا فتُفعّل قفل المحاولات لـ 15 دقيقة.

### توثيق الإصدار

| الملف | المحتوى |
|-------|---------|
| `AUDIT_REPORT.md` | تقرير المراجعة الكامل، الثغرات، وقائمة التجميد |
| `CHANGELOG.md` | سجل التغييرات بين النسخ |
| `docs/SPEC_v1.md` | وثيقة المواصفات الأصلية المعتمدة |
| `.env.example` | مرجع مفاتيح الإعدادات (المشروع لا يقرأ `.env`) |

---

## 9. واجهة الـ API

كل المسارات على `api/index.php?r=<المسار>`، وتعيد دائمًا:

```json
{ "ok": true, "data": { } }
{ "ok": false, "error": { "code": "نطاق_المشكلة", "message": "رسالة عربية" } }
```

### مسارات عامة

| المسار | الطريقة | الوظيفة |
| --- | --- | --- |
| `catalog.snapshot` | GET | الإعدادات + التصنيفات + المنتجات + المواقع (يُخزَّن للعمل دون اتصال) |
| `product.get&id=` | GET | تفاصيل منتج |
| `locations.list` | GET | المواقع النشطة |
| `order.create` | POST | إنشاء طلب (يتطلب `Idempotency-Key`) |
| `order.status&token=` | GET | متابعة حالة طلب (قراءة فقط) |
| `ping` | GET | فحص التشغيل |

**مثال إنشاء طلب:**

```bash
curl -X POST "https://your-site.com/api/index.php?r=order.create" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 4f2c9a11-6b1e-4c2f-9f11-2a0f2b3c4d5e" \
  -d '{
        "order_type": "dine_in",
        "customer_name": "أبو علي",
        "location_code": "T05",
        "fields": { "notes": "بدون بصل" },
        "items": [ { "product_id": 3, "quantity": 2 } ]
      }'
```

> `items` لا تحتوي أي سعر — الخادم هو المرجع الوحيد للأسعار.
> إعادة الإرسال بنفس `Idempotency-Key` تعيد الطلب نفسه دون تكرار.

### مسارات الإدارة

كلها تتطلب جلسة صالحة، وكل عملية تعديل تتطلب ترويسة `X-CSRF-Token`:

`admin.login` · `admin.logout` · `admin.session` · `admin.password` ·
`admin.dashboard` · `admin.orders.active` · `admin.orders` · `admin.order` ·
`admin.order.status` · `admin.order.qr` · `admin.qr` · `admin.qr.sheet` ·
`admin.categories` · `admin.category.create|update|delete` · `admin.categories.reorder` ·
`admin.products` · `admin.product.create|update|delete|image` ·
`admin.locations` · `admin.location.save|delete` · `admin.settings` ·
`admin.settings.update|export` · `admin.backups` · `admin.backup.create|download`

---

## 10. الاختبارات

```bash
php tests/run.php            # كل الاختبارات
php tests/run.php orders     # مجموعة واحدة
```

تعمل على قاعدة بيانات مؤقتة منفصلة ولا تمس بيانات النسخة.

| المجموعة | التغطية |
| --- | --- |
| `orders` | استقلال الطلبات، إلزام الموقع على الخادم، منع التكرار، لقطة الأسعار، قواعد الانتقالات، التحقق من المدخلات |
| `security` | تجزئة كلمة المرور، مقاومة التوقيت، CSRF، تقييد المحاولات، حقن SQL، رفع الملفات، رموز المتابعة، حماية المسارات |
| `catalog` | الكتالوج، صفحة التفاصيل، البحث، حساب المال، تعريفات البنية |
| `pwa` | المانيفست، Service Worker، عدم تخزين بيانات حساسة، بنية المشروع، ترويسات الأمان |

---

## 11. المشاكل الشائعة

**الصفحة تحوّلك إلى `setup`**
ملف `storage/config/local.php` غير موجود. نفّذ `php bin/console install`
أو افتح `setup/index.php`.

**"حدث خطأ في الخادم" بعد التحديث**
نفّذ `php bin/console migrate` — قد يكون مخطط قاعدة البيانات قديماً.

**رموز QR لا تعمل**
تأكد أن `base_url` في `storage/config/local.php` هو رابطك الحقيقيPUBLIC
(https ونطاق صحيح). جرّب الرابط المكتوب تحت الرمز في اللوحة.

**الصور لا تظهر**
تأكد أن `app/uploads/` قابلة للكتابة (775) وأن الخادم يسمح بعرض PNG/JPG.

**الطلبات لا تصل إلى اللوحة**
تأكد أن الطلب يُرسل عبر HTTPS وأن الخادم لا يحجب `api/index.php`،
وأن صلاحية الكتابة على `storage/database/` متاحة.

**الترميز العربي مشوّه**
تأكد أن `default_charset = UTF-8` وأن كل ملفات المشروع محفوظة UTF-8 دون BOM.

**الزبون يرى رسالة "لا يوجد اتصال" مع وجود الإنترنت**
تعطّل الخادم مؤقتًا أو فيه خطأ في `api/index.php`. افحص
`storage/logs/app-YYYY-MM-DD.log`.

---

## حدود الإصدار الأول

خارج النطاق عمدًا: تعدد الشركات، حسابات الزبائن، صلاحيات متعددة، دفع إلكتروني،
محاسبة، مخزون متقدم، مزامنة طلبات دون اتصال، إشعارات لحظية، تطبيقات أصلية،
منشئ قوالب ديناميكي، تعدد قواعد البيانات.

---

## الترخيص والاستخدام

هذا القالب مخصص للاستخدام التجاري لكل نسخة مستقلة. لا اشتراكات إلزامية،
ولا خدمات خارجية مطلوبة لتشغيل الوظائف الأساسية — بما فيها توليد رموز QR
وتخزين البيانات والنسخ الاحتياطي.