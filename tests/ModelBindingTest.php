<?php

require_once __DIR__.'/../src/Illuminate/Foundation/silex.phar';

class ModelBindingTest extends Silex\WebTestCase {

	public function testClosureModelBinder()
	{
		$this->app->modelBinder('user', function($id) { return $id.'!'; });
		$this->app->get('something/{user}', function($user) { return $user; });
		$client = $this->createClient();
		$crawler = $client->request('GET', '/something/taylor');
		$this->assertEquals('taylor!', $client->getResponse()->getContent());
	}


	public function testCustomModelBinder()
	{
		$this->app->modelBinder('user', 'ModelBindingTestBinderStub');
		$this->app->get('something/{user}', function($user) { return $user; });
		$client = $this->createClient();
		$crawler = $client->request('GET', '/something/taylor');
		$this->assertEquals('taylor!', $client->getResponse()->getContent());	
	}


	public function testMultiBinderRegistration()
	{
		$this->app->modelBinders(array('user' => 'foo', 'order' => 'bar'));
		$this->assertEquals('foo', $this->app->binders['user']);
		$this->assertEquals('bar', $this->app->binders['order']);
	}


	public function createApplication()
	{
		$app = new Illuminate\Foundation\Application;
		$app['debug'] = true;
		$app['ioc'] = new ModelBindingTestIoCStub;
		return $app;
	}

}

class ModelBindingTestIoCStub {

	public function resolve($class)
	{
		if ($class !== 'ModelBindingTestBinderStub')
		{
			throw new Exception("Invalid IoC argument.");
		}

		return new ModelBindingTestBinderStub;
	}

}

class ModelBindingTestBinderStub implements Illuminate\Foundation\IModelBinder {

	public function resolveBinding($id, Symfony\Component\HttpFoundation\Request $request)
	{
		return $id.'!';
	}

}