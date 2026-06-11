<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Psr\Log\AbstractLogger;

final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
    public array $records = [];

    /** @param array<string,mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
