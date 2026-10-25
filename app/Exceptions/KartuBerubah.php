<?php

namespace App\Exceptions;

use RuntimeException;

class KartuBerubah extends RuntimeException
{
    public static function baru(): self
    {
        return new self('Kartu berubah, muat ulang halaman.');
    }
}
