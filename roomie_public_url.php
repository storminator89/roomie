<?php
declare(strict_types=1);

// Only deployment configuration may authorize an absolute scan link. Never
// infer it from Host, Forwarded, Referer, a query parameter or even location.
function roomiePublicBase(mixed $value): ?string
{
    if (!is_string($value) || strlen($value) > 230
        || preg_match('@\Ahttps://([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?(/(?:[A-Za-z0-9._~-]+/)*[A-Za-z0-9._~-]*)?\z@D', $value, $parts) !== 1) {
        return null;
    }
    if (filter_var($parts[1], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        || (isset($parts[2]) && $parts[2] !== '' && ((int) $parts[2] < 1 || (int) $parts[2] > 65535))) {
        return null;
    }
    foreach (explode('/', $parts[3] ?? '') as $segment) {
        if ($segment === '.' || $segment === '..') {
            return null;
        }
    }
    return rtrim($value, '/');
}
