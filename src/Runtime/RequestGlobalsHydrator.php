<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

use Narya\SDK\Contracts\NaryaRequest;

final class RequestGlobalsHydrator
{
    public function hydrate(NaryaRequest $request): void
    {
        $_GET = $request->getQueryParams();
        $_POST = [];
        $_REQUEST = $_GET;
        $_COOKIE = [];
        $_FILES = [];

        $raw = $request->getRaw();

        $_SERVER = array_merge(
            $_SERVER,
            isset($raw['server']) && is_array($raw['server']) ? $raw['server'] : []
        );

        $_SERVER['REQUEST_METHOD'] = $request->getMethod();
        $_SERVER['REQUEST_URI'] = $request->getUri();
        $_SERVER['QUERY_STRING'] = $request->getQuery();
        $_SERVER['REMOTE_ADDR'] = $request->getRemoteAddr();
        $_SERVER['HTTP_HOST'] = $request->getHost();
        $_SERVER['REQUEST_SCHEME'] = $request->getScheme();

        foreach ($request->getHeaders() as $name => $values) {
            $serverKey = $this->headerToServerKey((string) $name);
            $_SERVER[$serverKey] = is_array($values) ? implode(',', $values) : (string) $values;
        }
    }

    private function headerToServerKey(string $name): string
    {
        $key = strtoupper(str_replace('-', '_', $name));

        if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            return $key;
        }

        return 'HTTP_' . $key;
    }
}
