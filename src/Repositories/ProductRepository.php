<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\ProjectConfig;
use App\Core\Database;
use App\Core\Money;

/**
 * الوصول إلى المنتجات، مع تنسيق جاهز للواجهة.
 */
final class ProductRepository
{
    public function allAdmin(): array
    {
        return array_map([$this, 'formatAdmin'], Database::all(
            'SELECT p.*, c.name AS category_name
             FROM products p LEFT JOIN categories c ON c.id = p.category_id
             ORDER BY p.sort_order ASC, p.id DESC'
        ));
    }

    /** الكتالوج العام: المتاح فقط، مع الحقول المطلوبة للعرض دون اتصال. */
    public function catalog(?string $categoryCode = null, ?string $search = null): array
    {
        $sql = 'SELECT p.id, p.name, p.description, p.price_minor, p.image_path, p.extra_json,
                       p.category_id, p.is_available, p.is_featured, p.sort_order, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE p.is_available = 1 AND (c.is_active = 1 OR p.category_id IS NULL)';

        $params = [];
        if ($categoryCode !== null && $categoryCode !== '') {
            $sql .= ' AND c.id = :cat';
            $params['cat'] = (int) $categoryCode;
        }
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (p.name LIKE :q OR p.description LIKE :q OR p.extra_json LIKE :q)';
            $params['q'] = '%' . trim($search) . '%';
        }
        $sql .= ' ORDER BY c.sort_order ASC, p.sort_order ASC, p.id ASC LIMIT ' . ProjectConfig::CATALOG_PAGE_SIZE;

        return array_map([$this, 'formatCatalog'], Database::all($sql, $params));
    }

    public function find(int $id): ?array
    {
        $row = Database::one(
            'SELECT p.*, c.name AS category_name FROM products p
             LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = :id',
            ['id' => $id]
        );
        return $row === null ? null : $this->formatAdmin($row);
    }

    /** نسخة مختصرة للاستخدام داخل إنشاء الطلب (السعر الحالي والتوفر). */
    public function findForOrder(int $id): ?array
    {
        return Database::one('SELECT id, name, price_minor, is_available FROM products WHERE id = :id', ['id' => $id]);
    }

    public function create(array $data): int
    {
        return Database::insert('products', [
            'category_id'  => (int) ($data['category_id'] ?? 0) ?: null,
            'name'         => (string) ($data['name'] ?? ''),
            'description'  => $data['description'] ?? '',
            'price_minor'  => (int) ($data['price_minor'] ?? 0),
            'image_path'   => $data['image_path'] ?? '',
            'extra_json'   => json_encode($data['extra'] ?? [], JSON_UNESCAPED_UNICODE),
            'is_available' => !empty($data['is_available']) ? 1 : 0,
            'is_featured'  => !empty($data['is_featured']) ? 1 : 0,
            'sort_order'   => (int) ($data['sort_order'] ?? 0),
            'created_at'   => Database::now(),
            'updated_at'   => Database::now(),
        ]);
    }

    public function update(int $id, array $data): void
    {
        Database::update('products', [
            'category_id'  => (int) ($data['category_id'] ?? 0) ?: null,
            'name'         => (string) ($data['name'] ?? ''),
            'description'  => $data['description'] ?? '',
            'price_minor'  => (int) ($data['price_minor'] ?? 0),
            'image_path'   => $data['image_path'] ?? '',
            'extra_json'   => json_encode($data['extra'] ?? [], JSON_UNESCAPED_UNICODE),
            'is_available' => !empty($data['is_available']) ? 1 : 0,
            'is_featured'  => !empty($data['is_featured']) ? 1 : 0,
            'sort_order'   => (int) ($data['sort_order'] ?? 0),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        Database::delete('products', 'id = :id', ['id' => $id]);
    }

    /* ------------------------------------------------------------------ */

    private function formatCatalog(array $row): array
    {
        $extra = json_decode((string) $row['extra_json'], true) ?: [];

        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['name'],
            'description' => (string) $row['description'],
            'price'       => Money::format((int) $row['price_minor']),
            'price_minor' => (int) $row['price_minor'],
            'image'       => $row['image_path'] !== '' ? ProjectConfig::uploadsUrl($row['image_path']) : '',
            'category_id' => $row['category_id'] !== null ? (int) $row['category_id'] : null,
            'category'    => (string) ($row['category_name'] ?? ''),
            'available'   => (bool) $row['is_available'],
            'featured'    => (bool) $row['is_featured'],
            'extra'       => $extra,
            'tags'        => $this->tags($extra),
            'has_details' => $this->hasDetails($row, $extra),
        ];
    }

    private function formatAdmin(array $row): array
    {
        $extra = json_decode((string) $row['extra_json'], true) ?: [];

        return [
            'id'          => (int) $row['id'],
            'category_id' => $row['category_id'] !== null ? (int) $row['category_id'] : null,
            'category'    => (string) ($row['category_name'] ?? ''),
            'name'        => (string) $row['name'],
            'description' => (string) $row['description'],
            'price'       => Money::minorToInput((int) $row['price_minor']),
            'price_minor' => (int) $row['price_minor'],
            'image'       => $row['image_path'] !== '' ? ProjectConfig::uploadsUrl($row['image_path']) : '',
            'image_path'  => (string) $row['image_path'],
            'extra'       => $extra,
            'available'   => (bool) $row['is_available'],
            'featured'    => (bool) $row['is_featured'],
            'sort_order'  => (int) $row['sort_order'],
            'created_at'  => (string) $row['created_at'],
        ];
    }

    /** وسوم قصيرة تظهر في بطاقة المنتج (مثل: نباتي، حار). */
    private function tags(array $extra): array
    {
        $tags = [];
        foreach (ProjectConfig::productExtraFields() as $field) {
            if (empty($field['show_in_card'])) {
                continue;
            }
            $value = $extra[$field['key']] ?? null;
            if ($field['type'] === 'boolean' && $value) {
                $tags[] = ['key' => $field['key'], 'label' => $field['label'], 'kind' => 'flag'];
            } elseif ($field['type'] === 'text' && is_string($value) && trim($value) !== '') {
                $tags[] = ['key' => $field['key'], 'label' => $value, 'kind' => 'text'];
            }
        }
        return $tags;
    }

    /** هل يستحق المنتج صفحة تفاصيل؟ (القواعد في ProjectConfig) */
    public function hasDetails(array $row, array $extra): bool
    {
        if (!ProjectConfig::feature('product_details')) {
            return false;
        }
        $rules = ProjectConfig::productDetailRules();

        if (mb_strlen((string) $row['description']) >= $rules['min_description_length']) {
            return true;
        }
        $detailCount = 0;
        foreach (ProjectConfig::productDetailFields() as $field) {
            $key = $field['key'];
            $value = $extra[$key] ?? null;
            $forced = in_array($key, $rules['always_detail_keys'], true);
            if (($value !== null && $value !== '' && $value !== []) || $forced) {
                $detailCount++;
            }
        }
        return $detailCount >= $rules['extra_field_threshold'];
    }

    /** حقول التفاصيل التي يجب عرضها لهذا المنتج. */
    public function detailFields(array $row): array
    {
        $extra = json_decode((string) ($row['extra_json'] ?? '{}'), true) ?: [];
        $out   = [];
        foreach (ProjectConfig::productDetailFields() as $field) {
            $value = $extra[$field['key']] ?? '';
            $out[] = [
                'key'   => $field['key'],
                'label' => $field['label'],
                'type'  => $field['type'],
                'value' => $value,
                'hint'  => $field['hint'] ?? '',
            ];
        }
        // كل الحقول الإضافية غير الفارغة (عدا ما ظهر في الوسوم)
        $tagKeys = [];
        foreach (ProjectConfig::productExtraFields() as $field) {
            if (!empty($field['show_in_card'])) {
                $tagKeys[] = $field['key'];
            }
        }
        $detailKeys = array_column(ProjectConfig::productDetailFields(), 'key');
        foreach (ProjectConfig::productExtraFields() as $field) {
            $key = $field['key'];
            if (in_array($key, $detailKeys, true)) {
                continue;
            }
            $value = $extra[$key] ?? '';
            if ($value === '' || $value === [] || $value === null) {
                continue;
            }
            $out[] = [
                'key'   => $key,
                'label' => $field['label'],
                'type'  => $field['type'],
                'value' => $value,
                'hint'  => $field['hint'] ?? '',
            ];
        }
        return $out;
    }
}