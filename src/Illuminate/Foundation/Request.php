<?php namespace Illuminate\Foundation;

use Illuminate\Session\Store as SessionStore;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class Request extends SymfonyRequest {

	/**
	 * The Illuminate session store implementation.
	 *
	 * @var Illuminate\Session\Store
	 */
	protected $sessionStore;

	/**
	 * Determine if the request contains a given input item.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function has($key)
	{
		return trim((string) $this->input($key)) !== '';
	}

	/**
	 * Get all of the input and files for the request.
	 *
	 * @return array
	 */
	public function everything()
	{
		return array_merge($this->input(), $this->files->all());
	}

	/**
	 * Retrieve an input item from the request.
	 *
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return string
	 */
	public function input($key = null, $default = null)
	{
		$input = $this->request->all();

		// If the key is null, we'll merge the request input with the query string
		// and return the entire array. This makes it convenient to get all of
		// the inputs for the entire Request from both of the input sources.
		if (is_null($key))
		{
			return array_merge($input, $this->query());
		}

		$value = isset($input[$key]) ? $input[$key] : null;

		// If the value is null, we'll try to pull it from the query values then
		// return the default value if it doesn't exist. This allows query to
		// fallback in place of the input for the current request instance.
		if (is_null($value))
		{
			return $this->query($key, $default);
		}

		return $value;
	}

	/**
	 * Retrieve an old input item.
	 *
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return string
	 */
	public function old($key = null, $default = null)
	{
		return $this->getSessionStore()->getOldInput($key, $default);
	}

	/**
	 * Get a subset of the items from the input data.
	 *
	 * @param  array  $keys
	 * @return array
	 */
	public function only($keys)
	{
		$keys = is_array($keys) ? $keys : func_get_args();

		return array_intersect_key($this->input(), array_flip((array) $keys));
	}

	/**
	 * Get all of the input except for a specified array of items.
	 *
	 * @param  array  $keys
	 * @return array
	 */
	public function except($keys)
	{
		$keys = is_array($keys) ? $keys : func_get_args();

		return array_diff_key($this->input(), array_flip((array) $keys));
	}

	/**
	 * Retrieve a query string item from the request.
	 *
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return string
	 */
	public function query($key = null, $default = null)
	{
		return $this->retrieveItem('query', $key, $default);
	}

	/**
	 * Retrieve a cookie from the request.
	 *
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return string
	 */
	public function cookie($key = null, $default = null)
	{
		return $this->retrieveItem('cookies', $key, $default);
	}

	/**
	 * Retrieve a file from the request.
	 *
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return Symfony\Component\HttpFoundation\File\UploadedFile
	 */
	public function file($key = null, $default = null)
	{
		return $this->retrieveItem('files', $key, $default);
	}

	/**
	 * Determine if the uploaded data contains a file.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function has_file($key)
	{
		return $this->files->has($key);
	}

	/**
	 * Retrieve a header from the request.
	 *
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return string
	 */
	public function header($key = null, $default = null)
	{
		return $this->retrieveItem('headers', $key, $default);
	}

	/**
	 * Retrieve a parameter item from a given source.
	 *
	 * @param  string  $source
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return string
	 */
	protected function retrieveItem($source, $key, $default)
	{
		if (is_null($key))
		{
			return $this->$source->all();
		}
		else
		{
			return $this->$source->get($key, $default);
		}
	}

	/**
	 * Flash the input for the current request to the session.
	 *
	 * @param  string $filter
	 * @param  array  $keys
	 * @return void
	 */
	public function flash($filter = null, $keys = array())
	{
		$flash = ( ! is_null($filter)) ? $this->$filter($keys) : $this->input();

		$this->sessionStore->flashInput($flash);
	}

	/**
	 * Flush all of the old input from the session.
	 *
	 * @return void
	 */
	public function flush()
	{
		$this->sessionStore->flashInput(array());
	}

	/**
	 * Merge new input into the current request's input array.
	 *
	 * @param  array  $input
	 * @return void
	 */
	public function merge(array $input)
	{
		$this->request->add($input);

		$this->query->add($input);
	}

	/**
	 * Replace the input for the current request.
	 *
	 * @param  array  $input
	 * @return void
	 */
	public function replace(array $input)
	{
		$this->request->replace($input);

		$this->query->replace($input);
	}

	/**
	 * Get the JSON payload for the request.
	 *
	 * @return object
	 */
	public function json()
	{
		return json_decode($this->getContent());
	}

	/**
	 * Determine if the request is the result of an AJAX call.
	 * 
	 * @return bool
	 */
	public function ajax()
	{
		return $this->isXmlHttpRequest();
	}

	/**
	 * Get the root URL for the application.
	 *
	 * @return string
	 */
	public function getRootUrl()
	{
		return $this->getScheme().'://'.$this->getHttpHost().$this->getBasePath();
	}

	/**
	 * Get the Illuminate session store implementation.
	 *
	 * @return Illuminate\Session\Store
	 */
	public function getSessionStore()
	{
		if ( ! isset($this->sessionStore))
		{
			throw new \RuntimeException("Session store not set on request.");
		}

		return $this->sessionStore;
	}

	/**
	 * Set the Illuminate session store implementation.
	 *
	 * @param  Illuminate\Session\Store  $session
	 * @return void
	 */
	public function setSessionStore(SessionStore $session)
	{
		$this->sessionStore = $session;
	}

}
