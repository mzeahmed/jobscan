<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514090008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add title_hash column for cross-provider deduplication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__job AS SELECT id, title, url, description, source, score, created_at, notified_at FROM job');
        $this->addSql('DROP TABLE job');
        $this->addSql('CREATE TABLE job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, url VARCHAR(2048) NOT NULL, description CLOB NOT NULL, source VARCHAR(100) NOT NULL, score INTEGER NOT NULL, created_at DATETIME NOT NULL, notified_at DATETIME DEFAULT NULL, title_hash VARCHAR(40) DEFAULT NULL)');
        $this->addSql('INSERT INTO job (id, title, url, description, source, score, created_at, notified_at) SELECT id, title, url, description, source, score, created_at, notified_at FROM __temp__job');
        $this->addSql('DROP TABLE __temp__job');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_title_hash ON job (title_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_url ON job (url)');
        $this->addSql('CREATE INDEX idx_job_score ON job (score)');
        $this->addSql('CREATE INDEX idx_job_source ON job (source)');
        $this->addSql('CREATE INDEX idx_job_created_at ON job (created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__job AS SELECT id, title, url, description, source, score, created_at, notified_at FROM job');
        $this->addSql('DROP TABLE job');
        $this->addSql('CREATE TABLE job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, url VARCHAR(2048) NOT NULL, description CLOB NOT NULL, source VARCHAR(100) NOT NULL, score INTEGER NOT NULL, created_at DATETIME NOT NULL, notified_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO job (id, title, url, description, source, score, created_at, notified_at) SELECT id, title, url, description, source, score, created_at, notified_at FROM __temp__job');
        $this->addSql('DROP TABLE __temp__job');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_url ON job (url)');
        $this->addSql('CREATE INDEX idx_job_score ON job (score)');
        $this->addSql('CREATE INDEX idx_job_source ON job (source)');
        $this->addSql('CREATE INDEX idx_job_created_at ON job (created_at DESC)');
    }
}
