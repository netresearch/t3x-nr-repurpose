<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\ValueObject;

use Netresearch\NrRepurpose\Domain\ValueObject\PromptSnippetSelection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromptSnippetSelectionTest extends TestCase
{
    public function testJsonRoundTripPreservesEveryField(): void
    {
        $selection = new PromptSnippetSelection(
            audience: 1,
            tone: 2,
            personas: [3, 4, 5],
            schaubildLayout: 6,
            schaubildStyle: 7,
            storyLayout: 8,
            storyStyle: 9,
        );

        $restored = PromptSnippetSelection::fromJson($selection->toJson());

        self::assertSame(1, $restored->audience);
        self::assertSame(2, $restored->tone);
        self::assertSame([3, 4, 5], $restored->personas);
        self::assertSame(6, $restored->schaubildLayout);
        self::assertSame(7, $restored->schaubildStyle);
        self::assertSame(8, $restored->storyLayout);
        self::assertSame(9, $restored->storyStyle);
    }

    public function testToJsonUsesTheAgreedShape(): void
    {
        $selection = new PromptSnippetSelection(audience: 1, tone: 2, personas: [3], schaubildLayout: 4, schaubildStyle: 5, storyLayout: 6, storyStyle: 7);

        self::assertSame(
            ['audience' => 1, 'tone' => 2, 'personas' => [3], 'schaubild' => ['layout' => 4, 'style' => 5], 'story' => ['layout' => 6, 'style' => 7]],
            json_decode($selection->toJson(), true),
        );
    }

    public function testPersonasAreDeduplicatedZeroFreeAndCappedAtThree(): void
    {
        $selection = new PromptSnippetSelection(personas: [0, 7, 7, -1, 8, 9, 10]);

        self::assertSame([7, 8, 9], $selection->personas);
    }

    /** @return iterable<string, array{string}> */
    public static function degradedJsonProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'invalid json' => ['{nope'];
        yield 'scalar json' => ['42'];
        yield 'wrong field types' => ['{"audience":"abc","personas":"x","schaubild":5,"story":null}'];
    }

    #[DataProvider('degradedJsonProvider')]
    public function testFromJsonDegradesToEmptySelection(string $json): void
    {
        self::assertTrue(PromptSnippetSelection::fromJson($json)->isEmpty());
    }

    public function testFromJsonReadsPartialDocuments(): void
    {
        $selection = PromptSnippetSelection::fromJson('{"tone":4,"story":{"style":11}}');

        self::assertSame(0, $selection->audience);
        self::assertSame(4, $selection->tone);
        self::assertSame([], $selection->personas);
        self::assertSame(11, $selection->storyStyle);
        self::assertFalse($selection->isEmpty());
    }

    public function testIsEmptyOnlyForTheAllZeroSelection(): void
    {
        self::assertTrue((new PromptSnippetSelection())->isEmpty());
        self::assertFalse((new PromptSnippetSelection(audience: 1))->isEmpty());
        self::assertFalse((new PromptSnippetSelection(personas: [2]))->isEmpty());
        self::assertFalse((new PromptSnippetSelection(storyStyle: 3))->isEmpty());
    }

    public function testSelectedUidsAreUniqueAndSkipZeros(): void
    {
        $selection = new PromptSnippetSelection(
            audience: 1,
            tone: 0,
            personas: [3, 1],
            schaubildLayout: 6,
            schaubildStyle: 6,
            storyLayout: 0,
            storyStyle: 9,
        );

        self::assertSame([3, 1, 6, 9], $selection->selectedUids());
    }
}
