<?php

namespace App\Agent\Tool;

use App\Service\MailIndex;
use App\Service\ResultCollector;
use App\Service\SearchContext;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

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
        private readonly HubInterface $hub,
    ) {
    }

    /**
     * @param string $query  What to search for; natural language, synonyms welcome
     * @param string $from   Optional sender substring to bias toward (name or address)
     * @param string $after  Optional earliest date, YYYY-MM-DD
     * @param string $before Optional latest date, YYYY-MM-DD
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, string $from = '', string $after = '', string $before = ''): array
    {
        $userId = $this->context->userId ?? '';
        // Balanced hybrid (keyword + semantic). Deliberately not model-tunable:
        // the model steers relevance by reformulating the query, and exposing a
        // float knob tripped the tool-arg type coercion (the model sends 0/1 as
        // JSON ints, which the denormalizer rejects for a float parameter).
        $hits = $this->index->search($userId, $query, $from, $after, $before, 0.5);

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

        // Push the updated, relevance-ranked candidate set to the browser so the
        // results pane fills in and re-orders live as the agent searches.
        $this->publishCandidates();

        return $out ?: [['note' => 'no matches; try different terms or a broader query']];
    }

    /** Stream the current ranked candidates to the conversation's Mercure topic. */
    private function publishCandidates(): void
    {
        $topic = $this->context->topic;
        if (!$topic) {
            return;
        }
        try {
            $this->hub->publish(new Update($topic, json_encode([
                'type' => 'candidates',
                'candidates' => $this->collector->rankedList(),
            ], \JSON_THROW_ON_ERROR)));
        } catch (\Throwable) {
            // Live updates are best-effort; the final payload still arrives.
        }
    }

    /**
     * @param array<int,array<string,mixed>> $results the chosen emails, each {n:int, reason:string}
     */
    public function present(array $results): string
    {
        $r = $this->collector->present($results);

        if ($r['unknown']) {
            return \sprintf(
                'Presented %d result(s). Numbers %s matched no candidate — run search_emails again and present from the fresh results.',
                $r['presented'],
                implode(', ', array_map(static fn (int $n): string => '#'.$n, $r['unknown'])),
            );
        }

        return 'Presented '.$r['presented'].' result(s) to the user.';
    }
}
