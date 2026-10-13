#!/bin/sh
# نقطة دخول الحاوية: يهيّئ الأذونات، يشغّل php-fpm، ثم ينفّذ الأمر المطلوب.

set -e

# امنح php-fpm الصلاحيات على مجلدات التخزين والرفع
chown -R www-data:www-data /var/www/html/storage /var/www/html/app/uploads 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/app/uploads 2>/dev/null || true
mkdir -p /var/www/html/app/uploads/products 2>/dev/null || true

if [ ! -f /var/www/html/storage/config/local.php ]; then
  echo ""
  echo "=============================================="
  echo " لم يتم تهيئة النسخة بعد."
  echo " افتح المتصفح على:  http://localhost:8080/setup/"
  echo " أو نفّذ داخل الحاوية:"
  echo "   docker compose exec app php bin/console install \\"
  echo "       --url=http://localhost:8080 --user=admin --pass=كلمة_مرور_قوية"
  echo "=============================================="
  echo ""
fi

# تشغيل php-fpm في الخلفية (nginx هو العملية الأمامية)
if [ "$1" = "nginx" ] || [ "$1" = "nginx-debug" ]; then
  php-fpm -D
  echo "php-fpm started on 127.0.0.1:9000"
fi

exec "$@"