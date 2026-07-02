<?php

namespace App\Service;

/**
 * Turns an exception chain into an actionable message. webklex/php-imap wraps
 * the real cause (DNS failure, auth reject, TLS error, …) in a generic
 * "connection failed" ConnectionFailedException, so a bare getMessage() tells
 * the user nothing — append the root cause's message.
 */
final class RootCause
{
    public static function message(\Throwable $e): string
    {
        $root = $e;
        while (null !== $root->getPrevious()) {
            $root = $root->getPrevious();
        }
        $msg = $e->getMessage();
        if ($root !== $e && '' !== $root->getMessage() && $root->getMessage() !== $msg) {
            $msg .= ': '.$root->getMessage();
        }

        return $msg;
    }
}
