<?php namespace Illuminate\Foundation;

use Closure;
use Illuminate\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Illuminate\Foundation\Provider\ServiceProvider;
use Symfony\Component\HttpKernel\Debug\ErrorHandler;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\Debug\ExceptionHandler as KernelHandler;

class Application extends Container implements HttpKernelInterface {

	/**
	 * Indicates if the application has "booted".
	 *
	 * @var bool
	 */
	protected $booted = false;

	/**
	 * The current requests being executed.
	 *
	 * @var array
	 */
	protected $requestStack = array();

	/**
	 * The application middlewares.
	 *
	 * @var array
	 */
	protected $middlewares = array();

	/**
	 * The pattern to middleware bindings.
	 *
	 * @var array
	 */
	protected $patternMiddlewares = array();

	/**
	 * The global middlewares for the application.
	 *
	 * @var array
	 */
	protected $globalMiddlewares = array();

	/**
	 * All of the registered service providers.
	 *
	 * @var array
	 */
	protected $serviceProviders = array();

	/**
	 * All of the registered error handlers.
	 *
	 * @var array
	 */
	protected $errorHandlers = array();

	/**
	 * Create a new Illuminate application instance.
	 *
	 * @return void
	 */
	public function __construct()
	{
		$this['router'] = new Router;

		$this['request'] = Request::createFromGlobals();

		// The exception handler class takes care of determining which of the bound
		// exception handler Closures should be called for a given exception and
		// gets the response from them. We'll bind it here to allow overrides.
		$this->registerExceptionHandlers();
	}

	/**
	 * Detect the application's current environment.
	 *
	 * @param  array   $environments
	 * @return string
	 */
	public function detectEnvironment(array $environments)
	{
		$base = $this['request']->getHost();

		foreach ($environments as $environment => $hosts)
		{
			// To determine the current environment, we'll simply iterate through
			// the possible environments and look for a host that matches our
			// host in the requests context, then return that environment.
			foreach ($hosts as $host)
			{
				if (str_is($host, $base))
				{
					return $this['env'] = $environment;
				}
			}
		}

		return $this['env'] = 'default';
	}

	/**
	 * Register a service provider with the application.
	 *
	 * @param  Illuminate\Foundation\Provider\ServiceProvider  $provider
	 * @param  array  $options
	 * @return void
	 */
	public function register(ServiceProvider $provider, array $options = array())
	{
		$provider->register($this);

		// Once we have registered the service, we will iterate through the options
		// and set each of them on the application so they will be available on
		// the actual loading of the service objects and for developer usage.
		foreach ($options as $key => $value)
		{
			$this[$key] = $value;
		}

		$this->serviceProviders[] = $provider;
	}

	/**
	 * Register a "before" application middleware.
	 *
	 * @param  Closure  $callback
	 * @return void
	 */
	public function before(Closure $callback)
	{
		$this->globalMiddlewares['before'][] = $callback;
	}

	/**
	 * Register an "after" application middleware.
	 *
	 * @param  Closure  $callback
	 * @return void
	 */
	public function after(Closure $callback)
	{
		$this->globalMiddlewares['after'][] = $callback;
	}

	/**
	 * Register a "finish" application middleware.
	 *
	 * @param  Closure  $callback
	 * @return void
	 */
	public function finish(Closure $callback)
	{
		$this->globalMiddlewares['finish'][] = $callback;
	}

	/**
	 * Return a new response from the application.
	 *
	 * @param  string  $content
	 * @param  int     $status
	 * @param  array   $headers
	 * @return Symfony\Component\HttpFoundation\Response
	 */
	public function respond($content = '', $status = 200, array $headers = array())
	{
		return new Response($content, $status, $headers);
	}

	/**
	 * Return a new JSON response from the application.
	 *
	 * @param  string  $content
	 * @param  int     $status
	 * @param  array   $headers
	 * @return Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function json($data = array(), $status = 200, array $headers = array())
	{
		return new JsonResponse($data, $status, $headers);
	}

	/**
	 * Register a new middleware with the application.
	 *
	 * @param  string   $name
	 * @param  Closure  $callback
	 * @return void
	 */
	public function addMiddleware($name, Closure $callback)
	{
		$this->middlewares[$name] = $callback;
	}

	/**
	 * Get a registered middleware callback.
	 *
	 * @param  string   $name
	 * @return Closure
	 */
	public function getMiddleware($name)
	{
		if (array_key_exists($name, $this->middlewares))
		{
			return $this->middlewares[$name];
		}
	}

	/**
	 * Tie a registered middleware to a URI pattern.
	 *
	 * @param  string  $pattern
	 * @param  string|array  $name
	 * @return void
	 */
	public function matchMiddleware($pattern, $names)
	{
		foreach ((array) $names as $name)
		{
			$this->patternMiddlewares[$pattern][] = $name;
		}
	}

	/**
	 * Execute a callback in a request context.
	 *
	 * @param  Illuminate\Foundation\Request  $request
	 * @param  Closure  $callback
	 * @return mixed
	 */
	public function using(Request $request, Closure $callback)
	{
		// When making a request to a route, we'll push the current request object
		// onto the request stack and set the given request as the new request
		// that is active. This allows for true HMVC requests within routes.
		$this->requestStack[] = $this['request'];

		$this['request'] = $request;

		$result = $callback();

		// Once the route has been run we'll want to pop the old request back into
		// the active position so any request prior to an HMVC call can run as
		// expected without worrying about the HMVC request waxing its data.
		$this['request'] = array_pop($this->requestStack);

		return $result;
	}

	/**
	 * Handles the given request and delivers the response.
	 *
	 * @return void
	 */
	public function run()
	{
		$response = $this->dispatch($this['request']);

		$response->send();

		$this->callFinishMiddleware($response);
	}

	/**
	 * Handle the given request and get the response.
	 *
	 * @param  Illuminate\Foundation\Request  $request
	 * @return Symfony\Component\HttpFoundation\Response
	 */
	public function dispatch(Request $request)
	{
		// Before we handle the requests we need to make sure the application has been
		// booted up. The boot process will call the "boot" method on each service
		// provider giving them all a chance to register any application events.
		if ( ! $this->booted)
		{
			$this->boot();
		}

		$this->prepareRequest($request);

		// First we will call the "before" global middlware, which we'll give a chance
		// to override the normal requests process when a response is returned by a
		// middlewares. Otherwise we'll call the route just like a normal reuqest.
		$response =  $this->callGlobalMiddleware('before');

		if ( ! is_null($response))
		{
			return $this->prepareResponse($response, $request);
		}

		$route = $this['router']->dispatch($request);

		// Once we have the route and before middlewares, we will iterate through them
		// and call each one. If a given filter returns a response we will let that
		// value override the rest of the request cycle and return the Responses.
		$before = $this->getBeforeMiddlewares($route, $request);

		foreach ($before as $middleware)
		{
			$response = $this->callMiddleware($middleware);
		}

		// If none of the before middlewares returned a response, we will just execute
		// the route that matched the request, then call the after filters for this
		// and return the responses back out and they'll get sent to the clients.
		if ( ! isset($response))
		{
			$response = $route->run();
		}

		$response = $this->prepareResponse($response, $request);

		// Once all of the "after" middlewares are called we should be able to return
		// the completed response object back to the consumers so it will be given
		// to the client as a response. The Responses should be final and ready.
		foreach ($route->getAfterMiddlewares() as $middleware)
		{
			$this->callMiddleware($middleware, array($response));
		}

		$this->callAfterMiddleware($response);

		return $response;
	}

	/**
	 * Handle the given request and get the response.
	 *
	 * Provides compatibility with BrowserKit functional testing.
	 *
	 * @implements HttpKernelInterface::handle
	 *
	 * @param  Illuminate\Foundation\Request  $request
	 * @param  int   $type
	 * @param  bool  $catch
	 * @return Symfony\Component\HttpFoundation\Response
	 */
	public function handle(SymfonyRequest $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
	{
		return $this->dispatch($request);
	}

	/**
	 * Boot the application's service providers.
	 *
	 * @return void
	 */
	protected function boot()
	{
		foreach ($this->serviceProviders as $provider)
		{
			$provider->boot($this);
		}

		$this->booted = true;
	}

	/**
	 * Get the before middlewares for a request and route.
	 *
	 * @param  Illuminate\Routing\Route  $route
	 * @param  Illuminate\Foundation\Request  $request
	 * @return array
	 */
	protected function getBeforeMiddlewares(Route $route, Request $request)
	{
		$before = $route->getBeforeMiddlewares();

		return array_merge($before, $this->findPatternMiddlewares($request));
	}

	/**
	 * Find the patterned middlewares matching a request.
	 *
	 * @param  Illuminate\Foundation\Request  $request
	 * @return array
	 */
	protected function findPatternMiddlewares(Request $request)
	{
		$middlewares = array();

		foreach ($this->patternMiddlewares as $pattern => $values)
		{
			// To find the pattern middlewares for a request, we just need to check the
			// registered patterns against the path info for the current request to
			// the application, and if it matches we'll merge in the middlewares.
			if (str_is('/'.$pattern, $request->getPathInfo()))
			{
				$middlewares = array_merge($middlewares, $values);
			}
		}

		return $middlewares;
	}

	/**
	 * Prepare the request by injecting any services.
	 *
	 * @param  Illuminate\Foundation\Request  $request
	 * @return Illuminate\Foundation\Request
	 */
	public function prepareRequest(Request $request)
	{
		if (isset($this['session']))
		{
			$request->setSessionStore($this['session']);
		}

		return $request;
	}

	/**
	 * Prepare the given value as a Response object.
	 *
	 * @param  mixed  $value
	 * @param  Illuminate\Foundation\Request  $request
	 * @return Symfony\Component\HttpFoundation\Response
	 */
	public function prepareResponse($value, Request $request)
	{
		if ( ! $value instanceof Response) $value = new Response($value);

		return $value->prepare($request);
	}

	/**
	 * Call the "before" global middlware.
	 *
	 * @return mixed
	 */
	public function callAfterMiddleware(Response $response)
	{
		return $this->callGlobalMiddleware('after', array($response));
	}

	/**
	 * Call the "finish" global middlware.
	 *
	 * @return mixed
	 */
	public function callFinishMiddleware(Response $response)
	{
		return $this->callGlobalMiddleware('finish', array($response));
	}

	/**
	 * Call a given middleware with the parameters.
	 *
	 * @param  string  $name
	 * @param  array   $parameters
	 * @return mixed
	 */
	protected function callMiddleware($name, array $parameters = array())
	{
		array_unshift($parameters, $this['request']);

		if (isset($this->middlewares[$name]))
		{
			return call_user_func_array($this->middlewares[$name], $parameters);
		}
	}

	/**
	 * Call a given global middleware with the parameters.
	 *
	 * @param  string  $name
	 * @param  array   $parameters
	 * @return mixed
	 */
	protected function callGlobalMiddleware($name, array $parameters = array())
	{
		array_unshift($parameters, $this['request']);

		if (isset($this->globalMiddlewares[$name]))
		{
			// There may be multiple handlers registered for a global middleware so we
			// will need to spin through each one and execute each of them and will
			// return back first non-null responses we come across from a filter.
			foreach ($this->globalMiddlewares[$name] as $middleware)
			{
				$response = call_user_func_array($middleware, $parameters);

				if ( ! is_null($response)) return $response;
			}
		}
	}

	/**
	 * Throw an HttpException with the given data.
	 *
	 * @param  int     $code
	 * @param  string  $message
	 * @param  array   $headers
	 * @return void
	 */
	public function abort($code, $message = '', array $headers = array())
	{
		throw new HttpException($code, $message, null, $headers);
	}

	/**
	 * Register the exception handler instances.
	 *
	 * @return void
	 */
	protected function registerExceptionHandlers()
	{
		$this['exception'] = function() { return new ExceptionHandler; };

		$this['kernel.error'] = function() { return new ErrorHandler; };

		$this['kernel.exception'] = function() { return new KernelHandler; };
	}

	/**
	 * Register exception handling for the application.
	 *
	 * @return mixed
	 */
	public function startExceptionHandling()
	{
		// By registering the error handler with a level of -1, we state that we want
		// all PHP errors converted to ErrorExceptions and thrown, which provides
		// a quite strict development environment, but prevents unseen errors.
		$this['kernel.error']->register(-1);

		$me = $this;

		return $this->setExceptionHandler(function($exception) use ($me)
		{
			$handlers = $me->getErrorHandlers();

			$response = $me['exception']->handle($exception, $handlers);

			// If one of the custom error handlers returned a response, we will send that
			// response back to the client after preparing it. This allows a specific
			// type of exceptions to handled by a Closure giving great flexibility.
			if ( ! is_null($response))
			{
				$response = $me->prepareResponse($response, $me['request']);

				$response->send();
			}
			else
			{
				$me['kernel.exception']->handle($exception);
			}
		});
	}

	/**
	 * Set the given Closure as the exception handler.
	 *
	 * This function is mainly needed for mocking purposes.
	 *
	 * @param  Closure  $handler
	 * @return mixed
	 */
	protected function setExceptionHandler(Closure $handler)
	{
		return set_exception_handler($handler);
	}

	/**
	 * Register an application error handler.
	 *
	 * @param  Closure  $callback
	 * @return void
	 */
	public function error(Closure $callback)
	{
		$this->errorHandlers[] = $callback;
	}

	/**
	 * Get the current application request stack.
	 *
	 * @return array
	 */
	public function getRequestStack()
	{
		return $this->requestStack;
	}

	/**
	 * Get the array of error handlers.
	 *
	 * @return array
	 */
	public function getErrorHandlers()
	{
		return $this->errorHandlers;
	}

	/**
	 * Dynamically access application services.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function __get($key)
	{
		return $this[$key];
	}

	/**
	 * Dynamically set application services.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function __set($key, $value)
	{
		$this[$key] = $value;
	}

	/**
	 * Dynamically handle application method calls.
	 *
	 * @param  string  $method
	 * @param  array   $parameters
	 * @return mixed
	 */
	public function __call($method, $parameters)
	{
		if (strpos($method, 'redirectTo') === 0)
		{
			array_unshift($parameters, strtolower(substr($method, 10)));

			return call_user_func_array(array($this, 'redirectToRoute'), $parameters);
		}

		throw new \BadMethodCallException("Call to undefined method {$method}.");
	}

}