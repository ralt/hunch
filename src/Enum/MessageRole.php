<?php

namespace App\Enum;

/** Mirrors braindump's AiMessageRole. */
enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
