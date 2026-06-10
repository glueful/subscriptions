<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

final class SubscriptionEventRepository
{
    /** @param array<string,mixed> $event */
    public function insertOrThrow(ApplicationContext $context, array $event): void
    {
        $row = array_merge(['uuid' => Utils::generateNanoID(12)], $event);
        if (isset($row['data']) && is_array($row['data'])) {
            $row['data'] = json_encode($row['data'], JSON_THROW_ON_ERROR);
        }

        db($context)->table('subscription_events')->insert($row);
    }

    /** @param array<string,mixed> $event */
    public function append(ApplicationContext $context, array $event): bool
    {
        try {
            $this->insertOrThrow($context, $event);
            return true;
        } catch (\Throwable $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }
            throw $e;
        }
    }

    public function existsByLogicalKey(ApplicationContext $context, string $gateway, string $key): bool
    {
        return db($context)->table('subscription_events')
            ->where('payvia_gateway', '=', $gateway)
            ->where('payvia_logical_event_key', '=', $key)
            ->limit(1)
            ->first() !== null;
    }

    /**
     * Cross-driver unique-violation detection: SQLSTATE 23000 (MySQL/SQLite) or
     * 23505 (Postgres) via getCode(), or a "unique" message substring -- checked
     * down the previous-exception chain in case a caller wrapped the PDO error.
     * Public because the listener branches on it around its claim transaction.
     */
    public function isUniqueViolation(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $code = (string) $current->getCode();
            if ($code === '23000' || $code === '23505') {
                return true;
            }

            $message = strtolower($current->getMessage());
            if (
                str_contains($message, 'unique')
                || str_contains($message, '23000')
                || str_contains($message, '23505')
            ) {
                return true;
            }
        }

        return false;
    }
}
