<?php

declare(strict_types=1);

namespace App\Http\View;

/**
 * An audit actor (« operator:01a1876f-… ») as the console shows it: a readable
 * label for the main text, the full internal identifier kept apart for the
 * `title` attribute and the screen-reader text (F-08).
 */
final readonly class ActorLabel
{
    public function __construct(
        public string $label,
        public string $full,
    ) {}
}
