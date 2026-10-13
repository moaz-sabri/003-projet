<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\ProjectConfig;
use App\Core\Database;
use App\Core\Money;

/**
 * الوصول إلى الطلبات وعناصرها وسجل تغييرات حالتها.
 */
final class OrderRepository
{
    /**
     * قائمة الطلبات للإدارة مع فلاتر (حالة، موقع، بحث، تاريخ).
     */
    public function listForAdmin(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);

        $total = (int) Database::value("SELECT COUNT(*) FROM orders WHERE $where", $params);

        $sql = "SELECT id, number, served_on, order_type, customer_name, customer_phone,
                       location_id, location_label, status, total_minor, items_count, created_at
                FROM orders WHERE $where
                ORDER BY created_at DESC, id DESC
                LIMIT $limit OFFSET $offset";
        $rows = Database::all($sql, $params);

        return [
            'items' => array_map([$this, 'formatSummary'], $rows),
            'total' => $total,
        ];
    }

    /** الطلبات الجديدة غير المكتملة (لتحديث دوري في الإدارة). */
    public function activeSince(string $since): array
    {
        $since = date('Y-m-d H:i:s', strtotime($since . ' -5 minutes'));
        $rows = Database::all(
            "SELECT id, number, served_on, order_type, customer_name, customer_phone,
                    location_id, location_label, status, total_minor, items_count, created_at
             FROM orders
             WHERE status IN ('new', 'in_progress') AND created_at >= :since
             ORDER BY id DESC LIMIT 60",
            ['since' => $since]
        );
        return array_map([$this, 'formatSummary'], $rows);
    }

    public function find(int $id): ?array
    {
        $row = Database::one('SELECT * FROM orders WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return null;
        }
        $order = $this->formatFull($row);
        $order['items'] = array_map([$this, 'formatItem'], Database::all(
            'SELECT * FROM order_items WHERE order_id = :id ORDER BY id ASC',
            ['id' => $id]
        ));
        $order['events'] = Database::all(
            'SELECT from_status, to_status, actor, note, created_at FROM order_events WHERE order_id = :id ORDER BY id ASC',
            ['id' => $id]
        );
        return $order;
    }

    public function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $row = Database::one('SELECT * FROM orders WHERE public_token = :token', ['token' => $token]);
        if ($row === null) {
            return null;
        }
        return $this->formatFull($row);
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $key)) {
            return null;
        }
        $row = Database::one('SELECT * FROM orders WHERE idempotency_key = :key', ['key' => $key]);
        return $row === null ? null : $this->formatFull($row);
    }

    /** رقم ظاهر متسلسل لكل يوم. */
    public function nextNumber(string $day): int
    {
        Database::run(
            "INSERT INTO daily_counters (day, value) VALUES (:day, 1)
             ON CONFLICT(day) DO UPDATE SET value = value + 1",
            ['day' => $day]
        );
        return (int) Database::value('SELECT value FROM daily_counters WHERE day = :day', ['day' => $day]);
    }

    public function newToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
            $exists = (int) Database::value('SELECT COUNT(*) FROM orders WHERE public_token = :t', ['t' => $token]);
        } while ($exists > 0);
        return $token;
    }

    public function updateStatus(int $orderId, string $from, string $to, string $actor, string $note = ''): void
    {
        Database::update('orders', [
            'status'      => $to,
            'updated_at'  => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $orderId]);

        Database::insert('order_events', [
            'order_id'    => $orderId,
            'from_status' => $from,
            'to_status'   => $to,
            'actor'       => $actor,
            'note'        => mb_substr($note, 0, 300),
            'created_at'  => Database::now(),
        ]);
    }

    public function cancelReason(int $orderId, string $reason): void
    {
        Database::update('orders', ['cancel_reason' => mb_substr($reason, 0, 300)], 'id = :id', ['id' => $orderId]);
    }

    public function stats(): array
    {
        $today = date('Y-m-d');
        return [
            'new'      => (int) Database::value("SELECT COUNT(*) FROM orders WHERE status = 'new'"),
            'active'   => (int) Database::value("SELECT COUNT(*) FROM orders WHERE status IN ('new','in_progress')"),
            'today'    => (int) Database::value('SELECT COUNT(*) FROM orders WHERE served_on = :d', ['d' => $today]),
            'today_total' => (int) Database::value('SELECT COALESCE(SUM(total_minor),0) FROM orders WHERE served_on = :d', ['d' => $today]),
            'completed_today' => (int) Database::value("SELECT COUNT(*) FROM orders WHERE served_on = :d AND status = 'completed'", ['d' => $today]),
        ];
    }

    /* ------------------------------------------------------------------ */

    private function buildFilters(array $filters): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['location_id'])) {
            $where[] = 'location_id = :loc';
            $params['loc'] = (int) $filters['location_id'];
        }
        if (!empty($filters['date'])) {
            $where[] = 'served_on = :date';
            $params['date'] = $filters['date'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(CAST(number AS TEXT) LIKE :q OR customer_name LIKE :q OR location_label LIKE :q)';
            $params['q'] = '%' . trim($filters['q']) . '%';
        }
        return [implode(' AND ', $where), $params];
    }

    public function formatSummary(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'number'        => (int) $row['number'],
            'served_on'     => (string) $row['served_on'],
            'order_type'    => (string) $row['order_type'],
            'order_type_label' => ProjectConfig::ORDER_TYPES[$row['order_type']]['label'] ?? $row['order_type'],
            'customer_name' => (string) $row['customer_name'],
            'customer_phone' => (string) $row['customer_phone'],
            'location_id'   => $row['location_id'] !== null ? (int) $row['location_id'] : null,
            'location'      => (string) $row['location_label'],
            'status'        => (string) $row['status'],
            'status_label'  => ProjectConfig::ORDER_STATUSES[$row['status']]['label'] ?? $row['status'],
            'status_color'  => ProjectConfig::ORDER_STATUSES[$row['status']]['color'] ?? '#888',
            'total'         => Money::format((int) $row['total_minor']),
            'total_minor'   => (int) $row['total_minor'],
            'items_count'   => (int) $row['items_count'],
            'created_at'    => (string) $row['created_at'],
            'time_label'    => date('H:i', strtotime((string) $row['created_at'])),
        ];
    }

    public function formatFull(array $row): array
    {
        $full = $this->formatSummary($row);
        $full['notes']        = (string) $row['notes'];
        $full['cancel_reason'] = (string) $row['cancel_reason'];
        $full['fields']       = json_decode((string) $row['fields_json'], true) ?: [];
        $full['tracking_token'] = (string) $row['public_token'];
        $full['next_statuses'] = ProjectConfig::ORDER_STATUSES[$row['status']]['next'] ?? [];
        return $full;
    }

    private function formatItem(array $row): array
    {
        return [
            'name'       => (string) $row['name'],
            'quantity'   => (int) $row['quantity'],
            'unit_price' => Money::format((int) $row['unit_price_minor']),
            'unit_price_minor' => (int) $row['unit_price_minor'],
            'line_total' => Money::format((int) $row['line_total_minor']),
            'line_total_minor' => (int) $row['line_total_minor'],
            'extra'      => json_decode((string) $row['extra_json'], true) ?: [],
        ];
    }
}