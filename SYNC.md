# sync به نصب محلی (XAMPP)

مسیر نصب هدف روی سیستم تو:

```
C:\xampp\htdocs\wordpress\wp-content\plugins\infinite-scroll-gallery-V1-patched-fBtdMsMxW4Lo4qe4
```

من به فایل‌سیستم ویندوز تو دسترسی ندارم، پس **هر تغییری** که می‌دهم اول اینجا (در همین ریپو)
انجام می‌شود و بعد با یکی از دو روش زیر به همان پوشه می‌رسد.

## نگاشت مسیرها (Repo → نصب تو)

repo دقیقاً همان ساختار داخل پوشهٔ افزونه است، پس هر فایل همین مسیر نسبی را در نصب تو دارد:

| در این ریپو | در نصب تو |
|---|---|
| `infinite-scroll-gallery-for-elementor.php` | `...\infinite-scroll-gallery-V1-patched-fBtdMsMxW4Lo4qe4\infinite-scroll-gallery-for-elementor.php` |
| `includes\class-gallery-widget.php` | `...\infinite-scroll-gallery-V1-patched-fBtdMsMxW4Lo4qe4\includes\class-gallery-widget.php` |
| `assets\js\ifg.js` | `...\infinite-scroll-gallery-V1-patched-fBtdMsMxW4Lo4qe4\assets\js\ifg.js` |
| `assets\css\ifg.css` | `...\infinite-scroll-gallery-V1-patched-fBtdMsMxW4Lo4qe4\assets\css\ifg.css` |
| `assets\js\vendor\*.min.js` | `...\infinite-scroll-gallery-V1-patched-fBtdMsMxW4Lo4qe4\assets\js\vendor\*.min.js` |

## روش ۱ — سریع (فقط فایل‌های تغییرکرده)

فایلی که تغییر کرده را از ریپو بگیر و همان مسیر را در پوشهٔ بالا overwrite کن.
چون `IFG_VERSION` (نسخهٔ افزونه) در `infinite-scroll-gallery-for-elementor.php` تعریف شده،
اگر مقدار نسخه عوض شود، مرورگرها خودشان نسخهٔ جدید JS/CSS را می‌گیرند و مشکل کش نداری.

## روش ۲ — ZIP آمادهٔ نصب

```bash
./build.sh
```

خروجی: `release/infinite-scroll-gallery-V1-patched-fBtdMsMxW4Lo4qe4.zip`

این ZIP یک پوشهٔ هم‌نام با نصب فعلی تو دارد. آن را در
`C:\xampp\htdocs\wordpress\wp-content\plugins\` اکسترکت کن و «جایگزینی فایل‌ها» را بزن.
نام پوشه عمداً تغییر نمی‌کند تا وردپرس آن را یک افزونهٔ جدید حساب نکند و تنظیمات ویجت‌های
ذخیره‌شده‌ات (Elementor) از دست نرود.

## بعد از هر sync

1. `wp-content/plugins` → افزونه را یک‌بار Deactivate/Activate کن (اگر `IFG_VERSION` عوض شده).
2. کش المنتور: `Elementor → Tools → Regenerate CSS & Data`.
3. در مرورگر با `Ctrl+F5` صفحه را رفرش کن (کش JS/CSS).
