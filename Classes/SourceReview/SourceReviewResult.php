<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\SourceReview;

/** Stored machine assessment, kept separate from an editor's approval. */
final readonly class SourceReviewResult
{
    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    public static function fromJson(
        string $json,
        ?string $candidate = null,
    ): self {
        $decoded = json_decode($json, true);
        $data    = [];
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $data[$key] = $value;
                }
            }
        }

        if ($candidate !== null && in_array($data['status'] ?? null, ['passed', 'flagged'], true)) {
            $hash = $data['candidateHash'] ?? null;
            if (!is_string($hash) || !hash_equals($hash, hash('sha256', $candidate))) {
                $data['status'] = 'error';
                $data['reason'] = 'candidate_changed';
            }
        }

        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function getStatus(): string
    {
        $status = $this->data['status'] ?? null;

        return is_string($status) && in_array($status, ['passed', 'flagged', 'error', 'not_run'], true) ? $status : 'not_run';
    }

    public function getLabelKey(): string
    {
        return 'sourceReview.status.' . $this->getStatus();
    }

    public function getSeverity(): string
    {
        return match ($this->getStatus()) {
            'passed'  => 'success',
            'flagged' => 'warning',
            'error'   => 'danger',
            default   => 'secondary',
        };
    }

    public function getMode(): string
    {
        $mode = $this->data['mode'] ?? null;

        return is_string($mode) && in_array($mode, ['off', 'observe', 'enforce'], true) ? $mode : 'off';
    }

    public function getEvaluatedAt(): int
    {
        return is_int($this->data['evaluatedAt'] ?? null) ? $this->data['evaluatedAt'] : 0;
    }

    public function getModel(): string
    {
        return is_string($this->data['model'] ?? null) ? $this->data['model'] : '';
    }

    public function getProfile(): string
    {
        return is_string($this->data['profile'] ?? null) ? $this->data['profile'] : '';
    }

    public function getCandidateChanged(): bool
    {
        return ($this->data['reason'] ?? null) === 'candidate_changed';
    }

    /** Only fixed application labels are exposed; never raw provider failure text. */
    public function getReasonLabelKey(): string
    {
        if ($this->getStatus() === 'passed') {
            return '';
        }

        return 'sourceReview.reason.' . match ($this->data['reason'] ?? null) {
            'disabled'          => 'disabled',
            'no_evidence'       => 'noEvidence',
            'empty_candidate'   => 'emptyCandidate',
            'source_mismatch'   => 'sourceMismatch',
            'candidate_changed' => 'candidateChanged',
            default             => 'unavailable',
        };
    }
}
