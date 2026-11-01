<?php

namespace App;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * FrankenPHP's FRANKENPHP_RESET_KERNEL mode clones the kernel after every
     * request. AbstractKernel::__clone() drops the container without shutting
     * it down, so an open DBAL connection is only released once the garbage
     * collector breaks the container reference cycle. Under sustained load
     * that lag exhausts PostgreSQL's connection slots ("too many clients
     * already"), so close the connections deterministically first.
     *
     * The worker is configured with FRANKENPHP_RESET_KERNEL=0 (see
     * compose.yaml); this stays as a safety net for environments that turn
     * the per-request reset back on.
     */
    public function __clone(): void
    {
        if ($this->booted && $this->container instanceof Container && $this->container->has('doctrine')) {
            $registry = $this->container->get('doctrine');
            if ($registry instanceof ManagerRegistry) {
                foreach ($registry->getConnectionNames() as $id) {
                    // Only touch connections this request actually opened:
                    // get() would otherwise instantiate (and connect) an
                    // unused one just to close it again.
                    if (!$this->container->initialized($id)) {
                        continue;
                    }

                    $connection = $this->container->get($id);
                    if ($connection instanceof Connection) {
                        $connection->close();
                    }
                }
            }
        }

        parent::__clone();
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
