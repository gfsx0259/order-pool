<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Order;

use Cycle\Database\DatabaseProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Closes an order once the partner has actually received `limit_total` leads.
 * `lead_deliveries` is unique on (lead_uuid, order_id), so a plain count is exact.
 */
final readonly class OrderCompletion
{
    private const string SQL = "UPDATE orders o
        SET status = 'completed', completed_at = COALESCE(completed_at, NOW()), updated_at = NOW()
        WHERE o.status <> 'completed'
          AND o.limit_total IS NOT NULL
          AND o.limit_total <= (
              SELECT count(*) FROM lead_deliveries d
              WHERE d.order_id = o.id AND d.delivery_status = 'delivered'
          )";

    public function __construct(
        private DatabaseProviderInterface $db,
        private LoggerInterface $logger,
    ) {}

    public function completeIfDelivered(int $orderId): bool
    {
        $completed = $this->db->database()->execute(self::SQL . ' AND o.id = ?', [$orderId]) > 0;

        if ($completed) {
            $this->logger->info('Order completed: delivered leads reached limit_total', ['order_id' => $orderId]);
        }

        return $completed;
    }

    public function completeAllDelivered(): int
    {
        $completed = $this->db->database()->execute(self::SQL);

        if ($completed > 0) {
            $this->logger->info('Orders completed: delivered leads reached limit_total', ['count' => $completed]);
        }

        return $completed;
    }
}
