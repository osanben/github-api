<?php

/**
 * @author  Miloslav Hůla
 *
 * @testCase
 */

require __DIR__ . '/../../bootstrap.php';


class MockClient implements Milo\Github\Http\IClient
{
	/** @var callable */
	public $onRequest;

	public function request(Milo\Github\Http\Request $request)
	{
		return call_user_func($this->onRequest, $request);
	}

	public function onRequest($cb) {}
	public function onResponse($cb) {}
}



class LoginTestCase extends Tester\TestCase
{
	/** @var Milo\Github\OAuth\Configuration */
	private $config;

	/** @var Milo\Github\Storages\SessionStorage */
	private $storage;

	/** @var MockClient */
	private $client;

	/** @var Milo\Github\OAuth\Login */
	private $login;


	public function setup()
	{
		$_SESSION = [];

		$config = new Milo\Github\OAuth\Configuration('c-id', 'c-secret');
		$this->storage = new Milo\Github\Storages\SessionStorage;
		$this->client = new MockClient;
		$this->login = new Milo\Github\OAuth\Login($config, $this->storage, $this->client);
	}


	public function testBasics()
	{
		Assert::same($this->client, $this->login->getClient());

		Assert::false($this->login->hasToken());
		Assert::exception(function() {
			$this->login->getToken();
		}, 'Milo\Github\LogicException', 'Token has not been obtained yet.');
	}


	public function testObtainToken()
	{
		$this->storage->set('auth.state', '*****');

		$this->client->onRequest = function(Milo\Github\Http\Request $request) {
			Assert::same('POST', $request->getMethod());
			Assert::same('https://github.com/login/oauth/access_token', $request->getUrl());
			Assert::same('client_id=c-id&client_secret=c-secret&code=tmp-code', $request->getContent());
			Assert::same('application/json', $request->getHeader('Accept'));
			Assert::same('application/x-www-form-urlencoded', $request->getHeader('Content-Type'));

			return new Milo\Github\Http\Response(200, [], '{"access_token":"hash","token_type":"type","scope":"a,b,c"}');
		};
		$token = $this->login->obtainToken('tmp-code', '*****');
		Assert::same('hash', $token->getValue());
		Assert::same('type', $token->getType());
		Assert::same(['a','b','c'], $token->getScopes());

		Assert::true($this->login->hasToken());
		Assert::type('Milo\Github\OAuth\Token', $this->login->getToken());

		Assert::same($this->login, $this->login->dropToken());
		Assert::false($this->login->hasToken());
	}


	public function testObtainTokenWithoutScopes()
	{
		$this->storage->set('auth.state', '*****');

		$this->client->onRequest = function() {
			return new Milo\Github\Http\Response(200, [], '{"access_token":"hash","token_type":"type","scope":""}');
		};
		$token = $this->login->obtainToken('tmp-code', '*****');
		Assert::same([], $token->getScopes());
	}


	public function testCsrf()
	{
		$this->storage->set('auth.state', '*****');

		Assert::exception(function() {
			$this->login->obtainToken('', '');
		}, 'Milo\Github\OAuth\LoginException', 'OAuth security state does not match.');
	}


	public function testClientFails()
	{
		$this->storage->set('auth.state', '*****');

		$this->client->onRequest = function() {
			throw new Milo\Github\Http\BadResponseException('fail');
		};
		$e = Assert::exception(function() {
			$this->login->obtainToken('', '*****');
		}, 'Milo\Github\OAuth\LoginException', 'HTTP request failed.');
		$e = Assert::exception(function() use ($e) {
			throw $e->getPrevious();
		}, 'Milo\Github\Http\BadResponseException', 'fail');
		Assert::null($e->getPrevious());
	}


	public function testInvalidJsonResponse()
	{
		$this->storage->set('auth.state', '*****');

		$this->client->onRequest = function() {
			return new Milo\Github\Http\Response(200, [], '{');
		};
		$e = Assert::exception(function() {
			$this->login->obtainToken('', '*****');
		}, 'Milo\Github\OAuth\LoginException', 'Bad JSON in response.');
		$e = Assert::exception(function() use ($e) {
			throw $e->getPrevious();
		}, 'Milo\Github\JsonException', 'Syntax error, malformed JSON');
		Assert::null($e->getPrevious());
	}


	public function testHttp404Response()
	{
		$this->storage->set('auth.state', '*****');

		$this->client->onRequest = function() {
			return new Milo\Github\Http\Response(404, [], '{"error":"fail"}');
		};
		$e = Assert::exception(function() {
			$this->login->obtainToken('', '*****');
		}, 'Milo\Github\OAuth\LoginException', 'fail');
		Assert::null($e->getPrevious());
	}


	public function testHttpNon200Response()
	{
		$this->storage->set('auth.state', '*****');
		$this->client->onRequest = function(Milo\Github\Http\Request $request) {
			return new Milo\Github\Http\Response(500, [], '');
		};
		$e = Assert::exception(function() {
			$this->login->obtainToken('', '*****');
		}, 'Milo\Github\OAuth\LoginException', 'Unexpected response.');
		Assert::null($e->getPrevious());
	}

}

(new LoginTestCase())->run();
