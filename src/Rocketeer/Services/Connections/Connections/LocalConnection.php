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
     * @type int
     */
    protected $previousStatus;

    /**
     * Run a set of commands against the connection.
     *
     * @param string|array $commands
     * @param Closure|null $callback
     *
     * @return mixed
     */
    public function run($commands, Closure $callback = null)
    {
        $commands = (array) $commands;
        $command  = implode(' && ', $commands);

        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'a'], // STDOUT
            2 => ['pipe', 'a'], // STDERR
        ], $pipes);

        if (!is_resource($process)) {
            $this->previousStatus = -1;

            false;
        }

        $status = proc_get_status($process);
        while ($status['running']) {
            $wrote = false;

            // Suppressed error after SIGINT during shutdown:
            //     stream_select(): unable to select [4]: Interrupted system call
            $n = @stream_select($pipes, $w, $e, null);

            if ($n === false) {
                // No streams showed activity
                break;
            } elseif ($n === 0) {
                // Process timed out
                break;
            } elseif ($n > 0) {
                $wrote = true;

                // Loop through pipes that have activity
                foreach ($pipes as $key => $pipe) {
                    $line = '';
                    while (!feof($pipe)) {
                        $data = fread($pipe, 1);

                        // If this is a new line or carriage return, send the line
                        if ($data === "\n" || $data === "\r") {
                            $callback($line.PHP_EOL);
                            break;
                        } else {
                            // Otherwise continue to append and wait for a line to finish
                            $line .= $data;
                        }
                    }
                }
            }

            // When no output was present last iteration
            // give the CPU a break of 0.001 seconds
            if (! $wrote) {
                usleep(1000);
            }

            $status = proc_get_status($process);
        }

        $this->previousStatus = $status['exitcode'];

        proc_close($process);
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
        $local = $this->files->get($local);

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
        return $this->files->exists($remote) ? $this->files->get($remote) : null;
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
