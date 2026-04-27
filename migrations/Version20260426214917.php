<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426214917 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes on score, source, created_at and unique constraint on url';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_job_url ON job (url)');
        $this->addSql('CREATE INDEX idx_job_score ON job (score)');
        $this->addSql('CREATE INDEX idx_job_source ON job (source)');
        $this->addSql('CREATE INDEX idx_job_created_at ON job (created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_job_url');
        $this->addSql('DROP INDEX IF EXISTS idx_job_score');
        $this->addSql('DROP INDEX IF EXISTS idx_job_source');
        $this->addSql('DROP INDEX IF EXISTS idx_job_created_at');
    }
}
