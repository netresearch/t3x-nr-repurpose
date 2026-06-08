# Cross-stack API grounding (verified 2026-06-08)

Source-cited facts + snippets from a 6-agent verification fan-out (nr-llm, TYPO3 v14.3 Messenger, v14.3 BE module, HTML→PNG, PDF, DDEV). Used to ground the implementation plans. Every fact carries a file:line or URL citation.

---


=================================================================
AREA: netresearch/nr-llm public service API (Feature services, Options, Specialized Sp
=================================================================

--- VERIFIED FACTS ---
[0] Composer package name is `netresearch/nr-llm`; requires php ^8.2 and typo3/cms-core ^13.4 || ^14.0 (composer.json:2,34,39). Root namespace `Netresearch\NrLlm\`.

[1] DI: ALL public-facing services are registered with `public: true` and an interface alias in Configuration/Services.yaml. A consumer constructor should type-hint the INTERFACE. Public interfaces aliased: LlmServiceManagerInterface (Services.yaml:33), CompletionServiceInterface (:83), VisionServiceInterface (:90), EmbeddingServiceInterface (:97), TranslationServiceInterface (:104), BudgetServiceInterface (:171). CapabilityPermissionServiceInterface is aliased but PRIVATE (:143-144, no public:true) so a consumer cannot autowire it by interface unless it overrides visibility; the concrete CapabilityPermissionService is auto-registered private too. Specialized services DallEImageService, FalImageService, TextToSpeechService, WhisperTranscriptionService are registered public:true by CONCRETE CLASS (no interface) at Services.yaml:224-238.

[2] Consumer Services.yaml: with standard `autowire:true/autoconfigure:true` defaults, NOTHING extra needs registering to inject the public interfaces (LlmServiceManagerInterface, CompletionServiceInterface, VisionServiceInterface, BudgetServiceInterface) or the public concrete Specialized classes. EXCEPTION: to inject CapabilityPermissionServiceInterface the consumer must add a public alias in its own Services.yaml (the nr_llm alias is private).

[3] (1) CompletionService implements CompletionServiceInterface (CompletionService.php:36). Constructor: __construct(private LlmServiceManagerInterface $llmManager, private ?BackendUserContextResolverInterface $beUserContextResolver = null) (:40-43). Public methods: complete(string $prompt, ?ChatOptions $options = null): CompletionResponse (:52); completeJson(string $prompt, ?ChatOptions $options = null): array<string,mixed> (:121, decodes JSON, throws InvalidArgumentException on bad JSON); completeMarkdown(...): string (:155); completeFactual(...): CompletionResponse (:174); completeCreative(...): CompletionResponse (:193). Interface only declares complete/completeJson/completeMarkdown/completeFactual/completeCreative (CompletionServiceInterface.php:31-55).

[4] System prompt is passed via ChatOptions: constructor param `?string $systemPrompt = null` (ChatOptions.php:33) or fluent withSystemPrompt(string): static (:168). Structured JSON output: set responseFormat='json' via ChatOptions constructor param `?string $responseFormat` (:32), withResponseFormat('json') (:160), the ChatOptions::json() preset (:92, temperature 0.3 + json), or just call CompletionService::completeJson() which forces it and returns a decoded array. response_format is validated to one of text|json|markdown (:24,326). Internally CompletionService maps 'json' to ['type'=>'json_object'] for the provider (CompletionService.php:268).

[5] Tool/function-calling is NOT on CompletionService. It is LlmServiceManager::chatWithTools(array $messages, array $tools, ?ToolOptions $options = null): CompletionResponse (LlmServiceManager.php:339; declared in LlmServiceManagerInterface.php:122). $tools is list<ToolSpec|array>. ToolSpec VO: __construct(string $name, string $description, array $parameters /*JSON-Schema*/, string $type='function') with static ToolSpec::function(name,description,parameters) factory (ToolSpec.php:50-83). ToolOptions extends ChatOptions adding ?string $toolChoice (auto|none|required) and ?bool $parallelToolCalls, with presets auto()/required()/noTools()/parallel() (ToolOptions.php:19-106). Provider must implement ToolCapableInterface or UnsupportedFeatureException is thrown (LlmServiceManager.php:357).

[6] (2) LlmServiceManager implements LlmServiceManagerInterface + SingletonInterface (LlmServiceManager.php:42). Facade methods: complete(string $prompt, ?ChatOptions $options=null): CompletionResponse (:179); chat(array $messages, ?ChatOptions $options=null): CompletionResponse (:159, $messages is list<ChatMessage|array>); streamChat(array $messages, ?ChatOptions $options=null): Generator<int,string,mixed,void> (:310, requires StreamingCapableInterface else UnsupportedFeatureException); vision(array $content, ?VisionOptions $options=null): VisionResponse (:269); embed(string|array $input, ?EmbeddingOptions=null): EmbeddingResponse (:199); chatWithTools(...) (:339). ChatMessage VOs built via ChatMessage::system($s) and ChatMessage::user($s) (used in CompletionService.php:67,70).

[7] (3) VisionService implements VisionServiceInterface (VisionService.php:29). Constructor identical shape to CompletionService: __construct(private LlmServiceManagerInterface $llmManager, private ?BackendUserContextResolverInterface $beUserContextResolver=null) (:37-40). Methods: generateAltText / generateTitle / generateDescription(string|array $imageUrl, ?VisionOptions=null): string|array (:52,79,106); analyzeImage(string|array $imageUrl, string $customPrompt, ?VisionOptions=null): string|array (:133); analyzeImageFull(string $imageUrl, string $prompt, ?VisionOptions=null): VisionResponse (:155 — single image, returns full response with usage). $imageUrl accepts a remote URL OR a base64 data URI matching ^data:image/(png|jpeg|jpg|gif|webp);base64, (validated VisionService.php:235-247). VisionResponse exposes ->description / getText() / getDescription() / ->usage / ->confidence (VisionResponse.php).

[8] (3) VisionOptions extends AbstractOptions implements BudgetAwareOptionsInterface (VisionOptions.php:17). Constructor: __construct(?string $detailLevel=null /*auto|low|high*/, ?int $maxTokens=null, ?float $temperature=null, ?string $provider=null, ?string $model=null, ?int $beUserUid=null, ?float $plannedCost=null) (:23-31). Presets: altText(), detailed(), quick(), comprehensive() (:43-86). detailLevel maps to OpenAI image detail. Base64/binary images are supplied by encoding into a data: URI string passed as $imageUrl (no separate binary param).

[9] (4) TextToSpeechService extends AbstractSpecializedService (TextToSpeechService.php:37) — NO interface; uses OpenAI TTS at https://api.openai.com/v1/audio/speech. synthesize(string $text, SpeechSynthesisOptions|array $options=[]): SpeechSynthesisResult (:73, max 4096 chars else ServiceUnavailableException); synthesizeToFile(string $text, string $outputPath, SpeechSynthesisOptions|array=[]): SpeechSynthesisResult (:131); synthesizeLong(string $text, SpeechSynthesisOptions|array=[]): array<int,SpeechSynthesisResult> (:160, splits at sentence boundaries). Tracks usage itself via $this->usageTracker->trackUsage('speech',...) (:105).

[10] (4) SpeechSynthesisOptions (final, extends AbstractOptions, SpeechSynthesisOptions.php:17). Constructor: __construct(?string $model='tts-1' [tts-1|tts-1-hd], ?string $voice='alloy' [alloy|echo|fable|onyx|nova|shimmer], ?string $format='mp3' [mp3|opus|aac|flac|wav|pcm], ?float $speed=1.0 [0.25-4.0]) (:23-28). Factories hd($voice) / fast($voice) / fromArray(). NOTE toArray() emits 'response_format' key for the format (:48). SpeechSynthesisResult (final readonly, SpeechSynthesisResult.php:15): public string $audioContent (raw binary), $format, $model, $voice, int $characterCount, ?array $metadata; helpers getSize(), getMimeType(), getFileExtension(), saveToFile(string $path):bool, toBase64(), toDataUrl(), isHd().

[11] (5) FalImageService extends AbstractSpecializedService (FalImageService.php:31) — NO interface; FAL.ai at https://fal.run + queue at https://queue.fal.run. generate(string $prompt, string $model='flux-schnell', array $options=[]): ImageGenerationResult (:76); generateMultiple(string $prompt, int $count=1, string $model='flux-schnell', array $options=[]): array<int,ImageGenerationResult> (:128, count clamped 1-4); imageToImage(string $imageUrl, string $prompt, string $model='flux-dev', array $options=[]): ImageGenerationResult (:188 — sets options['image_url']=$imageUrl and strength default 0.75, then delegates to generate). $options keys: image_size|width+height, num_images, guidance_scale, num_inference_steps, seed, negative_prompt, enable_safety_checker, strength (FalImageService.php:62-71,354-409). Model ids: flux-pro|flux-dev|flux-schnell|sdxl|sd3|playground or raw 'org/model'. FAL returns URLs only (base64 is null on the result).

[12] (5) FAL imageToImage takes a SOURCE IMAGE URL string, not a local path. For a local PNG the consumer must turn it into a URL FAL can fetch — either a publicly reachable https URL or a data: URI (FAL accepts data URIs). There is no built-in local-file upload in FalImageService.

[13] (5) DallEImageService extends AbstractSpecializedService (DallEImageService.php:32) — NO interface; OpenAI images at https://api.openai.com/v1/images. generate(string $prompt, ImageGenerationOptions|array $options=[]): ImageGenerationResult (:70); generateMultiple(string $prompt, int $count=1, ImageGenerationOptions|array=[]): array (:126); createVariations(string $imagePath, int $count=1, string $size='1024x1024'): array (:200, DALL-E2, local PNG path, <4MB); edit(string $imagePath, string $prompt, ?string $maskPath=null, string $size='1024x1024'): ImageGenerationResult (:253, DALL-E2). ImageGenerationOptions (final, ImageGenerationOptions.php:18): __construct(?string $model='dall-e-3', ?string $size='1024x1024', ?string $quality='standard'[standard|hd], ?string $style='vivid'[vivid|natural], ?string $format='url'[url|b64_json]) with size validated per model.

[14] (5) ImageGenerationResult (final readonly, ImageGenerationResult.php:15): __construct(string $url, ?string $base64, string $prompt, ?string $revisedPrompt, string $model, string $size, string $provider, ?array $metadata=null). Helpers: hasBase64(), getBinaryContent():?string, toDataUrl($mime='image/png'):?string, saveToFile(string $path):bool (downloads from $url if no base64), downloadFromUrl():?string, getDimensions()/getWidth()/getHeight()/isLandscape()/isPortrait()/isSquare(), wasPromptRevised(), getEffectivePrompt().

[15] All Specialized services share AbstractSpecializedService::__construct(ClientInterface $httpClient, RequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory, ExtensionConfiguration $extensionConfiguration, UsageTrackerServiceInterface $usageTracker, LoggerInterface $logger) (AbstractSpecializedService.php:53-60) — all autowired by TYPO3 core, so a consumer just type-hints the concrete class. isAvailable():bool returns true only when an API key was found in `nr_llm` extension config (:71). API keys/baseUrls are read from extension config branches (providers.openai.apiKey for DALL-E/TTS, image.fal.apiKey for FAL) — NOT passed per call.

[16] (6) BudgetService implements BudgetServiceInterface (BudgetService.php:38). Interface method: check(int $beUserUid, float $plannedCost = 0.0): BudgetCheckResult (BudgetServiceInterface.php:33). It is a PURE PRE-FLIGHT CHECK that returns a result object — it does NOT throw. Returns BudgetCheckResult::allowed() when uid<=0, no record, inactive, or no limit hit; otherwise BudgetCheckResult::denied(...). Consumer guards a call by: if(!$this->budgetService->check($uid,$cost)->allowed){...}. BudgetCheckResult (final readonly, BudgetCheckResult.php:19): public bool $allowed, string $exceededLimit (LIMIT_* const), float $currentUsage, float $limit, string $reason.

[17] (6) The over-budget SIGNAL inside the pipeline is BudgetExceededException (final, extends RuntimeException, BudgetExceededException.php:23) carrying public readonly BudgetCheckResult $result. It is thrown by BudgetMiddleware::handle() (BudgetMiddleware.php:78) when ChatOptions/VisionOptions/etc carry a beUserUid and the check fails. The Feature services / LlmServiceManager opt into this by setting beUserUid+plannedCost on the options (metadata keys BudgetMiddleware::METADATA_BE_USER_UID='beUserUid', METADATA_PLANNED_COST='plannedCost', BudgetMiddleware.php:55-56). Specialized (TTS/FAL/DALL-E) calls do NOT pass through this middleware — no automatic budget guard there.

[18] (6) Budget-aware options: ChatOptions/ToolOptions/VisionOptions/EmbeddingOptions/TranslationOptions accept ?int $beUserUid and ?float $plannedCost (last two ctor params) via BudgetFieldsTrait (BudgetFieldsTrait.php), with withBeUserUid(int)/withPlannedCost(float) fluent setters. beUserUid must be >=0 (0 = anonymous/skip), plannedCost >=0.0 else InvalidArgumentException (BudgetFieldsTrait.php:88-103). These fields are pipeline metadata and are deliberately excluded from toArray()/provider payload.

[19] (7) CapabilityPermissionService implements CapabilityPermissionServiceInterface (CapabilityPermissionService.php:30). isAllowed(ModelCapability $capability, ?BackendUserAuthentication $backendUser = null): bool (CapabilityPermissionServiceInterface.php:33 / impl :42). Rules: no BE user (CLI/FE) => true; admin => true; else $user->check('custom_options','nrllm:capability_<value>'). Static helpers permissionString()/permissionKey() are NOT in the interface (concrete-only). Registered in ext_localconf.php under TYPO3_CONF_VARS['BE']['customPermOptions']['nrllm'] (ext_localconf.php:80).

[20] (7) Domain/Enum/ModelCapability: string-backed enum (ModelCapability.php:17) with cases CHAT='chat', COMPLETION='completion', EMBEDDINGS='embeddings', VISION='vision', STREAMING='streaming', TOOLS='tools', JSON_MODE='json_mode', AUDIO='audio'. Helpers values():list<string>, isValid(string):bool, tryFromString(string):?self. (No dedicated IMAGE/SPEECH capability — image gen / TTS would map to AUDIO or have no capability gate.)

--- CODE SNIPPETS ---
### DI constructor: inject public interfaces + concrete Specialized services
public function __construct(
    private readonly CompletionServiceInterface $completion,
    private readonly VisionServiceInterface $vision,
    private readonly BudgetServiceInterface $budget,
    private readonly TextToSpeechService $tts,
    private readonly FalImageService $fal,
) {}
// autowire defaults are enough; the only manual entry you may add to YOUR Services.yaml:
//   Netresearch\NrLlm\Service\CapabilityPermissionServiceInterface:
//     alias: Netresearch\NrLlm\Service\CapabilityPermissionService
//     public: true

### Text completion returning structured JSON (podcast/diagram outline)
use Netresearch\NrLlm\Service\Option\ChatOptions;

$options = (new ChatOptions(
    temperature: 0.3,
    responseFormat: 'json',
    systemPrompt: 'You are an editor. Output ONLY valid JSON.',
    beUserUid: $beUserUid,      // enables BudgetMiddleware guard
    plannedCost: 0.02,
));
// returns array<string,mixed>; throws InvalidArgumentException on non-JSON
$data = $this->completion->completeJson($sourceText, $options);
// or full response object: $resp = $this->completion->complete($prompt, $options); $resp->content;

### Vision OCR call (page image -> text), full response with usage
use Netresearch\NrLlm\Service\Option\VisionOptions;

$opts = VisionOptions::comprehensive()  // detailLevel=high, maxTokens=1000
    ->withBeUserUid($beUserUid);
// $imageUrl may be an https URL OR 'data:image/png;base64,....'
$resp = $this->vision->analyzeImageFull(
    $imageUrl,
    'Transcribe all visible text in this image verbatim (OCR).',
    $opts,
);
$text = $resp->description; // === $resp->getText()

### TTS synth of one text turn (NOT auto budget-guarded)
use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;

if (!$this->budget->check($beUserUid, 0.015)->allowed) {
    throw new \RuntimeException('AI budget exhausted');
}
if (!$this->tts->isAvailable()) { /* OpenAI key missing in nr_llm config */ }
$result = $this->tts->synthesizeToFile(
    $turnText,                                   // <= 4096 chars (else synthesizeLong)
    $outputPath . '/turn-01.mp3',
    new SpeechSynthesisOptions(model: 'tts-1-hd', voice: 'nova', format: 'mp3'),
);
// $result->audioContent (binary), $result->getMimeType(), $result->characterCount

### FAL image generate + imageToImage from a local PNG (encode to data URI)
// text-to-image
$img = $this->fal->generate(
    'Vibrant abstract cover art, 9:16',
    'flux-schnell',
    ['image_size' => 'portrait_16_9', 'num_inference_steps' => 4],
);
$img->saveToFile($outDir . '/cover.png'); // downloads from $img->url (FAL returns URL, base64 null)

// image-to-image: imageToImage() takes a URL string, so wrap a local PNG as a data URI
$png  = (string) file_get_contents($localPngPath);
$dataUri = 'data:image/png;base64,' . base64_encode($png);
$out = $this->fal->imageToImage(
    $dataUri,                 // source image (URL or data: URI)
    'Turn this into an Instagram story background, soft gradient',
    'flux-dev',
    ['strength' => 0.6],
);

--- CITATIONS ---
• /home/sme/p/t3x-nr-llm/main/composer.json:2,34,39
• /home/sme/p/t3x-nr-llm/main/Configuration/Services.yaml:30-99,143-144,168-238
• /home/sme/p/t3x-nr-llm/main/Classes/Service/Feature/CompletionService.php:36-146,268
• /home/sme/p/t3x-nr-llm/main/Classes/Service/Feature/CompletionServiceInterface.php:24-56
• /home/sme/p/t3x-nr-llm/main/Classes/Service/LlmServiceManager.php:42,159,179,199,269,310,339,357
• /home/sme/p/t3x-nr-llm/main/Classes/Service/LlmServiceManagerInterface.php:31-135
• /home/sme/p/t3x-nr-llm/main/Classes/Service/Option/ChatOptions.php:20-43,92,160-173,268-282,326
• /home/sme/p/t3x-nr-llm/main/Classes/Service/Option/ToolOptions.php:19-166
• /home/sme/p/t3x-nr-llm/main/Classes/Service/Option/VisionOptions.php:17-86
• /home/sme/p/t3x-nr-llm/main/Classes/Service/Option/BudgetFieldsTrait.php:37-103
• /home/sme/p/t3x-nr-llm/main/Classes/Service/Feature/VisionService.php:29-247
• /home/sme/p/t3x-nr-llm/main/Classes/Service/Feature/VisionServiceInterface.php:28-80
• /home/sme/p/t3x-nr-llm/main/Classes/Domain/Model/VisionResponse.php:15-54
• /home/sme/p/t3x-nr-llm/main/Classes/Domain/Model/CompletionResponse.php:17-83
• /home/sme/p/t3x-nr-llm/main/Classes/Domain/ValueObject/ToolSpec.php:35-165
• /home/sme/p/t3x-nr-llm/main/Classes/Domain/ValueObject/VisionContent.php:32-208
• /home/sme/p/t3x-nr-llm/main/Classes/Specialized/AbstractSpecializedService.php:47-152
• /home/sme/p/t3x-nr-llm/main/Classes/Specialized/Speech/TextToSpeechService.php:37-182
• /home/sme/p/t3x-nr-llm/main/Classes/Specialized/Speech/SpeechSynthesisResult.php:15-134
• /home/sme/p/t3x-nr-llm/main/Classes/Specialized/Option/SpeechSynthesisOptions.php:17-96
• /home/sme/p/t3x-nr-llm/main/Classes/Specialized/Image/FalImageService.php:31-200,354-409
• /home/sme/p/t3x-nr-llm/main/Classes/Specialized/Image/DallEImageService.php:32-290
• /home/sme/p/t3x-nr-llm/main/Classes/Specialized/Image/ImageGenerationResult.php:15-209
• /home/sme/p/t3x-nr-llm/main/Classes/Specialized/Option/ImageGenerationOptions.php:18-101
• /home/sme/p/t3x-nr-llm/main/Classes/Service/BudgetService.php:38-108
• /home/sme/p/t3x-nr-llm/main/Classes/Service/BudgetServiceInterface.php:21-34
• /home/sme/p/t3x-nr-llm/main/Classes/Domain/DTO/BudgetCheckResult.php:19-84
• /home/sme/p/t3x-nr-llm/main/Classes/Exception/BudgetExceededException.php:23-31
• /home/sme/p/t3x-nr-llm/main/Classes/Provider/Middleware/BudgetMiddleware.php:55-78
• /home/sme/p/t3x-nr-llm/main/Classes/Service/CapabilityPermissionService.php:30-89
• /home/sme/p/t3x-nr-llm/main/Classes/Service/CapabilityPermissionServiceInterface.php:23-45
• /home/sme/p/t3x-nr-llm/main/Classes/Domain/Enum/ModelCapability.php:17-56
• /home/sme/p/t3x-nr-llm/main/ext_localconf.php:80


=================================================================
AREA: TYPO3 v14.3 LTS Symfony Messenger integration (message bus, handlers, transports
=================================================================

--- VERIFIED FACTS ---
[0] #[AsMessageHandler] attribute FQCN is Symfony\Component\Messenger\Attribute\AsMessageHandler and IS autoconfigured in v14.3 Core. Verified: typo3/sysext/core/Configuration/Services.php@14.3 line 47-50 calls $containerBuilder->registerForAutoconfiguration(AsMessageHandler::class) and adds tag 'messenger.message_handler'; line 148 adds DependencyInjection\MessageHandlerPass('messenger.message_handler').

[1] On v14.3 (and v13) NO Services.yaml entry is required for handlers — the attribute alone registers them. A 'messenger.message_handler' tag entry is only needed for v12 compatibility OR to define before/after ordering. Source: CoreApi MessageBus/Index.rst (14.3 branch) lines 76-82 and Changelog 13.0/Feature-101700.

[2] Handler can carry the attribute on the class (__invoke) or on a specific method (multiple handlers per class). Source: Feature-101700 changelog text.

[3] Transport routing is configured in PHP at $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] (array). Default is ['*' => 'default'] (synchronous SyncTransport). Verified: typo3/sysext/core/Configuration/DefaultConfiguration.php@14.3 messenger=>['routing'=>['*'=>'default']]; documented in Configuration/Typo3ConfVars/SYS.rst confval messenger.routing.

[4] To enable async DB processing for all messages: $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing']['*'] = 'doctrine'; (or per-message-class key). Source: CoreApi MessageBus/Index.rst line 103 + SYS.rst.

[5] A Doctrine DBAL transport is PRE-REGISTERED by Core under identifier 'doctrine' using the default TYPO3 DB connection — no extra service definition needed to use it. Verified: typo3/sysext/core/Configuration/Services.yaml@14.3 lines 148-156, service Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport via factory TYPO3\CMS\Core\Messenger\DoctrineTransportFactory::createTransport with queue_name 'default', tagged messenger.sender + messenger.receiver identifier 'doctrine'.

[6] Custom transports are defined as services with factory @TYPO3\CMS\Core\Messenger\DoctrineTransportFactory::createTransport, tagged messenger.sender and messenger.receiver with an 'identifier', then referenced by that identifier in the routing array. Source: CoreApi MessageBus/_custom-transport.yaml + Index.rst lines 191-218.

[7] Three transports are Core-tested: SyncTransport (default), DoctrineTransport (DBAL DB queue), InMemoryTransport (testing only). Source: CoreApi MessageBus/Index.rst lines 220-227.

[8] Dispatch: inject Symfony\Component\Messenger\MessageBusInterface (Core aliases it to messenger.bus.default, factory TYPO3\CMS\Core\Messenger\BusFactory::createBus) and call $bus->dispatch($message). There is NO TYPO3-specific bus wrapper interface. Verified: Core Services.yaml@14.3 lines 125-130; CoreApi MessageBus/_MyClass.php.

[9] messenger:consume command FQCN TYPO3\CMS\Core\Command\ConsumeMessagesCommand, #[AsCommand(name: 'messenger:consume', description: 'Consume messages')]. Options (verified from configure() in v14.3 source): --limit/-l, --failure-limit/-f, --memory-limit/-m, --time-limit/-t, --sleep (default 1), --bus/-b, --queues (array), --all, --keepalive (optional, default self::DEFAULT_KEEPALIVE_INTERVAL). Argument 'receivers' (IS_ARRAY). Source: ConsumeMessagesCommand.php@14.3 lines 86-103.

[10] Stop conditions registered when options set: StopWorkerOnMessageLimitListener (--limit), StopWorkerOnFailureLimitListener (--failure-limit), StopWorkerOnMemoryLimitListener (--memory-limit), StopWorkerOnTimeLimitListener (--time-limit), plus messenger:stop-workers signal and pcntl signals (SIGTERM/SIGINT/SIGQUIT/SIGALRM). Source: ConsumeMessagesCommand.php@14.3 lines 217-296.

[11] The command ALWAYS returns Command::SUCCESS (exit 0) and defines NO --exit-code-on-limit option. The systemd example in the docs (using '--exit-code-on-limit 133' + RestartForceExitStatus=133) does NOT match the v14.3 slimmed-down wrapper. Verified: ConsumeMessagesCommand.php@14.3 (no such InputOption; execute() returns Command::SUCCESS at line ~276).

[12] Retry/backoff and failure transport are NOT provided by Core in v14.3. Core wires only SendMessageMiddleware + HandleMessageMiddleware (Services.yaml lines 115-123); BusFactory::createBus just does new MessageBus($middlewares) with no retry/failure listeners. DefaultConfiguration messenger array has NO failure_transport / retry_strategy / transports keys. Symfony's SendFailedMessageForRetryListener / SendFailedMessageToFailureTransportListener are NOT registered. Verified: Core Services.yaml@14.3, BusFactory.php@14.3 lines 35-40, DefaultConfiguration.php@14.3.

[13] Rate limiting IS supported (since v13.4): tag a RateLimiterFactory service with messenger.rate_limiter and identifier matching the transport (e.g. 'doctrine'). Source: CoreApi MessageBus/_add-rate-limiter.yaml + Index.rst lines 229-257.

[14] The worker self-terminates after ~1 hour per Core guidance to avoid memory leaks; run under a process supervisor (systemd) to restart. Source: CoreApi MessageBus/Index.rst lines 152-157.

--- CODE SNIPPETS ---
### GenerateArtifactsMessage.php (immutable message DTO)
<?php
declare(strict_types=1);
namespace Netresearch\NrRepurpose\Queue\Message;

final class GenerateArtifactsMessage
{
    public function __construct(
        public readonly string $sourceUrl,
        public readonly int $jobUid,
        public readonly bool $wantPodcast = true,
        public readonly bool $wantDiagram = true,
        public readonly bool $wantStory = true,
    ) {}
}

### GenerateArtifactsHandler.php (#[AsMessageHandler] autoconfigured on v14.3 — no Services.yaml needed)
<?php
declare(strict_types=1);
namespace Netresearch\NrRepurpose\Queue\Handler;

use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateArtifactsHandler
{
    public function __invoke(GenerateArtifactsMessage $message): void
    {
        // call netresearch/nr-llm to produce podcast + diagram + story
        // throw on hard failure; for transient errors see retry note (no Core retry in v14.3)
    }
}

### Route the message to the pre-registered Doctrine DB transport (config/system/additional.php)
<?php
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;

// 'doctrine' transport is pre-registered by Core; no service definition needed.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'][GenerateArtifactsMessage::class] = 'doctrine';
// keep the synchronous default for all other (e.g. Core) messages:
$GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing']['*'] = 'default';

### Dispatch from an Extbase/PSR-15 controller (inject the Symfony bus interface)
<?php
declare(strict_types=1);
namespace Netresearch\NrRepurpose\Controller;

use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Symfony\Component\Messenger\MessageBusInterface;

final class RepurposeController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {}

    public function enqueueAction(string $url, int $jobUid): void
    {
        $this->bus->dispatch(new GenerateArtifactsMessage($url, $jobUid));
    }
}

### Run + supervise the worker (v14.3-correct — NO --exit-code-on-limit)
# CLI (Composer install):
vendor/bin/typo3 messenger:consume doctrine --time-limit=3600 --memory-limit=256M

# systemd unit (command exits 0 on limit, so just always-restart):
# ExecStart=/usr/bin/php /var/www/app/vendor/bin/typo3 messenger:consume doctrine --time-limit=3600 --memory-limit=256M
# Restart=always
# RestartSec=1

### Optional: throttle the doctrine transport via a rate limiter (since v13.4) — Configuration/Services.yaml
services:
  netresearch.nrrepurpose.rate_limiter.llm:
    class: Symfony\Component\RateLimiter\RateLimiterFactory
    arguments:
      $config:
        id: 'nrrepurpose_llm'
        policy: 'sliding_window'
        limit: 100
        interval: '60 seconds'
      $storage: '@TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage'
    tags:
      - name: 'messenger.rate_limiter'
        identifier: 'doctrine'

--- CITATIONS ---
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/ApiOverview/MessageBus/Index.rst (upstream/14.3 and main) lines 44-157, 191-257
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/ApiOverview/MessageBus/_DemoMessage.php, _MyClass.php, _DemoHandler.php, _demo-handler.yaml, _custom-transport.yaml, _add-rate-limiter.yaml, _in-memory-transport.yaml, _custom-middleware.yaml
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/Configuration/Typo3ConfVars/SYS.rst lines 884-915 (confval messenger / routing)
• https://raw.githubusercontent.com/TYPO3/typo3/14.3/typo3/sysext/core/Configuration/Services.php lines 14, 47-50, 148 (registerForAutoconfiguration AsMessageHandler -> messenger.message_handler + MessageHandlerPass)
• https://raw.githubusercontent.com/TYPO3/typo3/14.3/typo3/sysext/core/Configuration/Services.yaml lines 113-156 (messenger middleware, bus factory, MessageBusInterface alias, Doctrine/Sync transports)
• https://raw.githubusercontent.com/TYPO3/typo3/14.3/typo3/sysext/core/Configuration/DefaultConfiguration.php (SYS=>messenger=>routing=>['*'=>'default']; no failure_transport/retry_strategy)
• https://raw.githubusercontent.com/TYPO3/typo3/14.3/typo3/sysext/core/Classes/Command/ConsumeMessagesCommand.php lines 18,53,86-103,217-296 (AsCommand, options, stop listeners, returns Command::SUCCESS, no --exit-code-on-limit)
• https://raw.githubusercontent.com/TYPO3/typo3/14.3/typo3/sysext/core/Classes/Messenger/BusFactory.php lines 35-40 (createBus = new MessageBus(middlewares), no retry/failure)
• https://raw.githubusercontent.com/TYPO3/typo3/14.3/typo3/sysext/core/Documentation/Changelog/13.0/Feature-101700-UseSymfonyAttributeToAutoconfigureMessageHandler.rst (attribute support since v13.0, issue #101700)
• gh api search/code q='AsMessageHandler repo:TYPO3/typo3' (located autoconfiguration in core/Configuration/Services.php and workspaces/webhooks handler usages)


=================================================================
AREA: TYPO3 v14.3 backend module + controller patterns (Modules.php, ModuleTemplateFac
=================================================================

--- VERIFIED FACTS ---
[0] Modules.php location: Configuration/Backend/Modules.php; read & processed at container build time, state is fixed at runtime (ModuleConfiguration/Index.rst:11-20).

[1] Modules.php common keys (verified in confval list, ModuleConfiguration/Index.rst): parent (string, parent identifier e.g. 'content'/'web'/'system'), path (string, default /module/<main>/<sub>), access ('user'|'admin'|'systemMaintainer'; Index.rst:60-64), workspaces ('*'|'live'|'offline'), position (array; 'top'|'bottom' or ['before'=>id]/['after'=>id]), appearance (array; appearance.renderInModuleMenu bool, appearance.dependsOnSubmodules bool versionadded 14.0 Index.rst:92), iconIdentifier (string), icon (DEPRECATED, use iconIdentifier), labels (array title/description/shortDescription OR string path to xlf with keys mlang_tabs_tab/mlang_labels_tabdescr/mlang_labels_tablabel), component (default TYPO3/CMS/Backend/Module/Iframe), navigationComponent, moduleData (array of allowed GET/POST-overridable defaults), aliases (array), routeOptions (array).

[2] PLAIN (non-Extbase) module keys: `routes` array. '_default' route is mandatory (except modules falling back to a submodule); each route needs at least 'target' => Controller::class.'::method'; routes may set 'path' and 'methods' => ['POST'] to restrict HTTP verbs (ModuleConfiguration/Index.rst:255-279, _Routes.php). Route identifier syntax: <module_identifier>.<sub_route> e.g. my_module.edit; build with UriBuilder->buildUriFromRoute('my_module.edit').

[3] EXTBASE module keys: `extensionName` (UpperCamelCase, e.g. extkey my_repurpose -> 'NrRepurpose') and `controllerActions` (array: [Controller::class => ['list','detail']]). The docs explicitly state these tell Core to bootstrap Extbase expecting controllers extending TYPO3\CMS\Extbase\Mvc\Controller\ActionController; do NOT mix with `routes` (ModuleConfiguration/Index.rst:282-337). Extbase routes auto-registered as <module_identifier>.<controller>_<action>, human-readable URLs unless feature toggle enableNamespacedArgumentsForBackend is on (default off).

[4] Backend controllers should carry the #[TYPO3\CMS\Backend\Attribute\AsController] attribute (CreateModule.rst:27-30; AsController.php verified: namespace TYPO3\CMS\Backend\Attribute, TARGET_CLASS, TAG_NAME='backend.controller'). v14.0 removed the old Backend\Attribute\Controller class alias (CreateModuleWithExtbase.rst:51-53 versionchanged 14.0). Alternative to the attribute: tag the service with 'backend.controller' in Services.yaml (CreateModule.rst:33-52).

[5] ModuleTemplateFactory::create(ServerRequestInterface $request): ModuleTemplate (ModuleTemplateFactory.php:48). ModuleTemplate methods verified (ModuleTemplate.php): assign(string,$mixed):self (99), assignMultiple(array):self (108), renderResponse(string $templateFileName=''):ResponseInterface (127), setTitle(string,string $context=''):self (188), setModuleClass(string):self (240), getDocHeaderComponent(), setFlashMessageQueue(). renderResponse('AdminModule/Debug') resolves template by Controller/Action path convention.

[6] ButtonBar make*Button() methods are DEPRECATED since v14 (ButtonBar.php: makeButton 181, makeGenericButton 233, makeInputButton 242, makeSplitButton 251, makeDropDownButton 260, makeLinkButton 269, makeFullyRenderedButton 278 — all trigger_error E_USER_DEPRECATED, removed in v15). v14 replacement: inject TYPO3\CMS\Backend\Template\Components\ComponentFactory and call createLinkButton():LinkButton (206), createShortcutButton():ShortcutButton (216), createDropDownButton, createInputButton, createSplitButton, createMenu, createMenuItem (ComponentFactory.php). getButtonBar() and addButton(button, ButtonBar::BUTTON_POSITION_LEFT|RIGHT, $group) remain.

[7] ShortcutButton (Components/Buttons/Action/ShortcutButton.php) fluent setters: setRouteIdentifier(string):static (88), setDisplayName(string):static (99), setArguments(array):static (105). LinkButton setters: setHref, setTitle, setShowLabelText(bool), setIcon(IconFactory->getIcon('id', IconSize::SMALL)).

[8] PLAIN module POST handling pattern (verified _AdminModuleControllerHandleRequest/_DebugAction): single entry method handleRequest(ServerRequestInterface $request): ResponseInterface; read $request->getParsedBody() for POST; ModuleData via $request->getAttribute('moduleData'); persist with $moduleData->cleanUp($allowedOptions) then $GLOBALS BE_USER->pushModuleData($moduleData->getModuleIdentifier(), $moduleData->toArray()); finally $view->renderResponse('AdminModule/Debug').

[9] ModuleData (Backend\Module\ModuleData.php) public API: getModuleIdentifier():string (51), get(name,$default=null):mixed (56), has(name):bool (61), set(name,value):void (66), clean(name,array $allowed):bool (78), cleanUp(array $allowedData, bool $useKeys=true):bool (108), toArray():array (122).

[10] EXTBASE CRUD+redirect pattern (verified in core BackendUserController.php, the cleanest CRUD-ish core example): extends ActionController; constructor injects readonly ModuleTemplateFactory (73) and ComponentFactory (25); initializeAction():void sets $this->moduleData = $this->request->getAttribute('moduleData') and $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request) + setTitle + setFlashMessageQueue($this->getFlashMessageQueue()) (105-111) — done in initializeAction not __construct because the controller is reused across actions in one Extbase call; actions return $this->moduleTemplate->renderResponse('BackendUser/List') (217) for display, or return $this->redirect('list') after create/update/delete (298,356,370). ForwardResponse also available for forwarding.

[11] FAL file selection / uploaded PDF picking: TCA column type=file (versionchanged 13.0: Core auto-generates the DB field, no ext_tables.sql entry needed — Fal/UsingFal/Tca.rst:12-16). Minimal config: ['type'=>'file','allowed'=>'common-media-types'] or a specific extension list / 'common-image-types'; add 'maxitems'=>N. appearance keys fileUploadAllowed/fileByUrlAllowed control upload/URL buttons. Replaces deprecated ExtensionManagementUtility::getFileFieldTCAConfig(). This is a TCA/DataHandler-record approach; a custom plain BE form cannot use the FAL element renderer — for that you persist a record (Extbase domain model + TCA) and let the standard FormEngine render the file element, or accept a raw upload via $request->getUploadedFiles().

[12] SVG icon registration: Configuration/Icons.php returns array keyed by icon identifier => ['provider' => TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class, 'source' => 'EXT:my_extension/Resources/Public/Icons/icon.svg'] (verified ApiOverview/Icon/_Icons.php + Configuration/Icons.rst). Identifier is then used as the module's iconIdentifier. SvgIconProvider class confirmed present at cms-core/Classes/Imaging/IconProvider/SvgIconProvider.php. BitmapIconProvider for PNG; optional 'spinning'=>true and 'deprecated' metadata (since v12).

[13] Services.yaml requirement: with #[AsController] + autoconfigure:true no explicit tag needed; without the attribute, register the controller with tags: ['backend.controller'] (CreateModule.rst:33-52). Standard _defaults: autowire/autoconfigure true, public false; resource '../Classes/*'. No ext_localconf.php entry is required for module registration itself — Modules.php and Icons.php are auto-loaded by convention.

[14] Fluid templates in BACKEND use layout `<f:layout name="Module" />` (Core-provided) and `<f:section name="Content">`; namespaces via data-namespace-typo3-fluid + xmlns f/core/be. WARNING from docs: some Fluid tags do NOT work in non-Extbase context, notably <f:form> (CreateModule.rst:90-91) — so a POST form in a plain module must be built with raw HTML <form> + UriBuilder action URL, whereas Extbase modules can use <f:form>.

--- CODE SNIPPETS ---
### Configuration/Backend/Modules.php — minimal Extbase CRUD module (idiomatic v14)
<?php
declare(strict_types=1);

use Netresearch\NrRepurpose\Controller\JobController;

return [
    'web_nrrepurpose' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user',
        'iconIdentifier' => 'tx-nrrepurpose-module',
        'path' => '/module/web/nr-repurpose',
        'labels' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_mod.xlf',
        // Extbase mode: bootstraps Extbase, expects ActionController subclasses
        'extensionName' => 'NrRepurpose',
        'controllerActions' => [
            JobController::class => [
                'list', 'new', 'create', 'show', 'delete',
            ],
        ],
    ],
];
// Registers routes web_nrrepurpose, web_nrrepurpose.Job_list, .Job_create, etc.

### Configuration/Backend/Modules.php — PLAIN (non-Extbase) alternative
<?php
declare(strict_types=1);

use Netresearch\NrRepurpose\Controller\JobController;

return [
    'web_nrrepurpose' => [
        'parent' => 'web',
        'access' => 'user',
        'iconIdentifier' => 'tx-nrrepurpose-module',
        'path' => '/module/web/nr-repurpose',
        'labels' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => ['target' => JobController::class . '::handleRequest'],
            'create'   => ['target' => JobController::class . '::create', 'methods' => ['POST']],
        ],
    ],
];

### Extbase BE controller skeleton (v14: ComponentFactory, AsController, redirect)
<?php
declare(strict_types=1);

namespace Netresearch\NrRepurpose\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

#[AsController]
class JobController extends ActionController
{
    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly ComponentFactory $componentFactory,
        protected readonly IconFactory $iconFactory,
        protected readonly JobRepository $jobRepository,
    ) {}

    // Build ModuleTemplate here, NOT in __construct (controller reused across actions)
    public function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        // v14: NOT $buttonBar->makeShortcutButton() (deprecated). Use ComponentFactory:
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $shortcut = $this->componentFactory->createShortcutButton()
            ->setRouteIdentifier('web_nrrepurpose')
            ->setDisplayName('Repurpose jobs');
        $buttonBar->addButton($shortcut, ButtonBar::BUTTON_POSITION_RIGHT);
    }

    public function listAction(): ResponseInterface
    {
        $this->moduleTemplate->assign('jobs', $this->jobRepository->findAll());
        return $this->moduleTemplate->renderResponse('Job/List');
    }

    public function newAction(): ResponseInterface
    {
        return $this->moduleTemplate->renderResponse('Job/New');
    }

    public function createAction(Job $newJob): ResponseInterface
    {
        $this->jobRepository->add($newJob);
        $this->addFlashMessage('Job created.');
        return $this->redirect('list'); // 303 redirect, no double-submit
    }
}

### Configuration/Icons.php — register the SVG module icon
<?php
declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'tx-nrrepurpose-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_repurpose/Resources/Public/Icons/module.svg',
    ],
];

### Configuration/Services.yaml — DI for controllers
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Netresearch\NrRepurpose\:
    resource: '../Classes/*'
    exclude: '../Classes/Domain/Model/*'

  # Only needed if you do NOT use the #[AsController] attribute:
  # Netresearch\NrRepurpose\Controller\JobController:
  #   tags: ['backend.controller']

### TCA type=file for PDF selection/upload (no ext_tables.sql field needed since v13)
// Configuration/TCA/tx_nrrepurpose_domain_model_job.php (columns excerpt)
'source_pdf' => [
    'label' => 'Source PDF',
    'config' => [
        'type' => 'file',
        'allowed' => 'pdf',      // or a MIME/extension list
        'maxitems' => 1,
        'appearance' => [
            'fileByUrlAllowed' => false, // hide the external-URL button
        ],
    ],
],

### Fluid template — Extbase BE module with <f:form> (works only in Extbase, not plain)
<html data-namespace-typo3-fluid="true"
      xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers">
    <f:layout name="Module" />
    <f:section name="Content">
        <f:form action="create" name="newJob" object="{newJob}">
            <f:form.textfield property="sourceUrl" />
            <f:form.submit value="Create" />
        </f:form>
    </f:section>
</html>

--- CITATIONS ---
• /home/sme/p/TYPO3CMS-Reference-CoreApi/.Build/vendor/typo3/cms-core/Classes/Information/Typo3Version.php:22-23 (VERSION 14.1.0-dev, BRANCH 14.1 — version caveat)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/ApiOverview/Backend/BackendModules/ModuleConfiguration/Index.rst:11-361 (all Modules.php confval keys, routes vs Extbase split, sudo mode)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/ApiOverview/Backend/BackendModules/ModuleConfiguration/_ModuleConfiguration/_Routes.php (plain routes example)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/ApiOverview/Backend/BackendModules/ModuleConfiguration/_ModuleConfiguration/_ControllerActions.php (Extbase controllerActions example)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/ExtensionArchitecture/HowTo/BackendModule/CreateModule.rst:1-91 (plain controller: AsController, handleRequest, f:form limitation)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/ExtensionArchitecture/HowTo/BackendModule/CreateModuleWithExtbase.rst:32-115 (Extbase pattern, v14.0 Controller alias removal, renderResponse) — note makeShortcutButton snippet is stale
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/ExtensionArchitecture/HowTo/BackendModule/_ModuleConfiguration/_Modules.rst.txt (full real Modules.php from EXT:examples)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/ExtensionArchitecture/HowTo/BackendModule/_ModuleConfiguration/_AdminModuleControllerHandleRequest.rst.txt + _AdminModuleControllerDebugAction.rst.txt + _AdminModuleControllerSetUpDocHeader.rst.txt + _AdminModuleControllerConstruct.rst.txt (plain controller full code incl ComponentFactory)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/.Build/vendor/typo3/cms-backend/Classes/Attribute/AsController.php (TAG_NAME backend.controller)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/.Build/vendor/typo3/cms-backend/Classes/Template/ModuleTemplate.php:99,108,127,188,240 (assign/assignMultiple/renderResponse/setTitle/setModuleClass)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/.Build/vendor/typo3/cms-backend/Classes/Template/ModuleTemplateFactory.php:48 (create signature)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/.Build/vendor/typo3/cms-backend/Classes/Template/Components/ButtonBar.php:181-282 (make*Button deprecations)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/.Build/vendor/typo3/cms-backend/Classes/Template/Components/ComponentFactory.php:206,216 (createLinkButton/createShortcutButton)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/.Build/vendor/typo3/cms-backend/Classes/Template/Components/Buttons/Action/ShortcutButton.php:88,99,105 (setRouteIdentifier/setDisplayName/setArguments)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/.Build/vendor/typo3/cms-backend/Classes/Module/ModuleData.php:51,56,61,66,78,108,122 (ModuleData API)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/.Build/vendor/typo3/cms-beuser/Classes/Controller/BackendUserController.php:60,73,105-111,217,298,356,370 (real Extbase BE CRUD: initializeAction, renderResponse, redirect)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/ApiOverview/Icon/_Icons.php + Documentation/ExtensionArchitecture/FileStructure/Configuration/Icons.rst (Icons.php / SvgIconProvider registration)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/.Build/vendor/typo3/cms-core/Classes/Imaging/IconProvider/SvgIconProvider.php (class exists)
• /home/sme/p/TYPO3CMS-Reference-CoreApi/Documentation/ApiOverview/Fal/UsingFal/Tca.rst:12-67 + _Tca/_my_table.php + _Tca/_overrides_my_table.php (TCA type=file, v13 auto DB field, appearance)


=================================================================
AREA: Rendering LLM-generated HTML/CSS to PNG from PHP, deterministically, inside the 
=================================================================

--- VERIFIED FACTS ---
[0] DDEV web container (where PHP executes) is Debian 13 trixie: `/etc/os-release` of ddev/ddev-webserver:v1.25.2 -> VERSION="13 (trixie)", VERSION_CODENAME=trixie (verified via `docker run`).

[1] DDEV web container already has Node v24.15.0 and npm 11.12.1 preinstalled; `chromium` is NOT installed but is available as apt candidate 149.0.7827.53-1~deb13u1 in trixie (verified via `apt-cache policy chromium` inside the image).

[2] nr_llm does NOT install chromium in the web/PHP container. E2E runs Playwright inside a SEPARATE CI image: Build/Scripts/runTests.sh:290 `IMAGE_PLAYWRIGHT="mcr.microsoft.com/playwright:v1.57.0-noble"`, invoked at runTests.sh:422. The web container has no node_modules and no playwright browsers.

[3] nr_llm package.json uses @playwright/test ^1.57.0 (not raw playwright), @types/node ^24, @axe-core/playwright ^4.10; engines node >=22.18.0 <25.0.0, npm >=11.5.2 (package.json:6-23). @playwright/test does NOT bundle browser binaries — they require `npx playwright install`.

[4] spatie/browsershot latest is 5.4.0 (released 2026-05-26 per Packagist), requires PHP ^8.2, depends on symfony/process ^6.0|^7.0|^8.0, spatie/temporary-directory ^2.0, ext-json, ext-fileinfo.

[5] Browsershot's Node engine is Puppeteer (NOT Playwright). README states conversion is done by Puppeteer/headless Chrome; bin/browser.cjs require('puppeteer'). Requirements page: Node 22 LTS+ and Puppeteer >= v23. Puppeteer is NOT bundled — it must be installed separately via npm.

[6] Browsershot 5 PHP API (verified from src/Browsershot.php): `public function windowSize(int $width, int $height): static`; `public function deviceScaleFactor(int $deviceScaleFactor): static`; `public function fullPage(): static`; `public function transparentBackground(): static`; `public function save(string $targetPath): void`; `public function setScreenshotType(string $type, ?int $quality = null): static`; `public function setNodeBinary(string $nodeBinary): static`; `public function setNpmBinary(string $npmBinary): static`; `public function setChromePath(string $executablePath): static`; `public function setNodeModulePath(string $nodeModulePath): static`; `public function setIncludePath(string $includePath): static`; `public function setBinPath(string $binPath): static`. Entry points `Browsershot::html(string $html)` and `Browsershot::url()`.

[7] Playwright Node screenshot API (verified from playwright.dev): page.setContent(html, {waitUntil}); page.setViewportSize({width,height}); page.screenshot({path, fullPage:boolean, omitBackground:boolean, type:'png'|'jpeg', clip}); browser.newContext({viewport:{width,height}, deviceScaleFactor:number}). deviceScaleFactor is a CONTEXT option, not a page option.

[8] Transparent screenshots require omitBackground:true which is PNG-only AND only takes effect when the page itself has a transparent/non-opaque background (set html,body{background:transparent} in the LLM CSS). This is identical behavior for Browsershot::transparentBackground() (Puppeteer omitBackground) and Playwright omitBackground.

[9] Debian trixie chromium runtime needs no extra shared libs when installed via the `chromium` apt package (it pulls chromium-common + recommends chromium-sandbox). The long libnss3/libgbm1/etc list is only needed when downloading a browser binary that is NOT packaged (e.g. puppeteer's own download or `playwright install` without --with-deps).

--- CODE SNIPPETS ---
### Install commands (run inside DDEV web container / baked into image)
# Option B (recommended) — Playwright. Node v24 already present in the trixie web image.
# Approach B1: let Playwright manage its own pinned chromium (most deterministic):
npm i -D playwright@^1.57.0
npx playwright install --with-deps chromium
#   -> browsers cached under ~/.cache/ms-playwright (or PLAYWRIGHT_BROWSERS_PATH)

# Approach B2: use Debian's packaged chromium (smaller, OS-patched) + playwright-core:
sudo apt-get update && sudo apt-get install -y --no-install-recommends chromium
npm i -D playwright-core@^1.57.0
#   -> then pass executablePath:'/usr/bin/chromium' to chromium.launch()

# Option A (rejected) — Browsershot needs a PHP dep + a separate Puppeteer + chrome:
composer require spatie/browsershot:^5.4
npm i puppeteer            # pulls its own chromium download (needs the long libnss3/libgbm1 lib set)

### .ddev/web-build/Dockerfile additions (Debian trixie) — Playwright B2 (apt chromium)
# Append to existing .ddev/web-build/Dockerfile (node/npm already in the ddev-webserver image).
# B2: OS-packaged chromium (pulls chromium-common, recommends chromium-sandbox). No extra libs needed.
RUN apt-get update \
 && apt-get install -y --no-install-recommends chromium fonts-liberation \
 && rm -rf /var/lib/apt/lists/*

# Install the JS renderer deps into the extension and point Playwright at the apt chromium.
# (Run as the web user; PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD avoids a duplicate download for playwright-core.)
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
    CHROMIUM_PATH=/usr/bin/chromium
# npm ci runs at build or first boot from Resources/Private/NodeRenderer/package.json (playwright-core ^1.57)

# --- Alternative B1 (Playwright-managed chromium): replace the apt block above with ---
# RUN npx --yes playwright@1.57.0 install --with-deps chromium
#   ('--with-deps' apt-installs the exact shared libs Playwright's bundled chromium needs on trixie)

### PHP interface + Symfony Process renderer (Option B)
<?php
declare(strict_types=1);
namespace Netresearch\NrRepurpose\Rendering;

interface HtmlToImageRendererInterface
{
    /** Render HTML to a PNG file and return its absolute path. */
    public function render(
        string $html,
        int $width,
        ?int $height,            // null => auto height (full page); set => exact clip
        float $deviceScaleFactor = 1.0,
        bool $transparent = false,
    ): string;
}

final class PlaywrightHtmlToImageRenderer implements HtmlToImageRendererInterface
{
    public function __construct(
        private readonly string $nodeBinary = 'node',
        private readonly string $scriptPath = '',   // abs path to render.cjs (EXT:nr_repurpose/Resources/Private/NodeRenderer/render.cjs)
        private readonly string $outputDir = '',    // writable temp dir
    ) {}

    public function render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string
    {
        $out = rtrim($this->outputDir, '/').'/'.bin2hex(random_bytes(8)).'.png';
        $process = new \Symfony\Component\Process\Process([
            $this->nodeBinary, $this->scriptPath,
            '--width', (string) $width,
            '--height', $height === null ? 'auto' : (string) $height,
            '--scale', (string) $deviceScaleFactor,
            '--out', $out,
            $transparent ? '--transparent' : '--opaque',
        ]);
        // HTML on stdin: avoids argv length limits and shell quoting issues.
        $process->setInput($html);
        $process->setTimeout(60.0);
        $process->mustRun();          // throws ProcessFailedException on non-zero exit
        return $out;
    }
}
// Diagram:  $r->render($html, 1200, null, 2.0, true);   // 1200 wide, auto height, @2x, transparent
// Story:    $r->render($html, 1080, 1920, 1.0, false);  // exact 1080x1920, opaque

### Resources/Private/NodeRenderer/render.cjs (Playwright)
// CommonJS so it runs without ESM config. Reads HTML from stdin, writes a PNG.
const { chromium } = require('playwright-core'); // or 'playwright' for B1

function arg(name, def) { const i = process.argv.indexOf('--' + name); return i > -1 ? process.argv[i + 1] : def; }

(async () => {
  const width  = parseInt(arg('width', '1200'), 10);
  const heightA = arg('height', 'auto');
  const scale  = parseFloat(arg('scale', '1'));
  const out    = arg('out');
  const transparent = process.argv.includes('--transparent');

  const html = await new Promise((res) => { let d = ''; process.stdin.on('data', c => d += c); process.stdin.on('end', () => res(d)); });

  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--force-color-profile=srgb'],
    executablePath: process.env.CHROMIUM_PATH || undefined, // set for apt chromium (B2)
  });
  const context = await browser.newContext({
    viewport: { width, height: heightA === 'auto' ? 10 : parseInt(heightA, 10) },
    deviceScaleFactor: scale,           // CONTEXT-level option
  });
  const page = await context.newPage();
  await page.setContent(html, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts && document.fonts.ready); // wait for webfonts

  await page.screenshot({
    path: out,
    type: 'png',
    fullPage: heightA === 'auto',       // auto-height diagram -> fullPage; fixed story -> clipped to viewport
    omitBackground: transparent,        // transparent PNG; LLM CSS must set html,body{background:transparent}
  });
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });

### Browsershot equivalent (Option A — for comparison only)
<?php
use Spatie\Browsershot\Browsershot;

// Diagram: 1200 wide, auto height, @2x, transparent
Browsershot::html($html)
    ->setNodeBinary('/usr/bin/node')
    ->setNpmBinary('/usr/bin/npm')
    ->setChromePath('/usr/bin/chromium')      // reuse apt chromium instead of puppeteer's download
    ->setNodeModulePath('/var/www/html/node_modules') // where `npm i puppeteer` placed it
    ->windowSize(1200, 800)
    ->deviceScaleFactor(2)
    ->fullPage()
    ->transparentBackground()
    ->setScreenshotType('png')
    ->save($pngPath);

// Story: exact 1080x1920, opaque
Browsershot::html($html)
    ->setChromePath('/usr/bin/chromium')
    ->windowSize(1080, 1920)
    ->setScreenshotType('png')
    ->save($pngPath);   // no fullPage() -> clipped to the 1080x1920 window
// Note: deviceScaleFactor() takes int in v5; transparentBackground() needs transparent CSS, PNG only.

--- CITATIONS ---
• /home/sme/p/t3x-nr-llm/main/package.json:6-23 (engines node 22-25, @playwright/test ^1.57.0, @types/node ^24)
• /home/sme/p/t3x-nr-llm/main/playwright.config.ts:30-35 (chromium project, Desktop Chrome)
• /home/sme/p/t3x-nr-llm/main/Build/Scripts/runTests.sh:290 (IMAGE_PLAYWRIGHT=mcr.microsoft.com/playwright:v1.57.0-noble)
• /home/sme/p/t3x-nr-llm/main/Build/Scripts/runTests.sh:422 (e2e runs npm ci && npx playwright test in that image)
• /home/sme/p/t3x-nr-llm/main/.ddev/web-build/Dockerfile:1-15 (current web-build customizations)
• /home/sme/p/t3x-nr-llm/main/.ddev/config.yaml (php_version: "8.5")
• docker run ddev/ddev-webserver:v1.25.2 -> /etc/os-release VERSION="13 (trixie)"; node v24.15.0; npm 11.12.1; apt-cache policy chromium Candidate 149.0.7827.53-1~deb13u1 (empirical, this session)
• https://packagist.org/packages/spatie/browsershot.json (browsershot 5.4.0, PHP ^8.2, symfony/process ^6|^7|^8, spatie/temporary-directory ^2.0)
• https://raw.githubusercontent.com/spatie/browsershot/main/src/Browsershot.php (windowSize/deviceScaleFactor/fullPage/transparentBackground/save/setScreenshotType/setNodeBinary/setNpmBinary/setChromePath/setNodeModulePath/setIncludePath/setBinPath signatures)
• https://raw.githubusercontent.com/spatie/browsershot/main/composer.json (no node/puppeteer deps bundled)
• https://github.com/spatie/browsershot/blob/main/README.md (conversion via Puppeteer / headless Chrome)
• https://spatie.be/docs/browsershot/v4/requirements (Node 22 LTS+, Puppeteer >= v23, binary path setters)
• https://github.com/spatie/browsershot/tree/main/bin (bin/browser.cjs exists -> requires puppeteer)
• https://playwright.dev/docs/api/class-page (setContent, setViewportSize, screenshot {fullPage,omitBackground,type,clip}, newContext {viewport,deviceScaleFactor})
• https://packages.debian.org/trixie/chromium (chromium 149.0.7827.53-1~deb13u1; chromium-common, chromium-sandbox)
• https://ddev.com/blog/release-v1250/ (DDEV v1.25.0: Debian trixie base for ddev-webserver, Node 24 default)


=================================================================
AREA: PDF ingestion (3-tier: embedded text / Vision-OCR for scanned / layout-aware tab
=================================================================

--- VERIFIED FACTS ---
[0] smalot/pdfparser latest stable = v2.12.5, released 2026-04-17 (verified Packagist p2 metadata https://repo.packagist.org/p2/smalot/pdfparser.json). composer require line: composer require smalot/pdfparser:^2.12

[1] smalot/pdfparser requires php >=7.1 and ext-zlib + ext-iconv; optional symfony/polyfill-mbstring ^1.18 (Packagist metadata). Compatible with the extension's PHP 8.3+ floor.

[2] smalot API: $parser = new \Smalot\PdfParser\Parser(); $pdf = $parser->parseFile($path); $text = $pdf->getText(). Document::getText(?int $pageLimit = null): string and Document::getPages() (returns Page[], throws MissingCatalogException) verified from src/Smalot/PdfParser/Document.php on master.

[3] Per-page text: Page::getText(?self $page = null): string verified from src/Smalot/PdfParser/Page.php on master.

[4] smalot has NO OCR support and throws \Exception with message 'Secured pdf file are currently not supported.' in Parser::parseContent() when the trailer 'encrypt' entry is set and getIgnoreEncryption() is false (README 'secured documents not supported'; doc/Usage.md; issues #488/#743 document false positives). Config::setIgnoreEncryption(true) can bypass the guard but does not decrypt.

[5] Poppler pdftotext 24.02.0 is installed at /usr/bin/pdftotext. Confirmed flags from pdftotext -h: -layout (maintain original physical layout), -f <int> first page, -l <int> last page, -enc <string> output encoding, -nopgbrk (no page-break form-feeds), -q (quiet), -opw/-upw (owner/user password for encrypted files), -tsv (TSV with bounding boxes). Default resolution 72 DPI.

[6] Poppler pdftoppm 24.02.0 is installed at /usr/bin/pdftoppm. Confirmed flags from pdftoppm -h: -png (PNG output), -r <fp> resolution in DPI (default 150), -f/-l page range, -singlefile (write only first page, no digit suffix), -gray, -scale-to <int> (fit page into NxN px box), -jpeg.

[7] Ghostscript (gs) is NOT installed (command not found) and is NOT required for the Poppler pdftoppm/pdftotext path — Poppler renders PDFs natively without a Ghostscript delegate.

[8] Imagick is NOT loaded in PHP (php -m shows only gd among image extensions). Even if installed, ImageMagick's default policy.xml since 2018 sets <policy domain="coder" rights="none" pattern="PDF"/>, blocking PDF rasterization unless relaxed; Imagick PDF rasterization also depends on a Ghostscript delegate. Therefore Poppler is the lower-friction tier-2 renderer.

[9] nr-llm integration surface (TYPO3 v13.4+/v14, PHP 8.2+): Netresearch\NrLlm\Service\Feature\VisionService::analyzeImage(string|array $imageUrl, string $customPrompt, ?VisionOptions $options = null): string|array (Classes/Service/Feature/VisionService.php:133). It calls validateImageUrl() which accepts a remote URL OR a data URI matching /^data:image\/(png|jpeg|jpg|gif|webp);base64,/ (VisionService.php:235-247).

[10] nr-llm VisionContent value object (Classes/Domain/ValueObject/VisionContent.php) confirms the wire shape: image items use type 'image_url' with image_url.url that 'may be a remote URL or a data:image/...;base64,... URI' (line 122-135); VisionContent::imageUrl($url, $detail) factory. Low-level entry point is VisionCapableInterface::analyzeImage(array $content, array $options): VisionResponse with $content as list<VisionContent>.

--- CODE SNIPPETS ---
### composer require
composer require smalot/pdfparser:^2.12

### .ddev/web-build/Dockerfile.pdf - Debian apt packages (poppler only; Ghostscript NOT needed for the Poppler path)
# .ddev/web-build/Dockerfile.pdf
# poppler-utils provides pdftotext + pdftoppm (the only required binaries).
# Add ghostscript ONLY if you later add an Imagick-based renderer fallback.
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        poppler-utils \
 && rm -rf /var/lib/apt/lists/*
# Optional (only if a future Imagick rasterization fallback is added):
#   ghostscript imagemagick php-imagick
# and relax /etc/ImageMagick-*/policy.xml PDF coder (rights=none -> read|write).

### Tier 1 - smalot embedded text + per-page + near-empty detection + encryption guard
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

final readonly class EmbeddedTextExtractor
{
    /** Minimum non-whitespace chars before a page counts as 'has text'. Tune on real docs. */
    private const MIN_CHARS_PER_PAGE = 80;

    /**
     * @return list<array{page:int, text:string, isSparse:bool}>
     * @throws \RuntimeException on encrypted/unsupported PDFs
     */
    public function extract(string $absPath): array
    {
        $config = new Config();
        // Mitigates known false-positive 'Secured pdf file' detection (issues #488/#743).
        // Remove if you want to hard-reject anything flagged as encrypted.
        $config->setIgnoreEncryption(true);

        $parser = new Parser([], $config);
        try {
            $document = $parser->parseFile($absPath);
        } catch (\Throwable $e) {
            // smalot throws \Exception('Secured pdf file are currently not supported.')
            throw new \RuntimeException(
                'PDF could not be parsed (possibly encrypted): ' . $e->getMessage(),
                1749379201,
                $e,
            );
        }

        $pages = [];
        foreach ($document->getPages() as $i => $page) {
            $text = trim($page->getText());
            $density = \strlen(preg_replace('/\s+/', '', $text) ?? '');
            $pages[] = [
                'page'     => $i + 1,
                'text'     => $text,
                'isSparse' => $density < self::MIN_CHARS_PER_PAGE,
            ];
        }

        return $pages;
    }
}

### Tier 2 - render one page to PNG via pdftoppm and OCR it through nr-llm Vision
use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Symfony\Component\Process\Process;

final readonly class VisionPdfOcr
{
    private const OCR_PROMPT = 'Transcribe ALL text in this page image verbatim, '
        . 'preserving reading order and line breaks. Output plain text only, no commentary.';

    public function __construct(private VisionServiceInterface $vision) {}

    /** OCR a single 1-based page of $absPath at $dpi DPI. */
    public function ocrPage(string $absPath, int $page, int $dpi = 200): string
    {
        $tmpPrefix = sys_get_temp_dir() . '/nrrepurpose_' . bin2hex(random_bytes(6));
        // pdftoppm -png -r <dpi> -f <page> -l <page> -singlefile <pdf> <prefix>
        // -singlefile => output is exactly <prefix>.png (no -NN page suffix).
        $proc = new Process([
            'pdftoppm', '-png',
            '-r', (string) $dpi,
            '-f', (string) $page,
            '-l', (string) $page,
            '-singlefile',
            $absPath, $tmpPrefix,
        ]);
        $proc->mustRun();

        $pngPath = $tmpPrefix . '.png';
        try {
            $bytes = file_get_contents($pngPath);
            if ($bytes === false) {
                throw new \RuntimeException('pdftoppm produced no PNG for page ' . $page, 1749379202);
            }
            $dataUri = 'data:image/png;base64,' . base64_encode($bytes);

            // VisionService::analyzeImage() validates data:image/png;base64,... URIs natively.
            $result = $this->vision->analyzeImage(
                $dataUri,
                self::OCR_PROMPT,
                (new VisionOptions())->withMaxTokens(2000),
            );

            return \is_array($result) ? implode("\n", $result) : $result;
        } finally {
            if (is_file($pngPath)) {
                @unlink($pngPath);
            }
        }
    }
}

### Tier 3 - layout/table-aware extraction via pdftotext -layout
use Symfony\Component\Process\Process;

final readonly class LayoutTextExtractor
{
    /** Extract one 1-based page preserving columns/tables; '-' writes to stdout. */
    public function extractLayout(string $absPath, int $page): string
    {
        // pdftotext -layout -f <page> -l <page> -enc UTF-8 -nopgbrk -q <pdf> -
        $proc = new Process([
            'pdftotext', '-layout',
            '-f', (string) $page,
            '-l', (string) $page,
            '-enc', 'UTF-8',
            '-nopgbrk',
            '-q',
            $absPath, '-',
        ]);
        $proc->mustRun();

        return rtrim($proc->getOutput());
    }
}

### Auto-mode tier dispatcher (per page)
// Pseudocode for the per-page decision; wire the three extractors above together.
// $pages comes from EmbeddedTextExtractor::extract().
foreach ($pages as $p) {
    if ($p['isSparse']) {
        // Tier 2: scanned/image page -> Vision OCR (cost: 1 LLM call/page)
        $text = $ocr->ocrPage($absPath, $p['page'], dpi: 200);
    } elseif ($this->looksTabular($p['text'])) {
        // Tier 3: re-extract with layout preservation for tables/columns
        $text = $layout->extractLayout($absPath, $p['page']);
    } else {
        // Tier 1: smalot embedded text is good enough
        $text = $p['text'];
    }
    // ... collect $text ...
}

// Cheap table heuristic: many lines with runs of 2+ spaces (column gutters).
private function looksTabular(string $text): bool
{
    $lines = preg_split('/\R/', $text) ?: [];
    $aligned = 0;
    foreach ($lines as $line) {
        if (preg_match('/\S {2,}\S/', $line)) {
            $aligned++;
        }
    }
    return $aligned >= 3; // tune on real documents
}

--- CITATIONS ---
• https://repo.packagist.org/p2/smalot/pdfparser.json (v2.12.5 released 2026-04-17; require php >=7.1, ext-zlib, ext-iconv)
• https://github.com/smalot/pdfparser/blob/master/src/Smalot/PdfParser/Document.php (getText(?int $pageLimit = null): string; getPages())
• https://github.com/smalot/pdfparser/blob/master/src/Smalot/PdfParser/Page.php (getText(?self $page = null): string)
• https://github.com/smalot/pdfparser/blob/master/README.md (no OCR; secured documents not supported)
• https://github.com/smalot/pdfparser/blob/master/src/Smalot/PdfParser/Parser.php (Parser::parseContent throws 'Secured pdf file are currently not supported.' when encrypt set and getIgnoreEncryption() false)
• https://github.com/smalot/pdfparser/issues/488 and https://github.com/smalot/pdfparser/issues/743 (false-positive 'Secured pdf file' detection)
• Local: pdftotext -h / pdftoppm -h output, version 24.02.0 (flags -layout, -f, -l, -enc, -nopgbrk, -r, -png, -singlefile)
• Local: which gs => not found; php -m => gd only, no imagick
• https://andycarter.dev/blog/how-to-fix-imagickexception-not-authorized and https://alexvanderbist.com/2018/fixing-imagick-error-unauthorized (ImageMagick policy.xml PDF coder disabled by default; Ghostscript delegate)
• /home/sme/p/t3x-nr-llm/main/Classes/Service/Feature/VisionService.php:133,235-247 (analyzeImage signature; data URI validation regex)
• /home/sme/p/t3x-nr-llm/main/Classes/Domain/ValueObject/VisionContent.php:122-135 (image_url accepts remote URL or data: URI)
• /home/sme/p/t3x-nr-llm/main/Classes/Provider/Contract/VisionCapableInterface.php:25 (analyzeImage(array $content, array $options): VisionResponse)


=================================================================
AREA: Runnable DDEV TYPO3 v14.3 instance bundling nr_repurpose + local path-checkout o
=================================================================

--- VERIFIED FACTS ---
[0] TYPO3 v14 latest release is 14.3.2; PHP requirement is 'PHP >= 8.2.0 <= 8.5.99' (get.typo3.org/version/14). So php_version '8.3' and '8.4' are both supported; '8.5' also works. Recommend 8.4 as upper-bound LTS-runtime default, 8.3 as floor.

[1] DDEV project type for TYPO3 is `typo3` (project-type=typo3) with docroot=public — confirmed by docs.ddev.com quickstart: `ddev config --project-type=typo3 --docroot=public`. The `ddev typo3` wrapper command only exists for type:typo3 projects.

[2] Source-verified v14 `typo3 setup` options from .Build/vendor/typo3/cms-install/Classes/Command/SetupCommand.php (installed v14.1.1, flags stable across 14.x): --driver, --host (default 'db'), --port (default '3306'), --dbname (default 'db'), --username (default 'db'), --password, --admin-username (default 'admin'), --admin-user-password (REQUIRED), --admin-email, --project-name, --create-site=<domain>, --server-type (default 'other'; e.g. 'apache'), --force, -n/--no-interaction.

[3] WARNING: a WebFetch summary of the DDEV quickstart returned WRONG flag names (--database-name, --admin-user-name, --admin-password). Those do NOT exist. The correct names are --dbname, --admin-username, --admin-user-password as proven by the SetupCommand.php source and the WebSearch quote of the actual DDEV quickstart script.

[4] netresearch's own install-v14 command (/home/sme/p/t3x-nr-llm/main/.ddev/commands/web/install-v14:30-58) uses: `composer create-project typo3/cms-base-distribution:^14 . --no-interaction --no-progress`, then `composer config repositories.nr_llm path "$EXTENSION_PATH"`, `composer config minimum-stability dev`, `composer config prefer-stable true`, `composer require netresearch/nr-llm:@dev`, then `vendor/bin/typo3 setup -n --dbname=... --password=... --create-site=https://... --admin-user-password=...`.

[5] v14 removed CLI dev-config commands; netresearch writes dev settings to config/system/additional.php instead (install-v14:60-86) — confirmed for v14.

[6] TYPO3 v14 core registers `messenger:consume` via #[AsCommand('messenger:consume','Consume messages')] in .Build/vendor/typo3/cms-core/Classes/Command/ConsumeMessagesCommand.php:42; registered in cms-core/Configuration/Services.yaml:139 with tagged messenger.receiver locator. Argument `receivers` (InputArgument::IS_ARRAY) = transport names; options include --sleep, --queues, --exit-code-on-limit plus Symfony-inherited --limit/--time-limit/--memory-limit.

[7] nr-llm composer.json requires php ^8.2, typo3/cms-core ^13.4||^14.0, netresearch/nr-vault ^0.4.0||^0.5.0; extension-key nr_llm; web-dir .Build/Web; type typo3-cms-extension. ext_emconf version 0.7.0, state beta, constraints typo3 13.4.0-14.99.99 / php 8.2.0-8.99.99.

[8] nr-llm injects API keys via docker-compose.web.yaml `environment:` using `${OPENAI_API_KEY:-}` / `${ANTHROPIC_API_KEY:-}` / `${GEMINI_API_KEY:-}` defaulted-empty (docker-compose.web.yaml), and .ddev/.gitignore + .env.dist keep real values untracked. .ddev/AGENTS.md explicitly says: 'Use the nr-vault extension for storing API keys, not DDEV env vars' — env is dev-only convenience.

[9] nr-repurpose/main currently contains ONLY .git and docs/ — there is NO composer.json yet (verified: `cat composer.json` -> No such file or directory). The instance composer.json and the extension's own composer.json must be authored.

[10] Local tooling present: ddev v1.25.2, docker 29.5.2, composer 2.9.7 (PHP 8.5.5). DDEV composer_version '2' is correct.

[11] nr-llm's web-build/Dockerfile installs php${PHP_VERSION}-pcov via apt-get (note: 'pecl not available in DDEV') and does apt-get dist-upgrade — a real example of the DDEV web-build Dockerfile append pattern (files named Dockerfile or Dockerfile.* are appended after DDEV's own; pre./prepend. variants insert earlier — per .ddev/web-build/README.txt).

--- CODE SNIPPETS ---
### .ddev/config.yaml (TYPO3 v14.3 instance — type typo3, docroot public)
name: nr-repurpose
type: typo3
docroot: public
php_version: "8.4"   # v14 supports 8.2-8.5; 8.4 = upper-bound LTS runtime. 8.3 also valid.
composer_version: "2"
webserver_type: apache-fpm   # nginx-fpm (DDEV default) also fine; apache-fpm matches nr-llm
database:
  type: mariadb
  version: "11.4"
web_environment:
  - TYPO3_CONTEXT=Development
# API keys come from untracked .ddev/.env via docker-compose.web.yaml, NOT here.

### instance composer.json — repositories + require (two local path repos)
{
  "name": "netresearch/nr-repurpose-instance",
  "type": "project",
  "minimum-stability": "dev",
  "prefer-stable": true,
  "repositories": [
    { "type": "path", "url": "../main" },
    { "type": "path", "url": "../../t3x-nr-llm/main" }
  ],
  "require": {
    "typo3/cms-base-distribution": "^14.3",
    "netresearch/nr-repurpose": "@dev",
    "netresearch/nr-llm": "@dev"
  },
  "extra": { "typo3/cms": { "web-dir": "public" } }
}
// NOTE: composer 'path' repos symlink the source by default (option "symlink": true).
// nr-llm pulls netresearch/nr-vault ^0.4||^0.5 transitively from its own require;
// add a 3rd path repo only if you want a LOCAL nr-vault checkout.

### Equivalent CLI (matches netresearch install-v14 + DDEV quickstart)
ddev config --project-type=typo3 --docroot=public --php-version=8.4
ddev start -y
ddev composer create-project "typo3/cms-base-distribution:^14.3" .
ddev composer config minimum-stability dev
ddev composer config prefer-stable true
ddev composer config repositories.nr_repurpose path ../main
ddev composer config repositories.nr_llm path ../../t3x-nr-llm/main
ddev composer require netresearch/nr-repurpose:@dev netresearch/nr-llm:@dev --no-interaction
ddev typo3 setup -n \
  --driver=mysqli --host=db --port=3306 --dbname=db --username=db --password=db \
  --admin-username=admin --admin-user-password='Demo123*' --admin-email=admin@example.com \
  --project-name='nr_repurpose Dev' \
  --create-site=https://nr-repurpose.ddev.site --server-type=apache --force
ddev typo3 extension:setup
ddev typo3 cache:flush

### .ddev/web-build/Dockerfile (ffmpeg, poppler, ghostscript, chromium + node deps for HTML->PNG)
# Appended after DDEV's own webimage Dockerfile (file named 'Dockerfile' or 'Dockerfile.*').
# DDEV web image is Debian-based; chromium pulls its own font/lib deps.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
        poppler-utils \
        ghostscript \
        chromium \
        fonts-liberation \
    && rm -rf /var/lib/apt/lists/*

# Puppeteer must use the system chromium (don't let it download its own).
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

# node + npm are already present in the DDEV web image (nodejs_version set via config.yaml
# or `ddev config --nodejs-version`); install the HTML->PNG renderer globally if needed:
RUN npm install -g puppeteer-core@latest

### .ddev/docker-compose.worker.yaml (continuous messenger:consume worker as extra service)
# Runs the Symfony Messenger consumer continuously. 'web' image reused so the
# TYPO3 binary + same PHP + bind mount are available. Restart loop survives
# time/memory limits (which exist deliberately to recycle the worker).
services:
  worker:
    container_name: ddev-${DDEV_SITENAME}-worker
    image: ${DDEV_WEBIMAGE}
    restart: "no"   # ddev manages lifecycle; use post-start hook to (re)start
    command:
      - bash
      - -c
      - |
        while true; do
          /var/www/html/vendor/bin/typo3 messenger:consume \
            --time-limit=3600 --memory-limit=256M --sleep=2 \
            || true
          sleep 2
        done
    volumes:
      - type: bind
        source: ../
        target: /var/www/html
    environment:
      - TYPO3_CONTEXT=Development
    labels:
      com.ddev.site-name: ${DDEV_SITENAME}
      com.ddev.approot: ${DDEV_APPROOT}
# 'messenger:consume' takes the transport/receiver NAME as argument when more than
# one is configured, e.g. `messenger:consume async`. With a single configured
# transport it defaults to that one (ConsumeMessagesCommand.php:59,68).

### Alternative: worker as a post-start hook (no extra container) + .ddev/.env for secrets
# .ddev/config.yaml hook (simpler; one less container):
hooks:
  post-start:
    - exec: "nohup vendor/bin/typo3 messenger:consume --time-limit=3600 --memory-limit=256M >/var/www/html/var/log/worker.log 2>&1 &"

# .ddev/.env (UNTRACKED — add to .ddev/.gitignore). DDEV auto-loads .ddev/.env
# and substitutes into docker-compose.*.yaml:
#   OPENAI_API_KEY=sk-...
#   ANTHROPIC_API_KEY=sk-ant-...
#   GEMINI_API_KEY=AIza-...

# .ddev/docker-compose.web.yaml — pass them into the web container without committing:
# services:
#   web:
#     environment:
#       - OPENAI_API_KEY=${OPENAI_API_KEY:-}
#       - ANTHROPIC_API_KEY=${ANTHROPIC_API_KEY:-}
#       - GEMINI_API_KEY=${GEMINI_API_KEY:-}

--- CITATIONS ---
• /home/sme/p/t3x-nr-llm/main/.ddev/commands/web/install-v14:30-58 (composer create-project ^14, path repo, minimum-stability dev, prefer-stable, require @dev, typo3 setup -n flags)
• /home/sme/p/t3x-nr-llm/main/.ddev/commands/web/install-v14:60-86 (v14 dev config via config/system/additional.php; v14 removed CLI config commands)
• /home/sme/p/t3x-nr-llm/main/.ddev/commands/web/install-v14:90 (vendor/bin/typo3 extension:setup)
• /home/sme/p/t3x-nr-llm/main/.Build/vendor/typo3/cms-install/Classes/Command/SetupCommand.php:66-160 (exact setup option names: driver,host,port,dbname,username,password,admin-username,admin-user-password,admin-email,project-name,create-site,server-type,force,no-interaction)
• /home/sme/p/t3x-nr-llm/main/.Build/vendor/typo3/cms-install/Classes/Command/SetupCommand.php:171-194 (env var names TYPO3_DB_*, TYPO3_SETUP_*, password-in-history warning)
• /home/sme/p/t3x-nr-llm/main/.Build/vendor/typo3/cms-core/Classes/Information/Typo3Version.php:22-23 (installed VERSION 14.1.1 / BRANCH 14.1)
• /home/sme/p/t3x-nr-llm/main/.Build/vendor/typo3/cms-core/Classes/Command/ConsumeMessagesCommand.php:42 (#[AsCommand('messenger:consume','Consume messages')]) and :57-90 (receivers IS_ARRAY argument; --sleep,--queues,--exit-code-on-limit options)
• /home/sme/p/t3x-nr-llm/main/.Build/vendor/typo3/cms-core/Configuration/Services.yaml:139-143 (ConsumeMessagesCommand DI registration with tagged messenger.receiver locator)
• /home/sme/p/t3x-nr-llm/main/composer.json (require: php ^8.2, typo3/cms-core ^13.4||^14.0, netresearch/nr-vault ^0.4.0||^0.5.0; extra.typo3/cms.extension-key nr_llm, web-dir .Build/Web)
• /home/sme/p/t3x-nr-llm/main/ext_emconf.php (version 0.7.0, state beta, typo3 13.4.0-14.99.99, php 8.2.0-8.99.99)
• /home/sme/p/t3x-nr-llm/main/.ddev/config.yaml (real netresearch DDEV config: php_version 8.5, composer_version 2, webserver_type apache-fpm, database mariadb 11.4, post-start hooks)
• /home/sme/p/t3x-nr-llm/main/.ddev/docker-compose.web.yaml (API keys via ${OPENAI_API_KEY:-} etc; TYPO3_DB_* and TYPO3_SETUP_* env; bind mount ../ -> /var/www/nr_llm)
• /home/sme/p/t3x-nr-llm/main/.ddev/docker-compose.services.yaml (extra service pattern: ollama/valkey with restart:unless-stopped, ddev labels, healthcheck)
• /home/sme/p/t3x-nr-llm/main/.ddev/web-build/Dockerfile and .ddev/web-build/README.txt (web-build Dockerfile append semantics, Dockerfile.* / pre. / prepend. variants)
• /home/sme/p/t3x-nr-llm/main/.ddev/AGENTS.md (Security: 'Use the nr-vault extension for storing API keys, not DDEV env vars')
• https://get.typo3.org/version/14 (TYPO3 v14 latest 14.3.2; PHP >= 8.2.0 <= 8.5.99)
• https://docs.ddev.com/en/stable/users/quickstart/ (ddev config --project-type=typo3 --docroot=public; ddev composer create-project typo3/cms-base-distribution:^14; ddev typo3 setup flags --dbname/--username/--admin-username/--admin-user-password/--create-site/--server-type/--force)
• Local tooling versions: `ddev version` v1.25.2; `docker --version` 29.5.2; `composer --version` 2.9.7 / PHP 8.5.5 (Bash output)
