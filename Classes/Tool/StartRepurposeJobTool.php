<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Service\JobSubmissionService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Starts one repurpose job from the backend chat: the same job the "Repurpose"
 * module's form creates, queued for the same worker.
 *
 * Registered through nr-llm's `nr_llm.tool` tag, which {@see ToolInterface}
 * applies by autoconfiguration. Declaring a write effect is what makes the
 * agent loop suspend for a human approval before every call; the approval card
 * is nr-llm's, this class adds no flow of its own.
 *
 * - **Who may call it:** a backend user with access to the module
 *   (`web_nrrepurpose`, the check the module itself is gated by); an
 *   administrator passes it. The job is owned by the acting user, never by the
 *   ambient `$GLOBALS['BE_USER']`, so the worker's per-owner capability grants
 *   (audio, vision) apply exactly as for a job created in the module.
 * - **Off by default:** a job spends provider money (text, speech, images), so
 *   an administrator switches the tool on in the nr-llm Tools module.
 * - **Sources:** a web page or a PDF URL. The URL is only shape-checked here;
 *   fetching it is the ingestion's job, behind its own host guard.
 * - **Artifacts** must be named by the caller: no hidden defaults decide what
 *   is generated and billed. The video is left out because it multiplies the
 *   cost (it needs the story and a render); it stays a module action.
 *
 * Effect: {@see ToolEffect::NON_IDEMPOTENT_WRITE}. A second call creates a
 * second job, so a reaped run must not be retried automatically.
 */
final readonly class StartRepurposeJobTool implements ToolInterface, ToolEffectInterface
{
    public const string NAME = 'start_repurpose_job';

    public const string GROUP = 'nr_repurpose';

    public const string MODULE = 'web_nrrepurpose';

    private const int MAX_URL_LENGTH = 2000;

    /** The artifact names on the wire, one per `want*` switch of the Job. */
    private const array ARTIFACTS = [
        'podcast',
        'schaubild',
        'story',
        'exec_summary',
        'faq',
        'social_post',
        'newsletter',
        'slide_deck',
        'handout',
    ];

    public function __construct(private JobSubmissionService $submission) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            self::NAME,
            'Start ONE repurpose job: turn a web page or a PDF (given by URL) into the chosen formats. The job is queued '
            . 'and runs in the background; it creates the same job as the Repurpose module (Web > Repurpose), where '
            . 'progress and results appear. The source is sent to the AI provider and generation costs money, so every '
            . 'call is shown to a person for approval. Choose only the artifacts the user asked for. The video is not '
            . 'available here.',
            [
                'type'       => 'object',
                'properties' => [
                    'source_url' => [
                        'type'        => 'string',
                        'description' => 'The http(s) URL of the web page or PDF to repurpose.',
                    ],
                    'source_type' => [
                        'type'        => 'string',
                        'enum'        => [SourceType::Url->value, SourceType::PdfUrl->value],
                        'description' => 'url for a web page (default), pdf_url for a PDF document.',
                    ],
                    'artifacts' => [
                        'type'        => 'array',
                        'minItems'    => 1,
                        'uniqueItems' => true,
                        'items'       => ['type' => 'string', 'enum' => self::ARTIFACTS],
                        'description' => 'The formats to generate. podcast (audio), schaubild (diagram), story (slide carousel), '
                            . 'exec_summary, faq, social_post, newsletter, slide_deck and handout (PDF).',
                    ],
                ],
                'required' => ['source_url', 'artifacts'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        $uid  = $context->actor->backendUserUid;
        if (!$user instanceof BackendUserAuthentication || $uid <= 0 || !$user->check('modules', self::MODULE)) {
            return ToolResult::error('Not permitted: starting a repurpose job needs a backend user with access to the Repurpose module.');
        }

        $url = $arguments['source_url'] ?? null;
        if (!is_string($url) || !$this->isFetchableUrl($url = trim($url))) {
            return ToolResult::error('source_url must be an absolute http(s) URL without credentials.');
        }

        $rawType = $arguments['source_type'] ?? SourceType::Url->value;
        $type    = is_string($rawType) ? SourceType::tryFrom($rawType) : null;
        if ($type !== SourceType::Url && $type !== SourceType::PdfUrl) {
            return ToolResult::error('source_type must be "url" or "pdf_url".');
        }

        $artifacts = $arguments['artifacts'] ?? null;
        if (!is_array($artifacts) || $artifacts === [] || !array_is_list($artifacts)) {
            return ToolResult::error('artifacts must be a non-empty list of formats: ' . implode(', ', self::ARTIFACTS) . '.');
        }

        foreach ($artifacts as $artifact) {
            if (!is_string($artifact) || !in_array($artifact, self::ARTIFACTS, true)) {
                return ToolResult::error('Unknown artifact. Choose from: ' . implode(', ', self::ARTIFACTS) . '.');
            }
        }

        $job = new Job();
        $job->setSourceTypeEnum($type);
        $job->setSourceValue($url);
        // The Job defaults switch podcast, schaubild and story on; the caller's list is the whole selection.
        $job->setWantPodcast(in_array('podcast', $artifacts, true));
        $job->setWantSchaubild(in_array('schaubild', $artifacts, true));
        $job->setWantStory(in_array('story', $artifacts, true));
        $job->setWantExecSummary(in_array('exec_summary', $artifacts, true));
        $job->setWantFaq(in_array('faq', $artifacts, true));
        $job->setWantSocialPost(in_array('social_post', $artifacts, true));
        $job->setWantNewsletter(in_array('newsletter', $artifacts, true));
        $job->setWantSlideDeck(in_array('slide_deck', $artifacts, true));
        $job->setWantHandout(in_array('handout', $artifacts, true));

        $jobUid = $this->submission->submit($job, $uid);

        return ToolResult::text(sprintf(
            'Started repurpose job #%d for %s (%s). It runs in the background; progress and results are in the Repurpose module (Web > Repurpose).',
            $jobUid,
            $url,
            implode(', ', array_values(array_unique($artifacts))),
        ));
    }

    public function isEnabledByDefault(): bool
    {
        return false;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        return self::GROUP;
    }

    public function getEffect(): ToolEffect
    {
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    private function isFetchableUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
