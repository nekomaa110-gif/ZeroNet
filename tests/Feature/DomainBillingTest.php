<?php

namespace Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

class DomainBillingTest extends TestCase
{
    private function routePengalihan(string $domain): bool
    {
        try {
            $app = require base_path('bootstrap/app.php');
            $app->afterBootstrapping(LoadConfiguration::class, fn ($app) => $app['config']->set('app.billing_domain', $domain));
            $app->make(Kernel::class)->bootstrap();

            return collect($app['router']->getRoutes()->getRoutes())
                ->contains(fn ($route) => $route->uri() === '{path?}');
        } finally {
            Container::setInstance($this->app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
        }
    }

    public function test_domain_billing_kosong_tidak_mendaftarkan_pengalihan(): void
    {
        $this->assertFalse($this->routePengalihan(''));
    }

    public function test_domain_billing_terisi_mendaftarkan_pengalihan(): void
    {
        $this->assertTrue($this->routePengalihan('billing.contoh.test'));
    }
}
