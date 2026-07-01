<?php

namespace App\Agent\Tool;

use App\Service\MailIndex;
use App\Service\ResultCollector;
use App\Service\SearchContext;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The tools Claude drives during the conversational search. Two tools, one class.
 */
#[AsTool(
    name: 'search_emails',
    description: 'Search the mailbox with hybrid keyword+semantic search. Returns a numbered candidate list (number, date, sender, subject, snippet). Reformulate freely and call repeatedly.',
    method: 'search',
)]
#[AsTool(
    name: 'present_results',
    description: 'Finish the request by presenting the chosen emails. Pass "results" as a list of objects, each with "n" (the candidate number) and "reason" (one line on why it matches).',
    method: 'present',
)]
final class EmailSearchTool
{
    public function __construct(
        private readonly MailIndex $index,
        private readonly ResultCollector $collector,
        private readonly SearchContext $context,
    ) {
    }

    /**
     * @param string $query          What to search for; natural language, synonyms welcome
     * @param string $from           Optional sender substring to bias toward (name or address)
     * @param string $after          Optional earliest date, YYYY-MM-DD
     * @param string $before         Optional latest date, YYYY-MM-DD
     * @param float  $semantic_ratio 0 = keyword only, 1 = meaning only; default 0.5
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, string $from = '', string $after = '', string $before = '', float $semantic_ratio = 0.5): array
    {
        $userId = $this->context->userId ?? '';
        $hits = $this->index->search($userId, $query, $from, $after, $before, $semantic_ratio);

        $out = [];
        foreach ($hits as $h) {
            $n = $this->collector->registerCandidate($h);
            $out[] = [
                'n' => $n,
                'date' => substr((string) ($h['dateISO'] ?? ''), 0, 10),
                'from' => $h['from'] ?? '',
                'subject' => $h['subject'] ?? '',
                'snippet' => $h['snippet'] ?? '',
            ];
        }

        return $out ?: [['note' => 'no matches; try different terms or a broader query']];
    }

    /**
     * @param array<int,array<string,mixed>> $results the chosen emails, each {n:int, reason:string}
     */
    public function present(array $results): string
    {
        $this->collector->present($results);

        return 'Presented '.\count($results).' result(s) to the user.';
    }
}
