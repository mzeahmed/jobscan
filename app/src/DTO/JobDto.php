<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class JobDto
{
    /**
     * @param string|null $externalId Identifiant de l'offre chez le provider, s'il en fournit un stable
     */
    public function __construct(
        public string $title,
        public string $url,
        public string $description,
        public string $source,
        public ?\DateTimeImmutable $publishedAt = null,
        public ?string $company = null,
        public ?string $location = null,
        public ?string $externalId = null,
        public PublicationDateSource $publicationDateSource = PublicationDateSource::Unknown,
        public PublicationDateConfidence $publicationDateConfidence = PublicationDateConfidence::Unknown,
    ) {
    }
}
