<?php declare(strict_types=1);

/*
 * This file is part of PHP CS Fixer: custom fixers.
 *
 * (c) 2018 Kuba Werłos
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace PhpCsFixerCustomFixers\Fixer;

use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

final class MultilineCommentOpeningClosingAloneFixer extends AbstractFixer
{
    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Multiline comments or PHPDocs must contain an opening and closing line with no additional content.',
            [new CodeSample("<?php\n/** Hello\n * World!\n */;\n")],
        );
    }

    /**
     * Must run before AlignMultilineCommentFixer, MultilineCommentOpeningClosingFixer.
     */
    public function getPriority(): int
    {
        return 28;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([\T_COMMENT, \T_DOC_COMMENT]);
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function fix(\SplFileInfo $file, Tokens $tokens): void
    {
        for ($index = $tokens->count() - 1; $index > 0; $index--) {
            if (!$tokens[$index]->isGivenKind([\T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }

            if (Preg::match('/\R/', $tokens[$index]->getContent()) !== 1) {
                continue;
            }

            $this->fixOpening($tokens, $index);
            $this->fixClosing($tokens, $index);
        }
    }

    private function fixOpening(Tokens $tokens, int $index): void
    {
        if (Preg::match('#^/\*+\R#', $tokens[$index]->getContent()) === 1) {
            return;
        }

        Preg::match('#\R(\h*)#', $tokens[$index]->getContent(), $matches);
        $indent = $matches[1] . '*';

        Preg::match('#^(?<opening>/\*+)(?<before_newline>.*?)(?<newline>\R)(?<after_newline>.*)$#s', $tokens[$index]->getContent(), $matches);
        if ($matches === []) {
            return;
        }

        $opening = $matches['opening'];
        \assert(\is_string($opening));

        $beforeNewline = $matches['before_newline'];
        \assert(\is_string($beforeNewline));

        $newline = $matches['newline'];
        \assert(\is_string($newline));

        $afterNewline = $matches['after_newline'];
        \assert(\is_string($afterNewline));

        if ($beforeNewline[0] !== ' ') {
            $indent .= ' ';
        }

        if (Preg::match('#^\h+$#', $beforeNewline) === 1) {
            $insert = '';
        } else {
            $insert = $newline . $indent . $beforeNewline;
        }

        $newContent = $opening . $insert . $newline . $afterNewline;

        if ($newContent !== $tokens[$index]->getContent()) {
            $tokens[$index] = new Token([Preg::match('~/\*{2}\s~', $newContent) === 1 ? \T_DOC_COMMENT : \T_COMMENT, $newContent]);
        }
    }

    private function fixClosing(Tokens $tokens, int $index): void
    {
        if (Preg::match('#\R\h*\*+/$#', $tokens[$index]->getContent()) === 1) {
            return;
        }

        Preg::match('#\R(\h*)#', $tokens[$index]->getContent(), $matches);

        $indent = $matches[1];
        \assert(\is_string($indent));

        $newContent = Preg::replace('#(\R)(.+?)\h*(\*+/)$#', \sprintf('$1$2$1%s$3', $indent), $tokens[$index]->getContent());

        if ($newContent !== $tokens[$index]->getContent()) {
            $id = $tokens[$index]->getId();
            \assert(\is_int($id));

            $tokens[$index] = new Token([$id, $newContent]);
        }
    }
}
