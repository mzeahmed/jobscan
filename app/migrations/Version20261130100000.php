<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 2.3 — résultats de matching par profil de recherche (`job_match`).
 *
 * Crée uniquement `job_match` : données dérivées et recalculables, aucune donnée existante touchée.
 */
final class Version20261130100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'job_match : résultats du matching (compatibilité, fraîcheur, priorité) par offre et par profil de recherche';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE job_match (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              search_profile_id VARCHAR(50) NOT NULL,
              verdict VARCHAR(20) NOT NULL,
              match_score INTEGER NOT NULL,
              freshness_score INTEGER NOT NULL,
              priority_score INTEGER NOT NULL,
              coverage INTEGER NOT NULL,
              cv_id VARCHAR(50) DEFAULT NULL,
              details CLOB NOT NULL,
              fingerprint VARCHAR(64) NOT NULL,
              rules_version INTEGER NOT NULL,
              computed_at DATETIME NOT NULL,
              job_id INTEGER NOT NULL,
              CONSTRAINT FK_7B077875BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_7B077875BE04EA9 ON job_match (job_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_match_job_profile ON job_match (job_id, search_profile_id)');
        $this->addSql('CREATE INDEX idx_job_match_profile_priority ON job_match (search_profile_id, priority_score)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE job_match');
    }
}
