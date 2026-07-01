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
}
