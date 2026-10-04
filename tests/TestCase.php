<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        $koneksi = config('database.default');
        $nama    = (string) config("database.connections.{$koneksi}.database");

        if ($nama !== ':memory:' && ! str_ends_with($nama, '_test')) {
            throw new RuntimeException("Test dihentikan: database \"{$nama}\" bukan database uji. Jalankan dari worktree dengan phpunit.mariadb.xml, jangan dari direktori produksi.");
        }

        return parent::setUpTraits();
    }
}
