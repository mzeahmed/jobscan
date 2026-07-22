<?php

declare(strict_types=1);

namespace App\CsFixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Tokenizer\FCT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;

/**
 * Met un argument par ligne sur les attributs de méthode ayant 2 arguments ou plus
 * (ex: #[Route(path: ..., name: ..., methods: ...)]).
 *
 * Portée volontairement limitée : un seul attribut par groupe #[...] avec des
 * arguments (pas de #[Foo(...), Bar(...)] groupés) ; les groupes déjà répartis sur
 * plusieurs lignes ne sont pas touchés (idempotent).
 */
final class SplitMethodAttributeArgsFixer extends AbstractFixer implements WhitespacesAwareFixerInterface
{
    private const int MIN_ARGS_TO_SPLIT = 2;

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Met un argument par ligne sur les attributs de méthode ayant 2 arguments ou plus.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
<?php
class SomeController
{
    #[Route(path: '/host/rooms/{id}/edit', name: 'app_host_room_edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
    }
}

CODE_SAMPLE
                ),
            ]
        );
    }

    public function getName(): string
    {
        return 'CsFixer/split_method_attribute_args';
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(FCT::T_ATTRIBUTE);
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $groups = [];
        $index = 0;

        while (null !== $index = $tokens->getNextTokenOfKind($index, [[FCT::T_ATTRIBUTE]])) {
            $endIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ATTRIBUTE, $index);
            $groups[] = [$index, $endIndex];
            $index = $endIndex + 1;
        }

        // Traité de droite à gauche : les insertions dans un groupe ne décalent que
        // les index situés après lui, donc les groupes précédents (non traités) restent valides.
        foreach (array_reverse($groups) as [$startIndex, $endIndex]) {
            if (!$this->isMethodAttribute($tokens, $endIndex)) {
                continue;
            }

            $this->fixGroup($tokens, $startIndex, $endIndex);
        }
    }

    private function isMethodAttribute(Tokens $tokens, int $attributeCloseIndex): bool
    {
        $index = $attributeCloseIndex;

        while (true) {
            $index = $tokens->getNextMeaningfulToken($index);

            if (null === $index) {
                return false;
            }

            $token = $tokens[$index];

            if ($token->isGivenKind(\T_FUNCTION)) {
                return true;
            }

            if ($token->isGivenKind(FCT::T_ATTRIBUTE)) {
                $index = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ATTRIBUTE, $index);
                continue;
            }

            if ($token->isGivenKind([\T_PUBLIC, \T_PROTECTED, \T_PRIVATE, \T_STATIC, \T_FINAL, \T_ABSTRACT, \T_READONLY])) {
                continue;
            }

            return false;
        }
    }

    private function fixGroup(Tokens $tokens, int $startIndex, int $endIndex): void
    {
        $openParenIndex = null;

        for ($i = $startIndex + 1; $i < $endIndex; $i++) {
            $content = $tokens[$i]->getContent();

            if ('(' === $content) {
                $openParenIndex = $i;
                break;
            }

            // Virgule au premier niveau avant toute '(' => plusieurs attributs nus
            // regroupés (#[Foo, Bar(...)]) : hors périmètre, on laisse tel quel.
            if (',' === $content) {
                return;
            }
        }

        if (null === $openParenIndex) {
            return;
        }

        $closeParenIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $openParenIndex);

        // #[Foo(...), Bar(...)] : plusieurs attributs dans le même groupe, hors périmètre.
        $afterClose = $tokens->getNextMeaningfulToken($closeParenIndex);
        if (null !== $afterClose && $afterClose < $endIndex && $tokens[$afterClose]->equals(',')) {
            return;
        }

        $commaIndexes = $this->findTopLevelCommas($tokens, $openParenIndex, $closeParenIndex);

        if (\count($commaIndexes) < self::MIN_ARGS_TO_SPLIT - 1) {
            return;
        }

        if ($this->isAlreadyMultiline($tokens, $openParenIndex, $closeParenIndex)) {
            return;
        }

        $baseIndent = $this->getLineIndent($tokens, $startIndex);
        $argIndent = $baseIndent . $this->whitespacesConfig->getIndent();
        $lineEnding = $this->whitespacesConfig->getLineEnding();

        // Toujours droite à gauche, pour la même raison qu'au-dessus.
        $this->setWhitespaceBefore($tokens, $closeParenIndex, $lineEnding . $baseIndent);

        foreach (array_reverse($commaIndexes) as $commaIndex) {
            $this->setWhitespaceAfter($tokens, $commaIndex, $lineEnding . $argIndent);
        }

        $this->setWhitespaceAfter($tokens, $openParenIndex, $lineEnding . $argIndent);
    }

    /**
     * @return int[]
     */
    private function findTopLevelCommas(Tokens $tokens, int $openIndex, int $closeIndex): array
    {
        $commas = [];
        $depth = 0;

        for ($i = $openIndex + 1; $i < $closeIndex; $i++) {
            $content = $tokens[$i]->getContent();

            // Comparaison sur le contenu brut, pas Token::equals() : php-cs-fixer
            // réassigne un kind custom aux '['/']' de tableau littéral (CT::T_ARRAY_BRACKET_*),
            // donc equals('[') ne les matche pas alors qu'ils délimitent bien un niveau.
            if (\in_array($content, ['(', '[', '{'], true)) {
                $depth++;
                continue;
            }

            if (\in_array($content, [')', ']', '}'], true)) {
                $depth--;
                continue;
            }

            if (0 === $depth && ',' === $content) {
                $commas[] = $i;
            }
        }

        return $commas;
    }

    private function isAlreadyMultiline(Tokens $tokens, int $openIndex, int $closeIndex): bool
    {
        for ($i = $openIndex; $i <= $closeIndex; $i++) {
            if (str_contains($tokens[$i]->getContent(), "\n")) {
                return true;
            }
        }

        return false;
    }

    private function getLineIndent(Tokens $tokens, int $attributeStartIndex): string
    {
        $prevIndex = $attributeStartIndex - 1;

        if ($prevIndex < 0 || !$tokens[$prevIndex]->isWhitespace()) {
            return '';
        }

        $content = $tokens[$prevIndex]->getContent();
        $lastNewlinePos = strrpos($content, "\n");

        if (false === $lastNewlinePos) {
            return '';
        }

        return substr($content, $lastNewlinePos + 1);
    }

    private function setWhitespaceAfter(Tokens $tokens, int $index, string $whitespace): void
    {
        $nextIndex = $index + 1;

        if (isset($tokens[$nextIndex]) && $tokens[$nextIndex]->isWhitespace()) {
            $tokens[$nextIndex] = new Token([\T_WHITESPACE, $whitespace]);

            return;
        }

        $tokens->insertAt($nextIndex, new Token([\T_WHITESPACE, $whitespace]));
    }

    private function setWhitespaceBefore(Tokens $tokens, int $index, string $whitespace): void
    {
        $prevIndex = $index - 1;

        if (isset($tokens[$prevIndex]) && $tokens[$prevIndex]->isWhitespace()) {
            $tokens[$prevIndex] = new Token([\T_WHITESPACE, $whitespace]);

            return;
        }

        $tokens->insertAt($index, new Token([\T_WHITESPACE, $whitespace]));
    }
}
