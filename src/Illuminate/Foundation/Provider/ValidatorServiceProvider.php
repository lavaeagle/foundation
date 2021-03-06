<?php namespace Illuminate\Foundation\Provider;

use Illuminate\Validation\Factory;
use Illuminate\Foundation\Application;

class ValidatorServiceProvider extends ServiceProvider {

	/**
	 * Register the service provider.
	 *
	 * @param  Illuminate\Foundation\Application  $app
	 * @return void
	 */
	public function register(Application $app)
	{
		$app['validator'] = $app->share(function($app)
		{
			$validator = new Factory($app['translator']);

			// The validation presence verifier is responsible for determing the existence
			// of values in a given data collection, typically a relational database or
			// other persistent data stores. And it is used to check for uniqueness.
			if (isset($app['validation.presence']))
			{
				$validator->setPresenceVerifier($app['validation.presence']);
			}

			return $validator;
		});
	}

}