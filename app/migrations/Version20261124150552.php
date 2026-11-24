<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 1.1 — fondations du pipeline de collecte.
 *
 * - `job` : modèle temporel (première/dernière détection, fiabilité de la date de
 *   publication), état de l'analyse (reprise), indice de doublon probable ;
 *   l'index d'empreinte devient non unique (un doublon probable n'est plus supprimé).
 * - `job_source` : provenance multi-sources, remplie pour chaque offre existante.
 * - `provider_run` : historique des exécutions de providers.
 *
 * Non destructive : aucune donnée existante n'est supprimée, et les tables étrangères
 * au schéma de `job` ne sont pas touchées. Les offres existantes sont considérées
 * comme déjà analysées (`done`).
 */
final class Version20261124150552 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Job temporal model, analysis state, job_source provenance and provider_run monitoring';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__job AS SELECT * FROM job');
        $this->addSql('DROP TABLE job');
        $this->addSql(<<<'SQL'
            CREATE TABLE job (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              title VARCHAR(255) NOT NULL,
              url VARCHAR(2048) NOT NULL,
              description CLOB NOT NULL,
              source VARCHAR(100) NOT NULL,
              score INTEGER NOT NULL,
              title_hash VARCHAR(40) DEFAULT NULL,
              canonical_url VARCHAR(2048) DEFAULT NULL,
              fingerprint VARCHAR(64) DEFAULT NULL,
              company VARCHAR(255) DEFAULT NULL,
              location VARCHAR(255) DEFAULT NULL,
              published_at DATETIME DEFAULT NULL,
              stack CLOB NOT NULL,
              contract_type VARCHAR(20) NOT NULL,
              freelance BOOLEAN NOT NULL,
              remote BOOLEAN NOT NULL,
              budget VARCHAR(100) NOT NULL,
              recent BOOLEAN NOT NULL,
              seniority VARCHAR(20) NOT NULL,
              score_breakdown CLOB NOT NULL,
              created_at DATETIME NOT NULL,
              notified_at DATETIME DEFAULT NULL,
              first_seen_at DATETIME NOT NULL,
              last_seen_at DATETIME NOT NULL,
              analyzed_at DATETIME DEFAULT NULL,
              publication_date_source VARCHAR(20) NOT NULL,
              publication_date_confidence VARCHAR(10) NOT NULL,
              analysis_status VARCHAR(10) NOT NULL,
              analysis_attempts INTEGER NOT NULL,
              last_analysis_attempt_at DATETIME DEFAULT NULL,
              next_analysis_attempt_at DATETIME DEFAULT NULL,
              last_analysis_error VARCHAR(500) DEFAULT NULL,
              probable_duplicate_of_id INTEGER DEFAULT NULL
            )
        SQL);
        // Offres existantes : déjà analysées ; première/dernière vue = date d'ingestion ;
        // la date de publication existante est de confiance moyenne, son origine inconnue.
        $this->addSql(<<<'SQL'
            INSERT INTO job (
              id, title, url, description, source, score, title_hash, canonical_url, fingerprint,
              company, location, published_at, stack, contract_type, freelance, remote, budget,
              recent, seniority, score_breakdown, created_at, notified_at,
              first_seen_at, last_seen_at, analyzed_at, publication_date_source,
              publication_date_confidence, analysis_status, analysis_attempts
            )
            SELECT
              id, title, url, description, source, score, title_hash, canonical_url, fingerprint,
              company, location, published_at, stack, contract_type, freelance, remote, budget,
              recent, seniority, score_breakdown, created_at, notified_at,
              created_at, created_at, created_at, 'unknown',
              CASE WHEN published_at IS NULL THEN 'unknown' ELSE 'medium' END, 'done', 0
            FROM __temp__job
        SQL);
        $this->addSql('DROP TABLE __temp__job');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_url ON job (url)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_canonical_url ON job (canonical_url)');
        $this->addSql('CREATE INDEX idx_job_score ON job (score)');
        $this->addSql('CREATE INDEX idx_job_source ON job (source)');
        $this->addSql('CREATE INDEX idx_job_created_at ON job (created_at)');
        $this->addSql('CREATE INDEX idx_job_fingerprint ON job (fingerprint)');
        $this->addSql('CREATE INDEX idx_job_analysis ON job (analysis_status, next_analysis_attempt_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE job_source (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              provider VARCHAR(100) NOT NULL,
              url VARCHAR(2048) NOT NULL,
              canonical_url VARCHAR(2048) NOT NULL,
              external_id VARCHAR(255) DEFAULT NULL,
              reported_published_at DATETIME DEFAULT NULL,
              publication_date_source VARCHAR(20) NOT NULL,
              publication_date_confidence VARCHAR(10) NOT NULL,
              first_seen_at DATETIME NOT NULL,
              last_seen_at DATETIME NOT NULL,
              job_id INTEGER NOT NULL,
              CONSTRAINT FK_F8E52F2BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_job_source_job ON job_source (job_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_source_provider_url ON job_source (provider, canonical_url)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_source_external_id ON job_source (provider, external_id)');
        // Une source par offre existante. L'URL canonique de repli est l'URL brute : `job.canonical_url`
        // peut être NULL pour des lignes antérieures au backfill d'identité.
        $this->addSql(<<<'SQL'
            INSERT INTO job_source (
              provider, url, canonical_url, external_id, reported_published_at,
              publication_date_source, publication_date_confidence, first_seen_at, last_seen_at, job_id
            )
            SELECT
              source, url, COALESCE(canonical_url, url), NULL, published_at,
              publication_date_source, publication_date_confidence, created_at, created_at, id
            FROM job
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE provider_run (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              provider VARCHAR(100) NOT NULL,
              started_at DATETIME NOT NULL,
              duration_ms INTEGER NOT NULL,
              status VARCHAR(20) NOT NULL,
              fetched_count INTEGER NOT NULL,
              new_count INTEGER NOT NULL,
              duplicate_count INTEGER NOT NULL,
              error_count INTEGER NOT NULL,
              last_error CLOB DEFAULT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_provider_run_provider_started ON provider_run (provider, started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE provider_run');
        $this->addSql('DROP TABLE job_source');

        $this->addSql('CREATE TEMPORARY TABLE __temp__job AS SELECT * FROM job');
        $this->addSql('DROP TABLE job');
        $this->addSql(<<<'SQL'
            CREATE TABLE job (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              title VARCHAR(255) NOT NULL,
              url VARCHAR(2048) NOT NULL,
              description CLOB NOT NULL,
              source VARCHAR(100) NOT NULL,
              score INTEGER NOT NULL,
              title_hash VARCHAR(40) DEFAULT NULL,
              canonical_url VARCHAR(2048) DEFAULT NULL,
              fingerprint VARCHAR(64) DEFAULT NULL,
              company VARCHAR(255) DEFAULT NULL,
              location VARCHAR(255) DEFAULT NULL,
              published_at DATETIME DEFAULT NULL,
              stack CLOB NOT NULL,
              contract_type VARCHAR(20) NOT NULL,
              freelance BOOLEAN NOT NULL,
              remote BOOLEAN NOT NULL,
              budget VARCHAR(100) NOT NULL,
              recent BOOLEAN NOT NULL,
              seniority VARCHAR(20) NOT NULL,
              score_breakdown CLOB NOT NULL,
              created_at DATETIME NOT NULL,
              notified_at DATETIME DEFAULT NULL
            )
        SQL);
        // L'ancien schéma impose une empreinte unique : les doublons probables, désormais
        // conservés, perdent leur empreinte plutôt que d'être supprimés.
        $this->addSql(<<<'SQL'
            INSERT INTO job (
              id, title, url, description, source, score, title_hash, canonical_url, fingerprint,
              company, location, published_at, stack, contract_type, freelance, remote, budget,
              recent, seniority, score_breakdown, created_at, notified_at
            )
            SELECT
              id, title, url, description, source, score, title_hash, canonical_url,
              CASE WHEN probable_duplicate_of_id IS NULL THEN fingerprint ELSE NULL END,
              company, location, published_at, stack, contract_type, freelance, remote, budget,
              recent, seniority, score_breakdown, created_at, notified_at
            FROM __temp__job
        SQL);
        $this->addSql('DROP TABLE __temp__job');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_url ON job (url)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_canonical_url ON job (canonical_url)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_fingerprint ON job (fingerprint)');
        $this->addSql('CREATE INDEX idx_job_score ON job (score)');
        $this->addSql('CREATE INDEX idx_job_source ON job (source)');
        $this->addSql('CREATE INDEX idx_job_created_at ON job (created_at)');
    }
}
