<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260426214013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE job ADD COLUMN notified_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__job AS SELECT id, title, url, description, source, score, created_at FROM job');
        $this->addSql('DROP TABLE job');
        $this->addSql('CREATE TABLE job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, url VARCHAR(2048) NOT NULL, description CLOB NOT NULL, source VARCHAR(100) NOT NULL, score INTEGER NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO job (id, title, url, description, source, score, created_at) SELECT id, title, url, description, source, score, created_at FROM __temp__job');
        $this->addSql('DROP TABLE __temp__job');
    }
}
