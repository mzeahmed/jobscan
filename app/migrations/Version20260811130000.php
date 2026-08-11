<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Processor\JobIdentity;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist AI analysis and replace title-only deduplication with canonical identities';
    }

    public function up(Schema $schema): void
    {
        $canonicalUrls = $this->canonicalUrlsForExistingJobs();

        $this->addSql('CREATE TEMPORARY TABLE __temp__job AS SELECT * FROM job');
        $this->addSql('DROP TABLE job');
        $this->addSql('CREATE TABLE job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, url VARCHAR(2048) NOT NULL, description CLOB NOT NULL, source VARCHAR(100) NOT NULL, score INTEGER NOT NULL, title_hash VARCHAR(40) DEFAULT NULL, canonical_url VARCHAR(2048) DEFAULT NULL, fingerprint VARCHAR(64) DEFAULT NULL, company VARCHAR(255) DEFAULT NULL, location VARCHAR(255) DEFAULT NULL, published_at DATETIME DEFAULT NULL, stack CLOB NOT NULL, contract_type VARCHAR(20) NOT NULL, freelance BOOLEAN NOT NULL, remote BOOLEAN NOT NULL, budget VARCHAR(100) NOT NULL, recent BOOLEAN NOT NULL, seniority VARCHAR(20) NOT NULL, score_breakdown CLOB NOT NULL, created_at DATETIME NOT NULL, notified_at DATETIME DEFAULT NULL)');
        $this->addSql("INSERT INTO job (id, title, url, description, source, score, title_hash, canonical_url, stack, contract_type, freelance, remote, budget, recent, seniority, score_breakdown, created_at, notified_at) SELECT id, title, url, description, source, score, title_hash, url, '[]', 'unknown', 0, 0, 'non précisé', 0, 'unknown', '[]', created_at, notified_at FROM __temp__job");
        $this->addSql('DROP TABLE __temp__job');
        foreach ($canonicalUrls as $id => $canonicalUrl) {
            $this->addSql(
                'UPDATE job SET canonical_url = ? WHERE id = ?',
                [$canonicalUrl, $id],
            );
        }
        $this->addSql('CREATE UNIQUE INDEX uniq_job_url ON job (url)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_canonical_url ON job (canonical_url)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_fingerprint ON job (fingerprint)');
        $this->addSql('CREATE INDEX idx_job_score ON job (score)');
        $this->addSql('CREATE INDEX idx_job_source ON job (source)');
        $this->addSql('CREATE INDEX idx_job_created_at ON job (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__job AS SELECT * FROM job');
        $this->addSql('DROP TABLE job');
        $this->addSql('CREATE TABLE job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, url VARCHAR(2048) NOT NULL, description CLOB NOT NULL, source VARCHAR(100) NOT NULL, score INTEGER NOT NULL, title_hash VARCHAR(40) DEFAULT NULL, created_at DATETIME NOT NULL, notified_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO job (id, title, url, description, source, score, title_hash, created_at, notified_at) SELECT id, title, url, description, source, score, title_hash, created_at, notified_at FROM __temp__job');
        $this->addSql('DROP TABLE __temp__job');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_url ON job (url)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_title_hash ON job (title_hash)');
        $this->addSql('CREATE INDEX idx_job_score ON job (score)');
        $this->addSql('CREATE INDEX idx_job_source ON job (source)');
        $this->addSql('CREATE INDEX idx_job_created_at ON job (created_at DESC)');
    }

    /** @return array<int, string|null> */
    private function canonicalUrlsForExistingJobs(): array
    {
        $identity = new JobIdentity();
        $canonicalUrls = [];
        $seen = [];

        /** @var array{id: int|string, url: string} $row */
        foreach ($this->connection->fetchAllAssociative('SELECT id, url FROM job ORDER BY id') as $row) {
            $canonicalUrl = $identity->canonicalUrl($row['url']);
            $id = (int) $row['id'];

            if (isset($seen[$canonicalUrl])) {
                $canonicalUrls[$id] = null;
                continue;
            }

            $seen[$canonicalUrl] = true;
            $canonicalUrls[$id] = $canonicalUrl;
        }

        return $canonicalUrls;
    }
}
