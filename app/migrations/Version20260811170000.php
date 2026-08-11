<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Processor\JobIdentity;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill canonical URLs for databases that ran the identity migration before its data fix';
    }

    public function up(Schema $schema): void
    {
        $identity = new JobIdentity();
        $seen = [];

        $this->addSql('UPDATE job SET canonical_url = NULL');
        /** @var array{id: int|string, url: string} $row */
        foreach ($this->connection->fetchAllAssociative('SELECT id, url FROM job ORDER BY id') as $row) {
            $canonicalUrl = $identity->canonicalUrl($row['url']);
            if (isset($seen[$canonicalUrl])) {
                continue;
            }

            $seen[$canonicalUrl] = true;
            $this->addSql('UPDATE job SET canonical_url = ? WHERE id = ?', [$canonicalUrl, (int) $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE job SET canonical_url = NULL');
        $this->addSql('UPDATE job SET canonical_url = url');
    }
}
