<?php
/*
 * This file is part of Rocketeer
 *
 * (c) Maxime Fabre <ehtnam6@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Rocketeer\Services\History;

use Illuminate\Support\Arr;
use Rocketeer\Traits\HasLocator;

/**
 * Handles rotation of logs.
 */
class LogsHandler
{
    use HasLocator;

    /**
     * Cache of the logs file to be written.
     *
     * @type array
     */
    protected $logs = [];

    /**
     * The name of the logs file.
     *
     * @type string[]
     */
    protected $name = [];

    /**
     * @param $line
     * @todo move this out to an event
     */
    public function publish($line)
    {
        // Do not publish in pretend mode, or in Brain without a log prefix set
        if ($this->getOption('pretend') || ($this->config->get('brain::active') && !$this->config->get('brain::log.active', true))) {
            return;
        }

        // Recurse for arrays
        if (is_array($line)) {
            foreach ($line as $entry) {
                $this->publish($entry);
            }

            return;
        }

        // Changes <fg=cyan> into <fg class="cyan"> from Symfony Console colours
        // Other formats:
        //   <fg=black;bg=cyan>foo</fg=black;bg=cyan>
        //   <bg=yellow;options=bold>foo</bg=yellow;options=bold>
        $pattern = '(?:(?:fg|bg|options)=([a-z]+);?)';
        $line = preg_replace("/" . $pattern . "/", 'fg class="$1"', $line);
        $line = preg_replace("/\/" . $pattern . ">/", '</fg>', $line);

        $release = $this->releasesManager->getNextRelease();
        $prefix = $this->config->get('brain::log.prefix', 'deploy:'.$release);

        // Long-term storage
        $this->redis->rpush($prefix.':log', $line);

        if (!$this->config->get('brain::log.publish', true)) {
            return;
        }

        // Real-time eventing
        $this->redis->publish($prefix, json_encode(array(
            'event' => 'log',
            'line'  => $line,
        )));
    }

    /**
     * Save something for the logs.
     *
     * @param string|string[] $string
     */
    public function log($string, $publish = true)
    {
        // No stage means we're actually not ready to log yet
        // This is a symptom of calling this method from Bash, where stage is
        // figured out for the first time
        if (! $this->connections->getStage()) {
            return;
        }

        // Create entry in the logs
        $file = $this->getCurrentLogsFile();
        if (!isset($this->logs[$file])) {
            $this->logs[$file] = [];
        }

        if ($publish) {
            $this->publish($this->prependHandle($string));
        }

        // Prepend currenth handle
        $this->logs[$file][] = $this->prependHandle($string);
    }

    /**
     * Write the stored logs.
     *
     * @return array
     */
    public function write()
    {
        foreach ($this->logs as $file => $entries) {
            if (!$file) {
                continue;
            }

            // Create the file if it doesn't exist
            if (!$this->files->exists($file)) {
                $this->createLogsFile($file);
            }

            $this->files->put($file, $this->formatEntries($entries));
        }

        return array_keys($this->logs);
    }

    //////////////////////////////////////////////////////////////////////
    //////////////////////////// CURRENT LOGS ////////////////////////////
    //////////////////////////////////////////////////////////////////////

    /**
     * Get the logs file being currently used.
     *
     * @return string|false
     */
    public function getCurrentLogsFile()
    {
        $hash = (string) $this->connections->getCurrentConnection();
        if (array_key_exists($hash, $this->name)) {
            return $this->name[$hash];
        }

        // Get the namer closure
        $namer = $this->config->get('logs');

        // Cancel if invalid namer
        if (!$namer) {
            return false;
        }

        // Compute name
        $name = is_callable($namer) ? $namer($this->connections) : $namer;
        $name = $this->app['path.rocketeer.logs'].'/'.$name;

        // Save for reuse
        $this->name[$hash] = $name;

        return $name;
    }

    /**
     * Get the current logs.
     *
     * @return array
     */
    public function getLogs()
    {
        return array_get($this->logs, $this->getCurrentLogsFile());
    }

    /**
     * Get the current logs, flattened.
     *
     * @return string
     */
    public function getFlattenedLogs()
    {
        return $this->formatEntries($this->getLogs());
    }

    //////////////////////////////////////////////////////////////////////
    ////////////////////////////// HELPERS ///////////////////////////////
    //////////////////////////////////////////////////////////////////////

    /**
     * Create a logs file if it doesn't exist.
     *
     * @param string $file
     */
    protected function createLogsFile($file)
    {
        $directory = dirname($file);

        // Create directory
        if (!is_dir($directory)) {
            $this->files->makeDirectory($directory, 0777, true);
        }

        // Create file
        if (!file_exists($file)) {
            $this->files->put($file, '');
        }
    }

    /**
     * Format entries to a string.
     *
     * @param array $entries
     *
     * @return string
     */
    protected function formatEntries($entries)
    {
        $entries = Arr::flatten($entries);
        $entries = implode(PHP_EOL, $entries);

        return $entries;
    }

    /**
     * Prepend the connection handle to each log entry.
     *
     * @param string|string[] $entries
     *
     * @return string|string[]
     */
    protected function prependHandle($entries)
    {
        $entries = (array) $entries;
        $handle  = $this->connections->getCurrentConnection()->toLongHandle();

        foreach ($entries as $key => $entry) {
            $entry = str_replace('<comment>['.$handle.']</comment> ', null, $entry);
            $entry = sprintf('[%s] %s', $handle, $entry);

            $entries[$key] = $entry;
        }

        return count($entries) === 1 ? $entries[0] : $entries;
    }
}
