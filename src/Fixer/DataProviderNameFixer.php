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
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Indicator\PhpUnitTestCaseIndicator;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixerCustomFixers\Analyzer\DataProviderAnalyzer;

final class DataProviderNameFixer extends AbstractFixer implements ConfigurableFixerInterface
{
    /** @var string */
    private $prefix = 'provide';

    /** @var string */
    private $suffix = 'Cases';

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Data provider names used only once must match the name of the test.',
            [
                new CodeSample(
                    '<?php
class FooTest extends TestCase {
    /**
     * @dataProvider dataProvider
     */
    public function testSomething($expected, $actual) {}
    public function dataProvider() {}
}
',
                ),
            ],
            null,
            'when relying on name of data provider function',
        );
    }

    public function getConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            (new FixerOptionBuilder('prefix', 'prefix that replaces "test"'))
                ->setAllowedTypes(['string'])
                ->setDefault($this->prefix)
                ->getOption(),
            (new FixerOptionBuilder('suffix', 'suffix to be added at the end"'))
                ->setAllowedTypes(['string'])
                ->setDefault($this->suffix)
                ->getOption(),
        ]);
    }

    /**
     * @param array<string, string> $configuration
     */
    public function configure(array $configuration): void
    {
        if (\array_key_exists('prefix', $configuration)) {
            $this->prefix = $configuration['prefix'];
        }

        if (\array_key_exists('suffix', $configuration)) {
            $this->suffix = $configuration['suffix'];
        }
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAllTokenKindsFound([\T_CLASS, \T_DOC_COMMENT, \T_EXTENDS, \T_FUNCTION, \T_STRING]);
    }

    public function isRisky(): bool
    {
        return true;
    }

    public function fix(\SplFileInfo $file, Tokens $tokens): void
    {
        $phpUnitTestCaseIndicator = new PhpUnitTestCaseIndicator();

        /** @var array<int> $indices */
        foreach ($phpUnitTestCaseIndicator->findPhpUnitClasses($tokens) as $indices) {
            $this->fixNames($tokens, $indices[0], $indices[1]);
        }
    }

    private function fixNames(Tokens $tokens, int $startIndex, int $endIndex): void
    {
        $dataProviderAnalyzer = new DataProviderAnalyzer();
        foreach ($dataProviderAnalyzer->getDataProviders($tokens, $startIndex, $endIndex) as $dataProviderAnalysis) {
            if (\count($dataProviderAnalysis->getUsageIndices()) > 1) {
                continue;
            }

            $usageIndex = $dataProviderAnalysis->getUsageIndices()[0];

            $testNameIndex = $tokens->getNextTokenOfKind($usageIndex, [[\T_STRING]]);
            \assert(\is_int($testNameIndex));

            $dataProviderNewName = $this->getProviderNameForTestName($tokens[$testNameIndex]->getContent());
            if ($tokens->findSequence([[\T_FUNCTION], [\T_STRING, $dataProviderNewName]], $startIndex, $endIndex) !== null) {
                continue;
            }

            $tokens[$dataProviderAnalysis->getNameIndex()] = new Token([\T_STRING, $dataProviderNewName]);

            $newCommentContent = Preg::replace(
                \sprintf('/(@dataProvider\s+)%s/', $dataProviderAnalysis->getName()),
                \sprintf('$1%s', $dataProviderNewName),
                $tokens[$usageIndex]->getContent(),
            );

            $tokens[$usageIndex] = new Token([\T_DOC_COMMENT, $newCommentContent]);
        }
    }

    private function getProviderNameForTestName(string $name): string
    {
        $name = Preg::replace('/^test_*/i', '', $name);

        if ($this->prefix === '') {
            $name = \lcfirst($name);
        } elseif (\substr($this->prefix, -1) !== '_') {
            $name = \ucfirst($name);
        }

        return $this->prefix . $name . $this->suffix;
    }
}
