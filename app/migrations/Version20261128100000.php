<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 2.2 — exigences structurées d'une annonce, extraites par le LLM indépendamment du profil.
 *
 * Deux colonnes nullables, sans toucher aux données existantes : les offres déjà analysées
 * gardent `requirements` et `extraction_version` à NULL, et peuvent être ré-extraites à la demande
 * (`app:jobs:analyze --outdated`).
 */
final class Version20261128100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'job.requirements et job.extraction_version : exigences structurées et version du schéma d\'extraction';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job ADD COLUMN requirements CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE job ADD COLUMN extraction_version SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job DROP COLUMN requirements');
        $this->addSql('ALTER TABLE job DROP COLUMN extraction_version');
    }
}
