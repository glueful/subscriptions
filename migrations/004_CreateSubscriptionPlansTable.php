<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateSubscriptionPlansTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('subscription_plans')) {
            return;
        }

        $schema->createTable('subscription_plans', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('plan_key', 64);
            $table->string('display_name', 120);
            $table->string('description', 255)->nullable();
            $table->json('entitlements');
            $table->string('payvia_priced_plan_uuid', 12)->nullable();
            $table->string('status', 20);
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique('plan_key');
        });

        $schema->addPendingOperation('CREATE INDEX idx_subscription_plans_status ON subscription_plans (status)');
        $schema->addPendingOperation('CREATE INDEX idx_subscription_plans_updated_at ON subscription_plans (updated_at)');
        $schema->execute();
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('subscription_plans');
    }

    public function getDescription(): string
    {
        return 'Create managed subscription plans table';
    }
}
