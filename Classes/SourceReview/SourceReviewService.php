<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\SourceReview;

use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\QuestionType;
use Netresearch\NrLlm\Service\Decision\DecisionRequest;
use Netresearch\NrLlm\Service\Decision\DecisionServiceInterface;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Throwable;

/** No evidence is truncated or replaced with the generated brief. */
final readonly class SourceReviewService
{
    public function __construct(
        private DecisionServiceInterface $decisions,
        private SourceReviewPolicy $policy,
    ) {}

    /** @return array<string, mixed> */
    public function review(
        string $candidate,
        SourceDocument $document,
        int $beUser,
        string $scope,
        bool $forceEnforce = false,
    ): array {
        $review = [
            'status'                => 'not_run',
            'mode'                  => $forceEnforce ? 'enforce' : $this->policy->mode(),
            'candidateHash'         => hash('sha256', $candidate),
            'sourceHash'            => hash('sha256', self::sourceEvidence($document)),
            'profile'               => SourceReviewPolicy::PROFILE,
            'profileVersion'        => SourceReviewPolicy::VERSION,
            'configuration'         => $this->policy->configuration(),
            'configurationSelector' => $this->policy->configuration(),
            'dataClass'             => $this->policy->dataClass()->value,
            'minimumScore'          => $this->policy->minimumScore(),
            'model'                 => '',
            'provider'              => '',
            'evaluatedAt'           => null,
            'scope'                 => $scope,
            'reason'                => 'disabled',
            'answers'               => [],
        ];
        if ($review['mode'] === 'off') {
            return $review;
        }

        $review['status'] = 'error';
        $review['reason'] = 'no_evidence';
        if ($document->isEmpty()) {
            return $review;
        }

        $review['reason'] = 'empty_candidate';
        if (trim($candidate) === '') {
            return $review;
        }

        $review['reason'] = 'review_unavailable';
        if ($this->policy->configuration() === SourceReviewPolicy::UNAVAILABLE_CONFIGURATION) {
            return $review;
        }

        try {
            $this->decisions->assertAvailable(
                SourceReviewPolicy::PROFILE,
                $this->policy->configuration(),
            );
            $result = $this->decisions->evaluate(
                new DecisionRequest(
                    profile: SourceReviewPolicy::PROFILE,
                    subject: new DecisionSubject(
                        task: 'Compare the ' . $scope . ' with the original source. Only source fidelity is assessed. Finished audio, images and video have not been inspected.',
                        candidate: $candidate,
                        evidence: [self::sourceEvidence($document)],
                    ),
                    configuration: $this->policy->configuration(),
                    beUserUid: $beUser,
                    callerSourceExtension: 'nr_repurpose',
                    callerSourceOperation: 'source_review',
                ),
            );
            if ($result->profile !== SourceReviewPolicy::PROFILE || $result->profileVersion !== SourceReviewPolicy::VERSION) {
                $review['reason'] = 'unexpected_profile';

                return $review;
            }

            $passed = true;
            foreach (['supported', 'qualifications'] as $key) {
                $answer = $result->answer($key);
                if ($answer->type !== QuestionType::YesNo || $answer->value === null) {
                    $review['reason'] = 'invalid_answer';

                    return $review;
                }

                $review['answers'][$key] = $answer->value;
                $passed                  = $passed && $answer->value >= $this->policy->minimumScore();
            }

            $review['status']          = $passed ? 'passed' : 'flagged';
            $review['reason']          = $passed ? 'supported' : 'source_mismatch';
            $review['configuration']   = $result->configuration;
            $review['model']           = $result->model;
            $review['provider']        = $result->provider;
            $review['probabilityKind'] = $result->probabilityKind->value;
            $review['evaluatedAt']     = time();
        } catch (Throwable) {
            // Fixed code only: exception messages may contain source text, credentials or endpoints.
            return $review;
        }

        return $review;
    }

    public static function sourceEvidence(SourceDocument $document): string
    {
        return 'Source title: ' . $document->title . "\nSource attribution: " . $document->sourceLabel . "\n\nOriginal text:\n" . $document->text;
    }
}
