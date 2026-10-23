<?php
/**
 * Response headers of the last http:// stream request (file_get_contents & co.).
 *
 * PHP 8.4 added http_get_last_response_headers() as the replacement for the magic local
 * $http_response_header. Before 8.4 the wrapper fills that local only when the calling scope names it,
 * so callers pass `$http_response_header ?? null` as the fallback; drop the argument once PHP 8.4 is the minimum.
 */
final class HttpResponseHeaders {
    public static function last(?array $legacyLocal): array {
        if (function_exists('http_get_last_response_headers')) return http_get_last_response_headers() ?? [];
        return $legacyLocal ?? [];
    }
}
