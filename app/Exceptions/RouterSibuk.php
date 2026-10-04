<?php

namespace App\Exceptions;

use RuntimeException;

class RouterSibuk extends RuntimeException
{
    public static function untuk(string $slug): self
    {
        return new self("Router {$slug} sedang dipakai proses lain (sinkron, pasang script, atau load balance). Coba lagi sebentar lagi.");
    }
}
