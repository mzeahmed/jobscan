<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 3.1 — candidatures (`job_application`, `job_application_status_change`) et décision utilisateur sur une offre
 * (`job.user_state`, `job.user_state_at`).
 *
 * Les tables s'appellent `job_application*` : une table `application` orpheline (ancienne fonctionnalité sans entité ni migration) peut exister dans
 * une base de développement ; elle n'est pas touchée.
 *
 * Non destructive : deux tables créées, deux colonnes nullables ajoutées à `job`. Aucune donnée existante n'est modifiée.
 */
final class Version20261202100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'job_application, job_application_status_change, job.user_state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job ADD COLUMN user_state VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE job ADD COLUMN user_state_at DATETIME DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE job_application (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              status VARCHAR(20) NOT NULL,
              search_profile_id VARCHAR(50) DEFAULT NULL,
              cv_version_id VARCHAR(50) DEFAULT NULL,
              cv_version_label VARCHAR(255) DEFAULT NULL,
              recruiter_name VARCHAR(255) DEFAULT NULL,
              recruiter_contact VARCHAR(255) DEFAULT NULL,
              channel VARCHAR(20) DEFAULT NULL,
              message_sent CLOB DEFAULT NULL,
              applied_at DATETIME DEFAULT NULL,
              last_contact_at DATETIME DEFAULT NULL,
              follow_up_at DATETIME DEFAULT NULL,
              responded_at DATETIME DEFAULT NULL,
              notes CLOB DEFAULT NULL,
              status_changed_at DATETIME NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              job_id INTEGER NOT NULL,
              CONSTRAINT FK_A45BDDC1BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_job_application_job ON job_application (job_id)');
        $this->addSql('CREATE INDEX idx_job_application_status ON job_application (status)');

        $this->addSql(<<<'SQL'
            CREATE TABLE job_application_status_change (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              from_status VARCHAR(20) DEFAULT NULL,
              to_status VARCHAR(20) NOT NULL,
              note VARCHAR(500) DEFAULT NULL,
              changed_at DATETIME NOT NULL,
              application_id INTEGER NOT NULL,
              CONSTRAINT FK_B7466A8E3E030ACD FOREIGN KEY (application_id) REFERENCES job_application (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_B7466A8E3E030ACD ON job_application_status_change (application_id)');
        $this->addSql('CREATE INDEX idx_job_application_status_change_changed_at ON job_application_status_change (changed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE job_application_status_change');
        $this->addSql('DROP TABLE job_application');
        $this->addSql('ALTER TABLE job DROP COLUMN user_state_at');
        $this->addSql('ALTER TABLE job DROP COLUMN user_state');
    }
}
