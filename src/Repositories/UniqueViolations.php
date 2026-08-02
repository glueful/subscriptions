<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

/**
 * Shared cross-driver unique-violation detector (spec §8), extracted out of
 * SubscriptionEventRepository::isUniqueViolation() so ProviderEventReceiptRepository
 * (Task 6) can reuse the exact same detection logic instead of re-implementing it.
 *
 * SQLSTATE 23000 (MySQL/SQLite) or 23505 (PostgreSQL) via getCode(), or a
 * "unique"/SQLSTATE message substring -- checked down the previous-exception chain in
 * case a caller wrapped the underlying PDO error.
 */
final class UniqueViolations
{
    public static function isUniqueViolation(\Throwable $e): bool
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
