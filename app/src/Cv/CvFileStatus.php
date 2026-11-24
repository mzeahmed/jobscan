<?php

declare(strict_types=1);

namespace App\Cv;

/**
 * État du fichier d'une version de CV, établi sans le lire (taille et date de modification seulement).
 */
final readonly class CvFileStatus
{
    public const string NOT_DECLARED = 'not_declared';
    public const string MISSING = 'missing';
    public const string UNREADABLE = 'unreadable';
    public const string EMPTY = 'empty';
    public const string OK = 'ok';

    private function __construct(
        public string $state,
        public ?string $resolvedPath,
        public ?int $size,
        public ?\DateTimeImmutable $modifiedAt,
    ) {
    }

    public static function notDeclared(): self
    {
        return new self(self::NOT_DECLARED, null, null, null);
    }

    public static function problem(string $state, string $resolvedPath): self
    {
        return new self($state, $resolvedPath, null, null);
    }

    public static function ok(string $resolvedPath, int $size, \DateTimeImmutable $modifiedAt): self
    {
        return new self(self::OK, $resolvedPath, $size, $modifiedAt);
    }

    public function isUsable(): bool
    {
        return $this->state === self::OK;
    }

    public function label(): string
    {
        return match ($this->state) {
            self::OK => 'fichier présent',
            self::MISSING => 'fichier introuvable',
            self::UNREADABLE => 'fichier illisible',
            self::EMPTY => 'fichier vide',
            default => 'chemin non renseigné',
        };
    }
}
