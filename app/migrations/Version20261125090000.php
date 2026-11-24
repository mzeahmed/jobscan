<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 1.3 — suivi persistant des emails d'alerte traités (idempotence du provider email).
 *
 * Crée uniquement `processed_email` ; aucune donnée existante n'est touchée.
 */
final class Version20261125090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'processed_email : suivi des emails d\'alerte traités';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE processed_email (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              dedup_key VARCHAR(64) NOT NULL,
              message_id VARCHAR(255) DEFAULT NULL,
              folder VARCHAR(255) NOT NULL,
              uid INTEGER NOT NULL,
              uid_validity VARCHAR(20) DEFAULT NULL,
              sender VARCHAR(255) NOT NULL,
              subject VARCHAR(255) NOT NULL,
              received_at DATETIME DEFAULT NULL,
              platform VARCHAR(50) DEFAULT NULL,
              status VARCHAR(20) NOT NULL,
              offers_count INTEGER NOT NULL,
              error CLOB DEFAULT NULL,
              processed_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_processed_email_key ON processed_email (dedup_key)');
        $this->addSql('CREATE INDEX idx_processed_email_processed_at ON processed_email (processed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE processed_email');
    }
}
