<?php
/*
 * This file is part of Rocketeer
 *
 * (c) Maxime Fabre <ehtnam6@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Rocketeer;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Rocketeer\Traits\AbstractLocatorClass;

/**
 * An helper to execute low-level commands on the remote server
 *
 * @author Maxime Fabre <ehtnam6@gmail.com>
 */
class Bash extends AbstractLocatorClass
{
	/**
	 * An history of executed commands
	 *
	 * @var array
	 */
	protected $history = array();

	/**
	 * Get the Task's history
	 *
	 * @return array
	 */
	public function getHistory()
	{
		return $this->history;
	}

	////////////////////////////////////////////////////////////////////
	///////////////////////////// CORE METHODS /////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Run actions on the remote server and gather the ouput
	 *
	 * @param  string|array $commands  One or more commands
	 * @param  boolean      $silent    Whether the command should stay silent no matter what
	 * @param  boolean      $array     Whether the output should be returned as an array
	 *
	 * @return string|array
	 */
	public function run($commands, $silent = false, $array = false)
	{
		$output   = null;
		$commands = $this->processCommands($commands);
		$verbose  = $this->getOption('verbose') and !$silent;

		// Log the commands for pretend
		if ($this->getOption('pretend') and !$silent) {
			return $this->addCommandsToHistory($commands);
		}

		// Run commands
		$me = $this;
		$this->remote->run($commands, function ($results) use (&$output, $verbose, $me) {
			$output .= $results;

			if ($verbose) {
				$me->remote->display(trim($results));
			}
		});

		// Explode output if necessary
		if ($array) {
			$output = explode($this->server->getLineEndings(), $output);
		}

		// Trim output
		$output = is_array($output)
			? array_filter($output)
			: trim($output);

		// Append output
		if (!$silent) {
			$this->history[] = $output;
		}

		return $output;
	}

	/**
	 * Run a raw command, without any processing, and
	 * get its output as a string or array
	 *
	 * @param  string|array $commands
	 * @param  boolean      $array     Whether the output should be returned as an array
	 *
	 * @return string
	 */
	public function runRaw($commands, $array = false)
	{
		$output = null;

		// Run commands
		$this->remote->run($commands, function ($results) use (&$output) {
			$output .= $results;
		});

		// Explode output if necessary
		if ($array) {
			$output = explode($this->server->getLineEndings(), $output);
			$output = array_filter($output);
		}

		return $output;
	}

	/**
	 * Run commands silently
	 *
	 * @param string|array  $commands
	 * @param boolean       $array
	 *
	 * @return string
	 */
	public function runSilently($commands, $array = false)
	{
		return $this->run($commands, true, $array);
	}

	/**
	 * Run commands in a folder
	 *
	 * @param  string        $folder
	 * @param  string|array  $tasks
	 *
	 * @return string
	 */
	public function runInFolder($folder = null, $tasks = array())
	{
		// Convert to array
		if (!is_array($tasks)) {
			$tasks = array($tasks);
		}

		// Prepend folder
		array_unshift($tasks, 'cd '.$this->rocketeer->getFolder($folder));

		return $this->run($tasks);
	}

	/**
	 * Check the status of the last run command, return an error if any
	 *
	 * @param  string $error        The message to display on error
	 * @param  string $output       The command's output
	 * @param  string $success      The message to display on success
	 *
	 * @return boolean|string
	 */
	public function checkStatus($error, $output = null, $success = null)
	{
		// If all went well
		if ($this->remote->status() == 0) {
			if ($success) {
				$this->command->comment($success);
			}

			return $output;
		}

		// Else
		$this->command->error($error);
		print $output.PHP_EOL;

		return false;
	}

	////////////////////////////////////////////////////////////////////
	/////////////////////////////// BINARIES ///////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Prefix a command with the right path to PHP
	 *
	 * @param string $command
	 *
	 * @return string
	 */
	public function php($command = null)
	{
		$php = $this->which('php');

		return trim($php. ' ' .$command);
	}

	/**
	 * Prefix a command with the right path to Artisan
	 *
	 * @param string $command
	 *
	 * @return string
	 */
	public function artisan($command = null)
	{
		$artisan = $this->which('artisan') ?: 'artisan';

		return $this->php($artisan. ' ' .$command);
	}

	/**
	 * Get a binary
	 *
	 * @param  string $binary    The name of the binary
	 * @param  string $fallback  A fallback location
	 *
	 * @return string
	 */
	public function which($binary, $fallback = null)
	{
		$location  = false;
		$locations = array(
			array($this->server,    'getValue',    'paths.'.$binary),
			array($this->rocketeer, 'getPath',     $binary),
			array($this,            'runSilently', 'which '.$binary),
		);

		// Add fallback if provided
		if ($fallback) {
			$locations[] = array($this, 'runSilently', 'which '.$fallback);
		}

		// Add command prompt if possible
		if ($this->hasCommand()) {
			$prompt      = $binary. ' could not be found, please enter the path to it';
			$locations[] = array($this->command, 'ask', $prompt);
		}

		// Look in all the locations
		$tryout = 0;
		while (!$location and array_key_exists($tryout, $locations)) {
			list($object, $method, $argument) = $locations[$tryout];

			$location = $object->$method($argument);
			$tryout++;
		}

		// Store found location
		$this->server->setValue('paths.'.$binary, $location);

		return $location ?: false;
	}

	////////////////////////////////////////////////////////////////////
	/////////////////////////////// FOLDERS ////////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Symlinks two folders
	 *
	 * @param  string $folder   The folder in shared/
	 * @param  string $symlink  The folder that will symlink to it
	 *
	 * @return string
	 */
	public function symlink($folder, $symlink)
	{
		if (!$this->fileExists($folder)) {
			if (!$this->fileExists($symlink)) {
				return false;
			}

			$this->move($symlink, $folder);
		}

		// Remove existing symlink
		$this->removeFolder($symlink);

		return $this->run(sprintf('ln -s %s %s', $folder, $symlink));
	}

	/**
	 * Move a file
	 *
	 * @param  string $origin
	 * @param  string $destination
	 *
	 * @return string
	 */
	public function move($origin, $destination)
	{
		$folder = dirname($destination);
		if (!$this->fileExists($folder)) {
			$this->createFolder($folder, true);
		}

		return $this->run(sprintf('mv %s %s', $origin, $destination));
	}

	/**
	 * Get the contents of a directory
	 *
	 * @param  string $directory
	 *
	 * @return array
	 */
	public function listContents($directory)
	{
		return $this->run('ls '.$directory, false, true);
	}

	/**
	 * Check if a file exists
	 *
	 * @param  string $file Path to the file
	 *
	 * @return boolean
	 */
	public function fileExists($file)
	{
		//$exists = $this->runRaw('if [ -e ' .$file. ' ]; then echo "true"; fi');
		$exists = $this->runRaw('[ -e ' .$file. ' ] && echo "true"');
		
		return trim($exists) == 'true';
	}

	/**
	 * Create a folder in the application's folder
	 *
	 * @param  string  $folder       The folder to create
	 * @param  boolean $recursive
	 *
	 * @return string The task
	 */
	public function createFolder($folder = null, $recursive = false)
	{
		$recursive = $recursive ? '-p ' : null;

		return $this->run('mkdir '.$recursive.$this->rocketeer->getFolder($folder));
	}

	/**
	 * Remove a folder in the application's folder
	 *
	 * @param  string $folder       The folder to remove
	 *
	 * @return string The task
	 */
	public function removeFolder($folder = null)
	{
		return $this->run('rm -rf '.$this->rocketeer->getFolder($folder));
	}

	////////////////////////////////////////////////////////////////////
	/////////////////////////////// HELPERS ////////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Get an option from the Command
	 *
	 * @param  string $option
	 *
	 * @return string
	 */
	protected function getOption($option)
	{
		return $this->hasCommand() ? $this->command->option($option) : null;
	}

	/**
	 * Add an array/command to the history
	 *
	 * @param string|array $commands
	 */
	protected function addCommandsToHistory($commands)
	{
		$this->command->line(implode(PHP_EOL, $commands));
		$commands = (sizeof($commands) == 1) ? $commands[0] : $commands;
		$this->history[] = $commands;

		return $commands;
	}

	/**
	 * Process an array of commands
	 *
	 * @param  string|array  $commands
	 *
	 * @return array
	 */
	protected function processCommands($commands)
	{
		$stage     = $this->rocketeer->getStage();
		$separator = $this->server->getSeparator();

		// Cast commands to array
		if (!is_array($commands)) {
			$commands = array($commands);
		}

		// Process commands
		foreach ($commands as &$command) {

			// Replace directory separators
			if (DS !== $separator) {
				$command = str_replace(DS, $separator, $command);
			}

			// Add stage flag to Artisan commands
			if (Str::contains($command, 'artisan') and $stage) {
				$command .= ' --env='.$stage;
			}

		}

		return $commands;
	}
}
