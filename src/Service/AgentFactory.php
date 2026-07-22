<?php

namespace App\Service;

use App\Agent\Tool\EmailSearchTool;
use App\Entity\User;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog as AnthropicModelCatalog;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Capability;

/**
 * Builds a Symfony AI Agent at request time from a *specific* user's provider
 * settings, so each user brings their own backend and key (decrypted from the
 * User entity). We can't use the bundle's autowired agent — it bakes in a
 * single env key/model at compile time.
 *
 * Three providers are supported: Anthropic (Claude), OpenAI (GPT), and Ollama
 * (local inference — no key, just a reachable server). All three support the
 * tool-calling the conversational search relies on, provided the chosen model
 * does (for Ollama, pick a tool-capable model such as llama3.1 or qwen2.5).
 */
final class AgentFactory
{
    public const PROVIDERS = ['anthropic', 'openai', 'ollama'];

    /** Sensible default model per provider when the user hasn't set one. */
    public const DEFAULT_MODELS = [
        'anthropic' => 'claude-opus-4-8',
        'openai' => 'gpt-4o-mini',
        'ollama' => 'llama3.1',
    ];

    public const OLLAMA_DEFAULT_URL = 'http://localhost:11434';

    public const SYSTEM_PROMPT = <<<'PROMPT'
        You help someone find emails in their own mailbox from a vague,
        natural-language description.

        You have three tools:
        - search_emails: run a hybrid keyword+semantic search. Use it liberally
          and reformulate freely (synonyms, the likely sender, the topic).
          Results come back as a numbered candidate list.
        - read_email: read the full text of one candidate by its number. Search
          snippets are short crops — when the user asks for a detail (a phone
          number, an address, a date, a reference, exact wording), read the
          email and answer the question directly in your reply, quoting the
          relevant part. Never tell the user to open the email for a detail
          you could read yourself.
        - present_results: finish by presenting the best 3-8 emails, each with a
          short reason it matches. Refer to emails by their number.

        Always write references to candidate emails as #N (e.g. #12) — exactly
        a hash sign followed by the number. The UI turns #N into a clickable
        link; any other spelling (n°12, no. 12, "candidate 12", №12) stays dead
        text. Reply in the user's language, but keep the #N format unchanged.

        If the request is genuinely ambiguous among distinct candidates, ask ONE
        short clarifying question with concrete options drawn from what you found,
        then stop and wait. Never ask just to confirm. If the top results clearly
        answer the request, present them without asking. Always finish a resolved
        request by calling present_results — in every language, and also when
        the answer only emerged after several searches or a clarifying detour.
        PROMPT;

    public function __construct(
        private readonly EmailSearchTool $tool,
        private readonly Crypto $crypto,
        private readonly SsrfGuard $ssrf,
    ) {
    }

    /** The effective model id for a user (their override, or the provider default). */
    public function modelFor(User $user): string
    {
        return $user->getAiModel() ?: (self::DEFAULT_MODELS[$user->getAiProvider()] ?? self::DEFAULT_MODELS['anthropic']);
    }

    public function forUser(User $user): AgentInterface
    {
        $model = $this->modelFor($user);
        $key = $user->hasApiKey() ? $this->crypto->decrypt((string) $user->getApiKeyEnc()) : '';

        $platform = match ($user->getAiProvider()) {
            'openai' => OpenAiFactory::createPlatform($key),
            'ollama' => $this->ollamaPlatform($user->getAiBaseUrl() ?: self::OLLAMA_DEFAULT_URL),
            default => AnthropicFactory::createPlatform($key, modelCatalog: $this->anthropicCatalog($model)),
        };

        $processor = new AgentProcessor(new Toolbox([$this->tool]));

        return new Agent($platform, $model, [$processor], [$processor]);
    }

    /** Guard the user-supplied Ollama URL against SSRF, then build the platform. */
    private function ollamaPlatform(string $baseUrl): \Symfony\AI\Platform\Platform
    {
        $this->ssrf->assertAllowedUrl($baseUrl);

        return OllamaFactory::createPlatform($baseUrl);
    }

    /**
     * The bundled Anthropic catalog stops at Opus 4.1, so register whatever model
     * the user picked (default claude-opus-4-8) with tool-calling capability.
     */
    private function anthropicCatalog(string $model): AnthropicModelCatalog
    {
        return new AnthropicModelCatalog([
            $model => [
                'class' => Claude::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::TOOL_CALLING,
                    Capability::INPUT_IMAGE,
                    Capability::INPUT_PDF,
                ],
            ],
        ]);
    }
}
