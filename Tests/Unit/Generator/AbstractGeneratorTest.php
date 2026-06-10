<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrRepurpose\Generator\AbstractGenerator;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the lenient imageSize-hint resolution shared by all image-calling generators:
 * a layout snippet hint is honored only when syntactically valid ("WxH", 2-4 digits)
 * and divisible by 16; everything else falls back to the default with a warning —
 * a size hint must never fail an artifact.
 */
final class AbstractGeneratorTest extends TestCase
{
    /**
     * @return AbstractLogger&object{records: list<array{level: mixed, message: string}>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };
    }

    private function subject(AbstractLogger $logger): object
    {
        $jobs = new class extends JobProcessingRepository {
            public function __construct()
            {
                // Intentionally empty: bypasses the parent's ConnectionPool wiring —
                // this test never touches the database.
            }
        };
        $budget = new class implements BudgetServiceInterface {
            public function check(int $beUserUid, float $plannedCost = 0.0): BudgetCheckResult
            {
                return BudgetCheckResult::allowed();
            }
        };

        return new class($jobs, $budget, $logger) extends AbstractGenerator {
            public function supports(GenerationContext $ctx): bool
            {
                return false;
            }

            public function generate(GenerationContext $ctx): bool
            {
                return false;
            }

            public function expose(string $hint, string $default): string
            {
                return $this->resolveImageSize($hint, $default);
            }
        };
    }

    public function testEmptyHintFallsBackSilently(): void
    {
        $logger = $this->logger();

        self::assertSame('1536x1024', $this->subject($logger)->expose('', '1536x1024'));
        self::assertSame([], $logger->records);
    }

    public function testValidHintsDivisibleBySixteenAreUsed(): void
    {
        $logger = $this->logger();
        $subject = $this->subject($logger);

        self::assertSame('1920x1088', $subject->expose('1920x1088', '1536x1024'));
        self::assertSame('32x96', $subject->expose('32x96', '1536x1024'));
        self::assertSame('3840x2160', $subject->expose('3840x2160', '1536x1024'));
        self::assertSame([], $logger->records);
    }

    public function testInvalidHintsFallBackWithAWarning(): void
    {
        $invalid = [
            'nonsense',        // no WxH shape at all
            '1920x',           // missing height
            '1920x1080x2',     // trailing garbage
            '8x1080',          // width below the 2-digit minimum
            '19200x1080',      // width above the 4-digit maximum
            '1000x1088',       // width not divisible by 16
            '1920x1080',       // height not divisible by 16 (1080 = 67.5 * 16)
        ];

        foreach ($invalid as $hint) {
            $logger = $this->logger();
            self::assertSame('1536x1024', $this->subject($logger)->expose($hint, '1536x1024'), 'hint: ' . $hint);
            self::assertCount(1, $logger->records, 'hint: ' . $hint);
            self::assertSame('warning', $logger->records[0]['level'], 'hint: ' . $hint);
        }
    }
}
