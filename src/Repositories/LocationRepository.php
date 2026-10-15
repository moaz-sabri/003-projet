<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\ProjectConfig;
use App\Core\Database;

/**
 * المواقع (طاولات / معارض / أقسام) مع رموز QR المرتبطة بها.
 */
final class LocationRepository
{
    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM locations';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        return array_map([$this, 'format'], Database::all($sql));
    }

    public function find(int $id): ?array
    {
        $row = Database::one('SELECT * FROM locations WHERE id = :id', ['id' => $id]);
        return $row === null ? null : $this->format($row);
    }

    public function findByCode(string $code): ?array
    {
        $row = Database::one('SELECT * FROM locations WHERE code = :code', ['code' => $code]);
        return $row === null ? null : $this->format($row);
    }

    public function save(?int $id, array $data): int
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        // توليد الرمز داخل المستودع أيضًا (لا نعتمد على طبقة الـ API)
        if ($code === '') {
            $code = $this->suggestCode();
        }
        if ($id === null) {
            $max = (int) Database::value('SELECT COALESCE(MAX(sort_order), 0) FROM locations');
            return Database::insert('locations', [
                'code'       => $code,
                'name'       => (string) ($data['name'] ?? ''),
                'type'       => $data['type'] ?? '',
                'note'       => $data['note'] ?? '',
                'is_active'  => !empty($data['is_active']) ? 1 : 0,
                'sort_order' => (int) ($data['sort_order'] ?? ($max + 10)),
                'created_at' => Database::now(),
                'updated_at' => Database::now(),
            ]);
        }

        Database::update('locations', [
            'code'       => $code,
            'name'       => (string) ($data['name'] ?? ''),
            'type'       => $data['type'] ?? '',
            'note'       => $data['note'] ?? '',
            'is_active'  => !empty($data['is_active']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);

        return $id;
    }

    public function delete(int $id): void
    {
        Database::delete('locations', 'id = :id', ['id' => $id]);
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM locations WHERE code = :code';
        $params = ['code' => strtoupper($code)];
        if ($exceptId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $exceptId;
        }
        return (int) Database::value($sql, $params) > 0;
    }

        /**
     * توليد رمز موقع متاح لم يُستخدم بعد.
     * يضمن التفرد عبر حلقة قصيرة على جدول المواقع.
     */
    public function suggestCode(): string
    {
        $n = (int) Database::value('SELECT COUNT(*) FROM locations') + 1;
        do {
            $code = sprintf('%s%02d', $this->codePrefix(), $n);
            $n++;
        } while ($this->codeExists($code));

        return $code;
    }
/** بادئة رموز المواقع (حرف واحد يميّز نوع النشاط داخل الرابط). */
    private function codePrefix(): string
    {
        return ProjectConfig::locationCodePrefix();
    }

    private function format(array $row): array
    {
        return [
            'id'        => (int) $row['id'],
            'code'      => (string) $row['code'],
            'name'      => (string) $row['name'],
            'type'      => (string) $row['type'],
            'note'      => (string) $row['note'],
            'active'    => (bool) $row['is_active'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }
}