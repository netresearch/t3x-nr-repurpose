<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\ValueObject;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\Enum\PdfMode;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;
use Netresearch\NrRepurpose\Exception\MalformedJobRowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobSnapshotTest extends TestCase
{
    private const string SNIPPETS = '{"audience":3,"tone":4,"personas":[5,6],"schaubild":{"layout":7,"style":8},"story":{"layout":9,"style":10}}';

    /** The flag columns and the artifact type each one requests. */
    private const array FLAGS = [
        'want_podcast'      => ArtifactType::Podcast,
        'want_schaubild'    => ArtifactType::Schaubild,
        'want_story'        => ArtifactType::Story,
        'want_video'        => ArtifactType::Video,
        'want_exec_summary' => ArtifactType::ExecutiveSummary,
        'want_faq'          => ArtifactType::Faq,
        'want_social_post'  => ArtifactType::SocialPost,
        'want_newsletter'   => ArtifactType::Newsletter,
        'want_slide_deck'   => ArtifactType::SlideDeck,
        'want_handout'      => ArtifactType::Handout,
    ];

    /**
     * A full row as each database driver hands it over: MariaDB (PDO) returns
     * integer columns as strings, SQLite as ints.
     *
     * @return iterable<string, array{array<string,mixed>}>
     */
    public static function fullRows(): iterable
    {
        $row = [
            'uid'             => 42,
            'pid'             => 0,
            'status'          => 'generating',
            'source_type'     => 'pdf_fal',
            'source_value'    => 'https://example.com/report.pdf',
            'source_pdf'      => 9,
            'pdf_mode'        => 'tables',
            'theme'           => 'neutral',
            'be_user'         => 7,
            'prompt_snippets' => self::SNIPPETS,
            'progress'        => 30,
            'error_message'   => null,
        ];
        foreach (array_keys(self::FLAGS) as $i => $column) {
            $row[$column] = $i % 2;
        }

        yield 'SQLite ints' => [$row];
        yield 'MariaDB strings' => [array_map(static fn (mixed $value): mixed => is_int($value) ? (string) $value : $value, $row)];
    }

    /** @param array<string,mixed> $row */
    #[DataProvider('fullRows')]
    public function testFromRowCastsEveryColumn(array $row): void
    {
        $job = JobSnapshot::fromRow($row);

        self::assertSame(42, $job->uid);
        self::assertSame(JobStatus::Generating, $job->status);
        self::assertSame(SourceType::PdfFal, $job->sourceType);
        self::assertSame('https://example.com/report.pdf', $job->sourceValue);
        self::assertSame(PdfMode::Tables, $job->pdfMode);
        self::assertSame('neutral', $job->theme);
        self::assertSame(7, $job->beUser);
        self::assertSame(3, $job->promptSnippets->audience);
        self::assertSame(4, $job->promptSnippets->tone);
        self::assertSame([5, 6], $job->promptSnippets->personas);
        self::assertSame(7, $job->promptSnippets->schaubildLayout);
        self::assertSame(8, $job->promptSnippets->schaubildStyle);
        self::assertSame(9, $job->promptSnippets->storyLayout);
        self::assertSame(10, $job->promptSnippets->storyStyle);
        self::assertSame(
            [false, true, false, true, false, true, false, true, false, true],
            [
                $job->wantPodcast, $job->wantSchaubild, $job->wantStory, $job->wantVideo, $job->wantExecSummary,
                $job->wantFaq, $job->wantSocialPost, $job->wantNewsletter, $job->wantSlideDeck, $job->wantHandout,
            ],
        );
    }

    public function testMissingColumnsTakeThePipelineDefaults(): void
    {
        $job = JobSnapshot::fromRow(['uid' => 5]);

        self::assertSame(5, $job->uid);
        self::assertSame(JobStatus::Queued, $job->status);
        self::assertSame(SourceType::Url, $job->sourceType);
        self::assertSame('', $job->sourceValue);
        self::assertSame(PdfMode::Auto, $job->pdfMode);
        self::assertSame('nr', $job->theme);
        self::assertSame(0, $job->beUser);
        self::assertTrue($job->promptSnippets->isEmpty());
        foreach (self::FLAGS as $type) {
            self::assertFalse($job->wants($type), $type->value);
        }
    }

    public function testNullColumnsTakeThePipelineDefaults(): void
    {
        $row = ['uid' => 5];
        foreach (['status', 'source_type', 'source_value', 'pdf_mode', 'theme', 'be_user', 'prompt_snippets', ...array_keys(self::FLAGS)] as $column) {
            $row[$column] = null;
        }

        self::assertEquals(JobSnapshot::fromRow(['uid' => 5]), JobSnapshot::fromRow($row));
    }

    public function testAnUnknownPdfModeFallsBackToAutoAsBefore(): void
    {
        self::assertSame(PdfMode::Auto, JobSnapshot::fromRow(['uid' => 1, 'pdf_mode' => 'ocr'])->pdfMode);
        self::assertSame(PdfMode::Auto, JobSnapshot::fromRow(['uid' => 1, 'pdf_mode' => ''])->pdfMode);
    }

    public function testAnInvalidSnippetDocumentDegradesToNoSelectionAsBefore(): void
    {
        self::assertTrue(JobSnapshot::fromRow(['uid' => 1, 'prompt_snippets' => '{not json'])->promptSnippets->isEmpty());
    }

    /** @return iterable<string, array{string, ArtifactType}> */
    public static function flags(): iterable
    {
        foreach (self::FLAGS as $column => $type) {
            yield $column => [$column, $type];
        }
    }

    #[DataProvider('flags')]
    public function testWantsReadsOnlyTheColumnOfItsArtifactType(string $column, ArtifactType $type): void
    {
        $job = JobSnapshot::fromRow(['uid' => 1, $column => 1]);

        foreach (ArtifactType::cases() as $candidate) {
            self::assertSame($candidate === $type, $job->wants($candidate), $candidate->value);
        }
    }

    public function testTheStubIsNeverWanted(): void
    {
        $row = ['uid' => 1];
        foreach (array_keys(self::FLAGS) as $column) {
            $row[$column] = 1;
        }

        self::assertFalse(JobSnapshot::fromRow($row)->wants(ArtifactType::Stub));
    }

    /**
     * @return iterable<string, array{array<string,mixed>, int, string}>
     */
    public static function malformedRows(): iterable
    {
        yield 'uid missing' => [['status' => 'queued'], 1790500004, 'uid'];
        yield 'uid null' => [['uid' => null], 1790500004, 'uid'];
        yield 'uid 0' => [['uid' => 0], 1790500001, 'uid'];
        yield 'uid "0"' => [['uid' => '0'], 1790500001, 'uid'];
        yield 'uid negative' => [['uid' => -3], 1790500004, 'uid'];
        yield 'uid not digits' => [['uid' => '12abc'], 1790500004, 'uid'];
        yield 'uid empty string' => [['uid' => ''], 1790500004, 'uid'];
        yield 'uid float' => [['uid' => 1.5], 1790500004, 'uid'];
        yield 'status unknown' => [['uid' => 1, 'status' => 'paused'], 1790500002, 'status'];
        yield 'status not a string' => [['uid' => 1, 'status' => 3], 1790500005, 'status'];
        yield 'source_type unknown' => [['uid' => 1, 'source_type' => 'docx'], 1790500003, 'source_type'];
        yield 'source_value not a string' => [['uid' => 1, 'source_value' => ['https://intranet.example/secret.pdf']], 1790500005, 'source_value'];
        yield 'pdf_mode not a string' => [['uid' => 1, 'pdf_mode' => 2], 1790500005, 'pdf_mode'];
        yield 'theme not a string' => [['uid' => 1, 'theme' => false], 1790500005, 'theme'];
        yield 'be_user not digits' => [['uid' => 1, 'be_user' => 'admin'], 1790500004, 'be_user'];
        yield 'prompt_snippets not a string' => [['uid' => 1, 'prompt_snippets' => ['audience' => 3]], 1790500005, 'prompt_snippets'];
        yield 'want flag not digits' => [['uid' => 1, 'want_video' => 'yes'], 1790500004, 'want_video'];
        yield 'want flag a bool' => [['uid' => 1, 'want_faq' => true], 1790500004, 'want_faq'];
    }

    /** @param array<string,mixed> $row */
    #[DataProvider('malformedRows')]
    public function testFromRowRejectsAMalformedRowNamingTheColumnOnly(array $row, int $code, string $column): void
    {
        try {
            JobSnapshot::fromRow($row);
            self::fail('Expected a MalformedJobRowException');
        } catch (MalformedJobRowException $e) {
            self::assertSame($code, $e->getCode());
            self::assertStringContainsString('"' . $column . '"', $e->getMessage());
            self::assertStringNotContainsString('intranet.example', $e->getMessage());
        }
    }
}
