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

use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\FixerDefinition\VersionSpecification;
use PhpCsFixer\FixerDefinition\VersionSpecificCodeSample;
use PhpCsFixer\Tokenizer\Analyzer\WhitespacesAnalyzer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\WhitespacesFixerConfig;
use PhpCsFixerCustomFixers\Analyzer\ConstructorAnalyzer;

final class MultilinePromotedPropertiesFixer extends AbstractFixer implements ConfigurableFixerInterface, WhitespacesAwareFixerInterface
{
    private int $minimumNumberOfParameters = 1;
    private WhitespacesFixerConfig $whitespacesConfig;

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'A constructor with promoted properties must have them in separate lines.',
            [
                new VersionSpecificCodeSample(
                    '<?php class Foo {
    public function __construct(private array $a, private bool $b, private int $i) {}
}
',
                    new VersionSpecification(80000),
                ),
            ],
        );
    }

    public function getConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            (new FixerOptionBuilder('minimum_number_of_parameters', 'minimum number of parameters in the constructor to fix'))
                ->setAllowedTypes(['int'])
                ->setDefault($this->minimumNumberOfParameters)
                ->getOption(),
        ]);
    }

    /**
     * @param array<string, int> $configuration
     */
    public function configure(array $configuration): void
    {
        if (\array_key_exists('minimum_number_of_parameters', $configuration)) {
            $this->minimumNumberOfParameters = $configuration['minimum_number_of_parameters'];
        }
    }

    public function setWhitespacesConfig(WhitespacesFixerConfig $config): void
    {
        $this->whitespacesConfig = $config;
    }

    /**
     * Must run before BracesFixer.
     * Must run after PromotedConstructorPropertyFixer.
     */
    public function getPriority(): int
    {
        return 36;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([
            CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PRIVATE,
            CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PROTECTED,
            CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PUBLIC,
        ]);
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function fix(\SplFileInfo $file, Tokens $tokens): void
    {
        $constructorAnalyzer = new ConstructorAnalyzer();

        for ($index = $tokens->count() - 1; $index > 0; $index--) {
            if (!$tokens[$index]->isGivenKind(\T_CLASS)) {
                continue;
            }

            $constructorAnalysis = $constructorAnalyzer->findNonAbstractConstructor($tokens, $index);
            if ($constructorAnalysis === null) {
                continue;
            }

            $openParenthesis = $tokens->getNextTokenOfKind($constructorAnalysis->getConstructorIndex(), ['(']);
            \assert(\is_int($openParenthesis));
            $closeParenthesis = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $openParenthesis);

            if (!$this->shouldBeFixed($tokens, $openParenthesis, $closeParenthesis)) {
                continue;
            }

            $this->fixParameters($tokens, $openParenthesis, $closeParenthesis);
        }
    }

    private function shouldBeFixed(Tokens $tokens, int $openParenthesis, int $closeParenthesis): bool
    {
        $promotedParameterFound = false;
        $minimumNumberOfParameters = 0;
        for ($index = $openParenthesis + 1; $index < $closeParenthesis; $index++) {
            if ($tokens[$index]->isGivenKind(\T_VARIABLE)) {
                $minimumNumberOfParameters++;
            }
            if (
                $tokens[$index]->isGivenKind([
                    CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PRIVATE,
                    CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PROTECTED,
                    CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PUBLIC,
                ])
            ) {
                $promotedParameterFound = true;
            }
        }

        return $promotedParameterFound && $minimumNumberOfParameters >= $this->minimumNumberOfParameters;
    }

    private function fixParameters(Tokens $tokens, int $openParenthesis, int $closeParenthesis): void
    {
        $indent = WhitespacesAnalyzer::detectIndent($tokens, $openParenthesis);

        $tokens->ensureWhitespaceAtIndex(
            $closeParenthesis - 1,
            1,
            $this->whitespacesConfig->getLineEnding() . $indent,
        );

        $index = $tokens->getPrevMeaningfulToken($closeParenthesis);
        \assert(\is_int($index));

        while ($index > $openParenthesis) {
            $index = $tokens->getPrevMeaningfulToken($index);
            \assert(\is_int($index));

            $blockType = Tokens::detectBlockType($tokens[$index]);
            if ($blockType !== null && !$blockType['isStart']) {
                $index = $tokens->findBlockStart($blockType['type'], $index);
                continue;
            }

            if (!$tokens[$index]->equalsAny(['(', ','])) {
                continue;
            }

            $tokens->ensureWhitespaceAtIndex(
                $index + 1,
                0,
                $this->whitespacesConfig->getLineEnding() . $indent . $this->whitespacesConfig->getIndent(),
            );
        }
    }
}
