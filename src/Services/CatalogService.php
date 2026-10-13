<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\ProjectConfig;
use App\Core\Money;
use App\Core\RuntimeSettings;
use App\Repositories\CategoryRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ProductRepository;

/**
 * كل ما تحتاجه واجهة الزبون في استدعاء واحد: الإعدادات + التصنيفات + المنتجات + المواقع.
 * هذا يجعل التصفح دون اتصال ممكنًا بحزمة واحدة تُخزَّن في Service Worker.
 */
final class CatalogService
{
    private CategoryRepository $categories;
    private ProductRepository  $products;
    private LocationRepository $locations;

    public function __construct()
    {
        $this->categories = new CategoryRepository();
        $this->products   = new ProductRepository();
        $this->locations  = new LocationRepository();
    }

    /** الحمولة الكاملة (bootstrap) لواجهة الزبون. */
    public function snapshot(?string $search = null): array
    {
        $products = $this->products->catalog(null, $search);
        $counts   = [];
        foreach ($products as $product) {
            $cid = $product['category_id'];
            $counts[$cid] = ($counts[$cid] ?? 0) + 1;
        }

        $categories = [];
        foreach ($this->categories->all(true) as $category) {
            $categories[] = [
                'id'      => $category['id'],
                'name'    => $category['name'],
                'image'   => $category['image_path'] !== '' ? ProjectConfig::uploadsUrl($category['image_path']) : '',
                'count'   => $counts[$category['id']] ?? 0,
                'active'  => (bool) $category['is_active'],
            ];
        }

        return [
            'settings'  => $this->settingsPayload(),
            'config'    => $this->configPayload(),
            'categories' => $categories,
            'products'  => $products,
            'locations' => ProjectConfig::feature('locations') ? $this->locations->all(true) : [],
        ];
    }

    /** إعدادات العرض + أساسيات العملة. */
    public function settingsPayload(): array
    {
        $settings = RuntimeSettings::publicPayload();
        if ($settings['show_welcome'] === false) {
            $settings['welcome_message'] = '';
        }
        return $settings;
    }

    /** تعريفات بنيوية تحتاجها الواجهة لبناء النماذج. */
    public function configPayload(): array
    {
        $types = [];
        foreach (ProjectConfig::orderTypeList() as $type) {
            $types[] = [
                'key'         => $type['key'],
                'label'       => $type['label'],
                'description' => $type['description'] ?? '',
                'default'     => (bool) ($type['default'] ?? false),
                'requires_location' => (bool) ($type['requires_location'] ?? false),
                'fields'      => array_map([$this, 'formatField'], ProjectConfig::orderFieldsFor($type['key'])),
            ];
        }

        return [
            'locale'          => ProjectConfig::LOCALE,
            'direction'       => ProjectConfig::DIRECTION,
            'currency'        => ProjectConfig::CURRENCY,
            'currency_position' => ProjectConfig::CURRENCY_POSITION,
            'timezone'        => ProjectConfig::TIMEZONE,
            'business_type'   => ProjectConfig::BUSINESS_TYPE,
            'location_singular' => ProjectConfig::LOCATION_SINGULAR,
            'location_plural'   => ProjectConfig::LOCATION_PLURAL,
            'location_required' => ProjectConfig::LOCATION_REQUIRED,
            'location_source'   => ProjectConfig::LOCATION_SOURCE,
            'customer_name_label' => ProjectConfig::CUSTOMER_NAME_LABEL,
            'customer_name_placeholder' => ProjectConfig::CUSTOMER_NAME_PLACEHOLDER,
            'max_qty'          => ProjectConfig::MAX_QTY_PER_ITEM,
            'features'         => ProjectConfig::FEATURES,
            'order_types'      => $types,
            'statuses'         => array_map(
                static fn(string $key, array $def): array => ['key' => $key, 'label' => $def['label'], 'color' => $def['color']],
                array_keys(ProjectConfig::ORDER_STATUSES),
                ProjectConfig::ORDER_STATUSES
            ),
        ];
    }

    private function formatField(array $field): array
    {
        return [
            'key'         => $field['key'],
            'label'       => $field['label'],
            'type'        => $field['type'],
            'required'    => (bool) ($field['required'] ?? false),
            'placeholder' => $field['placeholder'] ?? '',
            'hint'        => $field['hint'] ?? '',
            'options'     => $field['options'] ?? [],
        ];
    }

    /** تفاصيل منتج واحد (تُفتح فقط إذا كان يستحق صفحة/نافذة تفاصيل). */
    public function productDetails(int $id): ?array
    {
        $row = $this->rawProduct($id);
        if ($row === null || (int) $row['is_available'] !== 1) {
            return null;
        }

        $repo    = new ProductRepository();
        $extra   = json_decode((string) $row['extra_json'], true) ?: [];
        $details = $repo->detailFields($row);

        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['name'],
            'description' => (string) $row['description'],
            'price'       => Money::format((int) $row['price_minor']),
            'price_minor' => (int) $row['price_minor'],
            'image'       => $row['image_path'] !== '' ? ProjectConfig::uploadsUrl($row['image_path']) : '',
            'category'    => (string) ($row['category_name'] ?? ''),
            'available'   => true,
            'details'     => $details,
        ];
    }

    private function rawProduct(int $id): ?array
    {
        return \App\Core\Database::one(
            'SELECT p.*, c.name AS category_name FROM products p
             LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = :id',
            ['id' => $id]
        );
    }
}