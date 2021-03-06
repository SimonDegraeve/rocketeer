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

use Illuminate\Container\Container;

/**
 * Finds configurations and paths
 *
 * @author Maxime Fabre <ehtnam6@gmail.com>
 */
class Igniter
{
	/**
	 * The Container
	 *
	 * @var Container
	 */
	protected $container;

	/**
	 * Build a new Igniter
	 *
	 * @param Container $app
	 */
	public function __construct(Container $app)
	{
		$this->app = $app;
	}

	/**
	 * Bind paths to the container
	 *
	 * @return void
	 */
	public function bindPaths()
	{
		$this->bindBase();
		$this->bindConfiguration();
	}

	////////////////////////////////////////////////////////////////////
	/////////////////////////////// IGNITION ///////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Get the path to the configuration folder
	 *
	 * @return string
	 */
	public function getConfigurationPath()
	{
		// Return path to Laravel configuration
		if ($this->app->bound('path')) {
			$laravel = $this->app['path'].'/config/packages/anahkiasen/rocketeer';
			if (file_exists($laravel)) {
				return $laravel;
			}
		}

		return $this->app['path.rocketeer.config'];
	}

	/**
	 * Export the configuration files
	 *
	 * @return void
	 */
	public function exportConfiguration()
	{
		$source      = __DIR__.'/../config';
		$destination = $this->getConfigurationPath();

		// Unzip configuration files
		$this->app['files']->copyDirectory($source, $destination);

		return $destination;
	}

	/**
	 * Replace placeholders in configuration
	 *
	 * @param string $folder
	 * @param array  $values
	 *
	 * @return void
	 */
	public function updateConfiguration($folder, array $values = array())
	{
		// Replace stub values in files
		$files = $this->app['files']->files($folder);
		foreach ($files as $file) {
			foreach ($values as $name => $value) {
				$contents = str_replace('{' .$name. '}', $value, file_get_contents($file));
				$this->app['files']->put($file, $contents);
			}
		}

		// Change repository in use
		$application = array_get($values, 'application_name');
		$this->app['rocketeer.server']->setRepository($application);
	}

	////////////////////////////////////////////////////////////////////
	//////////////////////////////// PATHS /////////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Bind the base path to the Container
	 *
	 * @return void
	 */
	protected function bindBase()
	{
		if ($this->app->bound('path.base')) {
			return;
		}

		$this->app->instance('path.base', getcwd());
	}

	/**
	 * Bind paths to the configuration files
	 *
	 * @return void
	 */
	protected function bindConfiguration()
	{
		$path  = $this->app['path.base'] ? $this->app['path.base'].'/' : '';
		$paths = array(
			'config' => '.rocketeer',
			'tasks'  => '.rocketeer/tasks',
		);

		foreach ($paths as $key => $file) {
			$file     = str_replace('tasks', 'tasks.php', $file);
			$filename = $path.$file;

			// Use configuration in current folder if none found
			$realpath = realpath('.').'/'.$file;
			if (!file_exists($filename) and file_exists($realpath)) {
				$filename = $realpath;
			}

			$this->app->instance('path.rocketeer.'.$key, $filename);
		}
	}
}
