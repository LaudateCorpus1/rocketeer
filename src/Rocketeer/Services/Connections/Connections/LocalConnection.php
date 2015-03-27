<?php

/*
 * This file is part of Rocketeer
 *
 * (c) Maxime Fabre <ehtnam6@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Rocketeer\Services\Connections\Connections;

use Closure;
use Rocketeer\Interfaces\ConnectionInterface;
use Rocketeer\Interfaces\HasRolesInterface;
use Rocketeer\Traits\HasLocator;
use Rocketeer\Traits\Properties\HasRoles;
use Symfony\Component\Process\Process;

/**
 * Stub of local connections to make Rocketeer work
 * locally when necessary.
 *
 * @author Maxime Fabre <ehtnam6@gmail.com>
 */
class LocalConnection implements ConnectionInterface, HasRolesInterface
{
    use HasLocator;
    use HasRoles;

    /**
     * Return status of the last command.
     *
     * @var int
     */
    protected $previousStatus;

    /**
     * Run a set of commands against the connection.
     *
     * @param string|array $commands
     * @param Closure|null $callback
     */
    public function run($commands, Closure $callback = null)
    {
        $commands = (array) $commands;
        $command = implode(' && ', $commands);

        $process = new Process($command);
        $process->setTimeout(null);

        $process->run(function ($type, $buffer) use ($callback) {
            if (Process::ERR === $type) {
                if ($callback) {
                    $callback($buffer.PHP_EOL);
                }
            } else {
                if ($callback) {
                    $callback($buffer.PHP_EOL);
                }
            }
        });

        try {
            $this->previousStatus = $process->wait();
        } catch (\RuntimeException $e) {
            $this->previousStatus = 1;
        }
    }

    /**
     * Get the exit status of the last command.
     *
     * @return int
     */
    public function status()
    {
        return $this->previousStatus;
    }

    /**
     * Upload a local file to the server.
     *
     * @param string $local
     * @param string $remote
     *
     * @codeCoverageIgnore
     *
     * @return int
     */
    public function put($local, $remote)
    {
        $local = $this->files->read($local);

        return $this->putString($local, $remote);
    }

    /**
     * Get the contents of a remote file.
     *
     * @param string $remote
     *
     * @codeCoverageIgnore
     *
     * @return string|null
     */
    public function getString($remote)
    {
        return $this->files->has($remote) ? $this->files->read($remote) : null;
    }

    /**
     * Upload a string to to the given file on the server.
     *
     * @param string $remote
     * @param string $contents
     *
     * @codeCoverageIgnore
     *
     * @return int
     */
    public function putString($remote, $contents)
    {
        return $this->files->put($remote, $contents);
    }
}
