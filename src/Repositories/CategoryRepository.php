<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * الوصول إلى التصنيفات. كل الاستعلامات محضّرة.
 */
final class CategoryRepository
{
    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM categories';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        return Database::all($sql);
    }

    public function find(int $id): ?array
    {
        return Database::one('SELECT * FROM categories WHERE id = :id', ['id' => $id]);
    }

    public function create(array $data): int
    {
        $max = (int) Database::value('SELECT COALESCE(MAX(sort_order), 0) FROM categories');
        return Database::insert('categories', [
            'name'        => (string) ($data['name'] ?? ''),
            'description' => $data['description'] ?? '',
            'image_path'  => $data['image_path'] ?? '',
            'sort_order'  => $data['sort_order'] ?? ($max + 10),
            'is_active'   => !empty($data['is_active']) ? 1 : 0,
            'created_at'  => Database::now(),
            'updated_at'  => Database::now(),
        ]);
    }

    public function update(int $id, array $data): void
    {
        $fields = [
            'name'        => (string) ($data['name'] ?? ''),
            'description' => $data['description'] ?? '',
            'image_path'  => $data['image_path'] ?? '',
            'is_active'   => !empty($data['is_active']) ? 1 : 0,
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        // كان `?? 0`: أي نداء تحديث بلا sort_order يكتب 0 فيختفى ترتيب
        // كل التصنيفات دفعة واحدة — بلا خطأ ولا رسالة.
        if (array_key_exists('sort_order', $data)) {
            $fields['sort_order'] = (int) $data['sort_order'];
        }

        Database::update('categories', $fields, 'id = :id', ['id' => $id]);
    }

    public function reorder(array $orderedIds): void
    {
        Database::transaction(function () use ($orderedIds): void {
            $i = 0;
            foreach ($orderedIds as $id) {
                $i += 10;
                Database::update('categories', ['sort_order' => $i], 'id = :id', ['id' => (int) $id]);
            }
        });
    }

    public function delete(int $id): void
    {
        Database::delete('categories', 'id = :id', ['id' => $id]);
    }

    public function countProducts(int $categoryId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM products WHERE category_id = :id', ['id' => $categoryId]);
    }
}