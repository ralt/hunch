<?php

namespace App\Service;

/**
 * Per-request holder for the current user's id, set by the controller before
 * calling the agent, read by EmailSearchTool to scope retrieval. Shared service
 * (one instance per request), like ResultCollector.
 */
final class SearchContext
{
    public ?string $userId = null;

    /** Mercure topic for the current conversation, so tools can push live updates. */
    public ?string $topic = null;
}
