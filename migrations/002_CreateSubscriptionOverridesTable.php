<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateSubscriptionOverridesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('subscription_overrides')) {
            return;
        }

        $schema->createTable('subscription_overrides', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 64);
            $table->string('entitlement', 128);
            $table->json('value');
            $table->timestamp('expires_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->index('tenant_uuid', 'idx_overrides_tenant');
            $table->unique(['tenant_uuid', 'entitlement'], 'uniq_override_tenant_entitlement');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('subscription_overrides');
    }

    public function getDescription(): string
    {
        return 'Creates per-tenant subscription entitlement overrides.';
    }
}
