# =============================================================================
# صورة Docker للتطوير والحماية — php-fpm + nginx في صورة واحدة (ملف واحد)
# الهدف: نفس سلوك الاستضافة التقليدية دون الحاجة إلى تثبيت أي شيء محليًا.
# =============================================================================

FROM php:8.3-fpm-alpine

# --- امتدادات PHP المطلوبة -------------------------------------------------
# pdo_sqlite: قاعدة البيانات | gd: تصغير الصور | intl: دعم النصوص
# ملاحظة: fileinfo مدمج في PHP 8 فلا نحتاج تثبيته.
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev sqlite-dev libpng-dev libjpeg-turbo-dev libwebp-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo_sqlite gd intl; \
    apk del .build-deps; \
    rm -rf /tmp/*

# --- إعدادات PHP و nginx ---------------------------------------------------
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf

RUN apk add --no-cache nginx \
 && rm /etc/nginx/http.d/default.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
 && mkdir -p /var/www/html/storage/database /var/www/html/storage/uploads \
             /var/www/html/storage/backups /var/www/html/storage/logs \
             /var/www/html/storage/tmp /var/www/html/storage/config

WORKDIR /var/www/html
COPY . /var/www/html

# لا تُنسخ الأسرار ولا قاعدة البيانات المحلية ولا السجلات إلى الصورة
RUN set -eux; \
    rm -f /var/www/html/storage/config/local.php; \
    rm -f /var/www/html/storage/database/*.sqlite /var/www/html/storage/database/*.sqlite-wal /var/www/html/storage/database/*.sqlite-shm; \
    rm -rf /var/www/html/storage/logs/* /var/www/html/storage/tmp/* /var/www/html/storage/backups/*; \
    rm -rf /var/www/html/.git; \
    chown -R www-data:www-data /var/www/html/storage; \
    chmod 775 /var/www/html/storage /var/www/html/storage/database \
                /var/www/html/storage/uploads /var/www/html/storage/backups \
                /var/www/html/storage/logs /var/www/html/storage/tmp \
                /var/www/html/storage/config

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD wget -qO- "http://127.0.0.1/api/index.php?r=ping" >/dev/null 2>&1 || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["nginx", "-g", "daemon off;"]