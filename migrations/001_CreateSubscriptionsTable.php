<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateSubscriptionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('subscriptions')) {
            return;
        }

        $schema->createTable('subscriptions', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 64);
            $table->string('plan_key', 64);
            $table->string('status', 20)->default('active');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('payvia_gateway', 50)->nullable();
            $table->string('payvia_customer_id', 191)->nullable();
            $table->string('payvia_subscription_id', 191)->nullable();
            $table->string('payvia_priced_plan_uuid', 12)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique('tenant_uuid');
            $table->unique(['payvia_gateway', 'payvia_subscription_id'], 'uniq_subscriptions_payvia_sub');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('subscriptions');
    }

    public function getDescription(): string
    {
        return 'Creates tenant subscriptions with optional Payvia linkage.';
    }
}
