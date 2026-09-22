<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * A plan's display price: `price_amount` in the currency's minor units, `price_currency` (ISO
 * 4217) and `billing_interval` (day|week|month|year). For showing what a plan costs on a pricing
 * page or plan picker; the payment gateway still decides what is charged. All three nullable: a
 * plan without a price is valid. Additive-only.
 */
final class PlanDisplayPrice implements MigrationInterface
{
    private const TABLE = 'subscription_plans';

    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasColumn(self::TABLE, 'price_amount')) {
            return;
        }

        $schema->alterTable(self::TABLE, function ($table): void {
            $table->bigInteger('price_amount')->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->string('billing_interval', 16)->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasColumn(self::TABLE, 'price_amount')) {
            return;
        }

        foreach (['price_amount', 'price_currency', 'billing_interval'] as $column) {
            if ($schema->getConnection()->getDriverName() === 'sqlite') {
                $schema->addPendingOperation('ALTER TABLE "' . self::TABLE . "\" DROP COLUMN \"{$column}\"");
                $schema->execute();
                continue;
            }
            $schema->alterTable(self::TABLE, function ($t) use ($column): void {
                $t->dropColumn($column);
            });
        }
    }

    public function getDescription(): string
    {
        return 'Adds subscription_plans.price_amount, price_currency and billing_interval: a plan\'s '
            . 'display price.';
    }
}
