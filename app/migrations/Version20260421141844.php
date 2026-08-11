<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260421141844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op: duplicate of Version20260421122747 kept for migration history compatibility';
    }

    public function up(Schema $schema): void
    {
        // Intentionally empty: the preceding migration already creates this table.
    }

    public function down(Schema $schema): void
    {
        // Intentionally empty: reverting this historical duplicate must preserve the table.
    }
}
