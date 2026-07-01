<?php

namespace App\Service;

use App\Agent\Tool\EmailSearchTool;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog;
use Symfony\AI\Platform\Capability;

/**
 * Builds a Symfony AI Agent at request time from a *specific* Anthropic API key,
 * so each user brings their own key (decrypted from the User entity). We can't
 * use the bundle's autowired agent — it bakes in a single env key at compile.
 *
 * The bundled model catalog stops at Opus 4.1, so we register claude-opus-4-8
 * (the current model, which the Anthropic API supports) via $additionalModels.
 */
final class AgentFactory
{
    public const MODEL = 'claude-opus-4-8';

    public const SYSTEM_PROMPT = <<<'PROMPT'
        You help someone find emails in their own mailbox from a vague,
        natural-language description.

        You have two tools:
        - search_emails: run a hybrid keyword+semantic search. Use it liberally
          and reformulate freely (synonyms, the likely sender, the topic).
          Results come back as a numbered candidate list.
        - present_results: finish by presenting the best 3-8 emails, each with a
          short reason it matches. Refer to emails by their number.

        If the request is genuinely ambiguous among distinct candidates, ask ONE
        short clarifying question with concrete options drawn from what you found,
        then stop and wait. Never ask just to confirm. If the top results clearly
        answer the request, present them without asking. Always finish a resolved
        request by calling present_results.
        PROMPT;

    public function __construct(private readonly EmailSearchTool $tool)
    {
    }

    public function forApiKey(#[\SensitiveParameter] string $apiKey): AgentInterface
    {
        $catalog = new ModelCatalog([
            self::MODEL => [
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

        $platform = AnthropicFactory::createPlatform($apiKey, modelCatalog: $catalog);
        $processor = new AgentProcessor(new Toolbox([$this->tool]));

        return new Agent($platform, self::MODEL, [$processor], [$processor]);
    }
}
