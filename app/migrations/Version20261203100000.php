<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 4.2 — rappels de relance : `job_application.follow_up_reminded_at` (quand le rappel a été envoyé pour la date de relance courante).
 *
 * Non destructive : une colonne nullable ajoutée. Aucune donnée existante n'est modifiée ; aucun rappel ne part pour une relance existante
 * tant que `app:reminders:send` n'est pas lancé.
 */
final class Version20261203100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'job_application.follow_up_reminded_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_application ADD COLUMN follow_up_reminded_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_application DROP COLUMN follow_up_reminded_at');
    }
}
