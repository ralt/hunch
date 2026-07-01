<?php

namespace App\Message;

/** Dispatched to run a conversational search in the background (off the request). */
final class SearchMessage
{
    public function __construct(public readonly string $conversationId)
    {
    }
}
