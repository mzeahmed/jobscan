<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * API 1 — authentification de l'API : table `refresh_token` (jetons de rafraîchissement à usage unique).
 *
 * Non destructive : une nouvelle table, aucune donnée existante touchée. Seule l'empreinte du jeton est stockée.
 */
final class Version20261210100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'refresh_token';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE refresh_token (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, used_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, token_hash VARCHAR(64) NOT NULL, family VARCHAR(32) NOT NULL, username VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_refresh_token_hash ON refresh_token (token_hash)');
        $this->addSql('CREATE INDEX idx_refresh_token_family ON refresh_token (family)');
        $this->addSql('CREATE INDEX idx_refresh_token_expires ON refresh_token (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE refresh_token');
    }
}
