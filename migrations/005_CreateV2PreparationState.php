<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateV2PreparationState implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('subscription_v2_preparation')) {
            return;
        }
        $schema->createTable('subscription_v2_preparation', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('marker_key', 40);
            $table->string('catalog_signature', 64);
            $table->json('report')->nullable();
            $table->timestamp('prepared_at')->default('CURRENT_TIMESTAMP');
            $table->unique('marker_key');
        });
        $schema->execute();
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('subscription_v2_preparation');
        $schema->execute();
    }

    public function getDescription(): string
    {
        return 'Create the v2 preparation-state table (upgrade bridge, spec §3)';
    }
}
