<?php

namespace Infira\MeritAktiva\Tests;

use PHPUnit\Framework\TestCase;
use Infira\MeritAktiva\API;

/**
 * Guards against re-introducing curl_close() in API::send().
 *
 * curl_close() has been a no-op since PHP 8.0 and is deprecated as of PHP 8.5.
 * When the host project routes E_DEPRECATED through an exception handler (e.g.
 * Yii2), the call throws *after* the HTTP request already succeeded, so callers
 * see a request fail even though it went through. The handle is freed
 * automatically when it goes out of scope, so the call is simply removed.
 */
class CurlCloseTest extends TestCase
{
    public function testSendDoesNotCallDeprecatedCurlClose(): void
    {
        $method = new \ReflectionMethod(API::class, 'send');
        $source = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringNotContainsString(
            'curl_close',
            $source,
            'API::send() must not call curl_close() (no-op since PHP 8.0, deprecated in 8.5).'
        );
    }
}
