<?php 

namespace Bespired\ToPdf;

use Illuminate\Support\ServiceProvider;

class ToPdfServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
		$this->package('Bespired/topdf');

//		View::addNamespace('Bespired/formbuilder', __DIR__.'/../../views');
		
//		require_once __DIR__ . '/../../routes.php';

		$this->app['topdf'] = $this->app->share(function($app)
        {
            return new ToPdf;
        });

		$loader = \Illuminate\Foundation\AliasLoader::getInstance();
		$loader->alias('ToPdf', 'Bespired\ToPdf\Facades\ToPdf');
		$loader->alias('Mark',  'Bespired\ToPdf\Mark');


	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		//
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array();
	}

}
