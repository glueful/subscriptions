<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateSubscriptionEventsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('subscription_events')) {
            return;
        }

        $schema->createTable('subscription_events', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 64);
            $table->string('type', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('source', 20);
            $table->string('payvia_gateway', 50)->nullable();
            $table->string('payvia_logical_event_key', 191)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->unique('uuid');
            $table->unique(['payvia_gateway', 'payvia_logical_event_key'], 'uniq_event_gateway_logical_key');
            $table->index(['tenant_uuid', 'created_at'], 'idx_events_tenant_created');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('subscription_events');
    }

    public function getDescription(): string
    {
        return 'Creates subscription lifecycle event log with Payvia logical-key dedupe.';
    }
}
