<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 2.4 — journal des notifications envoyées (`notification_log`), pour ne notifier chaque offre qu'une fois.
 *
 * Crée uniquement `notification_log` ; aucune donnée existante n'est touchée. Les offres déjà notifiées par
 * l'ancien mécanisme restent marquées par `job.notified_at`, que le nouveau mécanisme respecte.
 */
final class Version20261201100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'notification_log : notifications envoyées par offre (immédiates et récapitulatifs)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_log (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              kind VARCHAR(10) NOT NULL,
              level VARCHAR(12) NOT NULL,
              search_profile_id VARCHAR(50) DEFAULT NULL,
              priority_score INTEGER NOT NULL,
              sent_at DATETIME NOT NULL,
              job_id INTEGER NOT NULL,
              CONSTRAINT FK_ED15DF2BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_ED15DF2BE04EA9 ON notification_log (job_id)');
        $this->addSql('CREATE INDEX idx_notification_log_sent_at ON notification_log (sent_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_log');
    }
}
