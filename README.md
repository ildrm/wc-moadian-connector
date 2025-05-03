# WooCommerce Moadian Connector

**[English](#english) | [فارسی](#فارسی)**

## English

### Overview
The **WooCommerce Moadian Connector** is a WordPress plugin that seamlessly integrates WooCommerce with the Iranian Tax System (Samaneh Moadian). It automates the process of generating and submitting invoices to the Moadian system for tax compliance, making it easier for businesses in Iran to manage their tax obligations.

### Features
- **Automatic Invoice Submission**: Sends invoices to the Moadian system when WooCommerce orders are marked as completed.
- **Retry Failed Invoices**: Allows manual retry for invoices that failed to submit.
- **Secure Private Key Storage**: Encrypts and stores the Moadian private key (PEM format) securely.
- **Sandbox and Production Modes**: Supports both sandbox and production environments for testing and live use.
- **Admin Interface**: Provides a settings page under **Settings > Moadian Settings** and an invoices list under **WooCommerce > Moadian Invoices**.
- **Customizable Invoice Types**: Supports different invoice types (e.g., Standard and Simplified).
- **Debugging Support**: Logs errors to `debug.log` when `WP_DEBUG` is enabled for troubleshooting.

### Requirements
- WordPress 5.0 or higher
- WooCommerce 8.0 or higher (tested up to 9.6.0)
- PHP 7.2 or higher
- OpenSSL PHP extension for encryption
- A valid Moadian private key (PEM format) and economic code

### Installation
1. **Download the Plugin**:
   - Clone this repository or download the ZIP file from GitHub.
   - Alternatively, install it directly from the WordPress Plugin Directory (if published).

2. **Upload to WordPress**:
   - Navigate to **Plugins > Add New > Upload Plugin** in your WordPress admin panel.
   - Upload the ZIP file and click **Install Now**.

3. **Activate the Plugin**:
   - After installation, click **Activate** to enable the plugin.

4. **Configure Settings**:
   - Go to **Settings > Moadian Settings** in the WordPress admin panel.
   - Enter your Moadian private key, economic code, and select the environment (Sandbox or Production).
   - Save the settings.

### Usage
1. **Configure the Plugin**:
   - Access **Settings > Moadian Settings** to set up the environment, private key, economic code, and default invoice type.
2. **Automatic Invoice Submission**:
   - When an order is marked as **Completed** in WooCommerce, the plugin automatically generates and submits an invoice to the Moadian system.
3. **View and Manage Invoices**:
   - Go to **WooCommerce > Moadian Invoices** to view the list of submitted invoices, their statuses, and any error messages.
   - Use the **Retry** button to resubmit failed or pending invoices.
4. **Monitor Errors**:
   - Enable `WP_DEBUG` in `wp-config.php` to log errors to `wp-content/debug.log` for troubleshooting.

### Troubleshooting
- **Moadian Invoices Menu Not Visible**:
  - Ensure you have WooCommerce 8.0 or higher installed and activated.
  - Check for plugin conflicts by deactivating other plugins (especially ACF or WooCommerce-related plugins).
  - Switch to a default theme (e.g., Twenty Twenty-Three) to rule out theme issues.
  - Verify that the user has the `manage_options` capability.
  - Check `wp-content/debug.log` for errors prefixed with `[WC Moadian]`.
- **Authentication or Submission Errors**:
  - Verify that the private key and economic code are correct and properly formatted.
  - Ensure the server has internet access to connect to the Moadian API (`https://tp.tax.gov.ir` or sandbox).
  - Check the error messages in the **Moadian Invoices** page or `debug.log`.
- **General Issues**:
  - Clear browser and server caches (if using a caching plugin).
  - Test with a clean WordPress installation to isolate environmental issues.

### Contributing
Contributions are welcome! To contribute:
1. Fork this repository.
2. Create a new branch for your feature or bug fix (`git checkout -b feature/your-feature`).
3. Commit your changes (`git commit -m 'Add your feature'`).
4. Push to the branch (`git push origin feature/your-feature`).
5. Open a pull request with a detailed description of your changes.

Please ensure your code follows WordPress coding standards and includes appropriate documentation.

### License
This plugin is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

### Support
For support, please open an issue on the [GitHub repository](https://github.com/your-repo/woocommerce-moadian-connector) or contact the author at [ildrm.com](https://ildrm.com).

---

## فارسی

### معرفی
افزونه **WooCommerce Moadian Connector** یک پلاگین وردپرسی است که ووکامرس را با سامانه مودیان مالیاتی ایران ادغام می‌کند. این افزونه فرآیند تولید و ارسال فاکتورها به سامانه مودیان را برای رعایت الزامات مالیاتی خودکار می‌کند و به کسب‌وکارهای ایرانی کمک می‌کند تا تعهدات مالیاتی خود را به‌راحتی مدیریت کنند.

### ویژگی‌ها
- **ارسال خودکار فاکتور**: هنگام تکمیل سفارش‌ها در ووکامرس، فاکتورها به‌صورت خودکار به سامانه مودیان ارسال می‌شوند.
- **تلاش مجدد برای فاکتورهای ناموفق**: امکان ارسال مجدد دستی فاکتورهای ناموفق یا در انتظار.
- **ذخیره امن کلید خصوصی**: کلید خصوصی مودیان (فرمت PEM) را به‌صورت رمزنگاری‌شده ذخیره می‌کند.
- **حالت‌های آزمایشی و تولیدی**: پشتیبانی از محیط‌های سندباکس و تولیدی برای تست و استفاده واقعی.
- **رابط کاربری مدیریت**: صفحه تنظیمات در **تنظیمات > تنظیمات مودیان** و لیست فاکتورها در **ووکامرس > فاکتورهای مودیان**.
- **انواع فاکتور قابل تنظیم**: پشتیبانی از انواع مختلف فاکتور (مانند استاندارد و ساده‌شده).
- **پشتیبانی از دیباگ**: ثبت خطاها در `debug.log` هنگام فعال بودن `WP_DEBUG` برای عیب‌یابی.

### پیش‌نیازها
- وردپرس نسخه 5.0 یا بالاتر
- ووکامرس نسخه 8.0 یا بالاتر (تست‌شده تا نسخه 9.6.0)
- PHP نسخه 7.2 یا بالاتر
- افزونه OpenSSL در PHP برای رمزنگاری
- کلید خصوصی معتبر مودیان (فرمت PEM) و کد اقتصادی

### نصب
1. **دانلود افزونه**:
   - این مخزن را کلون کنید یا فایل ZIP را از GitHub دانلود کنید.
   - در صورت انتشار، می‌توانید آن را مستقیماً از مخزن افزونه‌های وردپرس نصب کنید.

2. **آپلود به وردپرس**:
   - در پنل مدیریت وردپرس به **افزونه‌ها > افزودن > بارگذاری افزونه** بروید.
   - فایل ZIP را آپلود کرده و روی **نصب** کلیک کنید.

3. **فعال‌سازی افزونه**:
   - پس از نصب، روی **فعال‌سازی** کلیک کنید.

4. **پیکربندی تنظیمات**:
   - به **تنظیمات > تنظیمات مودیان** در پنل مدیریت وردپرس بروید.
   - کلید خصوصی مودیان، کد اقتصادی و محیط (سندباکس یا تولیدی) را وارد کنید.
   - تنظیمات را ذخیره کنید.

### استفاده
1. **پیکربندی افزونه**:
   - به **تنظیمات > تنظیمات مودیان** بروید و محیط، کلید خصوصی، کد اقتصادی و نوع فاکتور پیش‌فرض را تنظیم کنید.
2. **ارسال خودکار فاکتور**:
   - هنگامی که یک سفارش در ووکامرس به وضعیت **تکمیل‌شده** تغییر کند، افزونه به‌صورت خودکار فاکتور را به سامانه مودیان ارسال می‌کند.
3. **مشاهده و مدیریت فاکتورها**:
   - به **ووکامرس > فاکتورهای مودیان** بروید تا لیست فاکتورهای ارسالی، وضعیت‌ها و پیام‌های خطا را مشاهده کنید.
   - از دکمه **تلاش مجدد** برای ارسال مجدد فاکتورهای ناموفق یا در انتظار استفاده کنید.
4. **نظارت بر خطاها**:
   - با فعال کردن `WP_DEBUG` در فایل `wp-config.php`، خطاها در `wp-content/debug.log` ثبت می‌شوند.

### عیب‌یابی
- **منوی فاکتورهای مودیان نمایش داده نمی‌شود**:
  - مطمئن شوید ووکامرس نسخه 8.0 یا بالاتر نصب و فعال است.
  - با غیرفعال کردن سایر افزونه‌ها (به‌ویژه ACF یا افزونه‌های مرتبط با ووکامرس) تداخل‌ها را بررسی کنید.
  - به یک پوسته پیش‌فرض (مانند Twenty Twenty-Three) تغییر دهید.
  - بررسی کنید که کاربر دارای قابلیت `manage_options` باشد.
  - فایل `wp-content/debug.log` را برای خطاهای `[WC Moadian]` بررسی کنید.
- **خطاهای احراز هویت یا ارسال**:
  - مطمئن شوید کلید خصوصی و کد اقتصادی صحیح و با فرمت مناسب وارد شده‌اند.
  - بررسی کنید که سرور به API مودیان (`https://tp.tax.gov.ir` یا سندباکس) دسترسی دارد.
  - پیام‌های خطا را در صفحه **فاکتورهای مودیان** یا `debug.log` بررسی کنید.
- **مشکلات عمومی**:
  - کش مرورگر و سرور (در صورت استفاده از افزونه کش) را پاک کنید.
  - با یک نصب تمیز وردپرس تست کنید تا مشکلات محیطی شناسایی شوند.

### مشارکت
از مشارکت شما استقبال می‌کنیم! برای مشارکت:
1. این مخزن را فورک کنید.
2. یک شاخه جدید برای ویژگی یا رفع اشکال ایجاد کنید (`git checkout -b feature/your-feature`).
3. تغییرات خود را کامیت کنید (`git commit -m 'Add your feature'`).
4. شاخه را به مخزن خود推送 کنید (`git push origin feature/your-feature`).
5. یک درخواست کشش (Pull Request) با توضیحات کامل تغییرات باز کنید.

لطفاً مطمئن شوید کد شما از استانداردهای کدنویسی وردپرس پیروی می‌کند و مستندات مناسب دارد.

### لایسنس
این افزونه تحت [لایسنس GPLv2 یا بالاتر](https://www.gnu.org/licenses/gpl-2.0.html) منتشر شده است.

### پشتیبانی
برای پشتیبانی، لطفاً یک مسئله (Issue) در [مخزن GitHub](https://github.com/your-repo/woocommerce-moadian-connector) باز کنید یا با نویسنده در [ildrm.com](https://ildrm.com) تماس بگیرید.