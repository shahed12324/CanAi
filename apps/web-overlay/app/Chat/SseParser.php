<?php

declare(strict_types=1);

namespace App\Chat;

/** Incremental Server-Sent-Events parser: feed raw chunks (any split), get complete events. */
final class SseParser
{
    private string $buffer = '';

    /** @return list<array{event: string, data: array<string, mixed>}> */
    public function feed(string $chunk): array
    {
        $this->buffer .= str_replace(["\r\n", "\r"], "\n", $chunk);
        $events = [];
        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $block = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);
            $event = 'message';
            $data = [];
            foreach (explode("\n", $block) as $line) {
                if ($line === '' || $line[0] === ':') {
                    continue;
                }
                [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
                $value = ltrim($value, ' ');
                if ($field === 'event') {
                    $event = $value;
                } elseif ($field === 'data') {
                    $data[] = $value;
                }
            }
            $decoded = json_decode(implode("\n", $data), true);
            $events[] = ['event' => $event, 'data' => is_array($decoded) ? $decoded : []];
        }

        return $events;
    }
}
