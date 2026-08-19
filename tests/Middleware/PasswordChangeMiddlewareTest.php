<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\Tests\Middleware;

use Exception;
use OCA\CloudCIXOnboarding\AppInfo\Application;
use OCA\CloudCIXOnboarding\Controller\PasswordController;
use OCA\CloudCIXOnboarding\Exception\PasswordChangeRequiredException;
use OCA\CloudCIXOnboarding\Middleware\PasswordChangeMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class PasswordChangeMiddlewareTest extends TestCase {
	public function testAllowsAnonymousRequest(): void {
		[$middleware] = $this->middleware(null, '0');
		$this->expectNotToPerformAssertions();

		$middleware->beforeController($this->createMock(Controller::class), 'index');
	}

	public function testAllowsAuthenticatedUserWithoutFlag(): void {
		[$middleware] = $this->middleware($this->user(), '0');
		$this->expectNotToPerformAssertions();

		$middleware->beforeController($this->createMock(Controller::class), 'index');
	}

	public function testBlocksFlaggedUser(): void {
		[$middleware] = $this->middleware($this->user(), '1');
		$this->expectException(PasswordChangeRequiredException::class);

		$middleware->beforeController($this->createMock(Controller::class), 'index');
	}

	public function testAllowsFlaggedUserPasswordControllerWithoutLoop(): void {
		[$middleware, $request] = $this->middleware($this->user(), '1');
		$this->expectNotToPerformAssertions();

		$controller = (new \ReflectionClass(PasswordController::class))->newInstanceWithoutConstructor();
		$middleware->beforeController($controller, 'show');
	}

	public function testAllowsFlaggedUserLogout(): void {
		[$middleware, $request] = $this->middleware($this->user(), '1');
		$request->method('getMethod')->willReturn('GET');
		$request->method('getPathInfo')->willReturn('/logout');
		$this->expectNotToPerformAssertions();

		$middleware->beforeController($this->createMock(Controller::class), 'logout');
	}

	public function testAllowsGeneratedCssAndJsReads(): void {
		$this->expectNotToPerformAssertions();
		foreach (['/css/core/123.css', '/js/core/123.js'] as $path) {
			[$middleware, $request] = $this->middleware($this->user(), '1');
			$request->method('getMethod')->willReturn('GET');
			$request->method('getPathInfo')->willReturn($path);
			$middleware->beforeController($this->createMock(Controller::class), 'asset');
		}
	}

	public function testBlocksMutatingRequestToResourcePath(): void {
		[$middleware, $request] = $this->middleware($this->user(), '1');
		$request->method('getMethod')->willReturn('POST');
		$request->method('getPathInfo')->willReturn('/css/core/123.css');
		$this->expectException(PasswordChangeRequiredException::class);

		$middleware->beforeController($this->createMock(Controller::class), 'asset');
	}

	public function testConvertsOnlyPasswordChangeExceptionToRedirect(): void {
		[$middleware] = $this->middleware(null, '0');
		$response = $middleware->afterException(
			$this->createMock(Controller::class),
			'index',
			new PasswordChangeRequiredException(),
		);

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/index.php/apps/cloudcix_onboarding/password', $response->getRedirectURL());
	}

	public function testRethrowsUnrelatedException(): void {
		[$middleware] = $this->middleware(null, '0');
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('unrelated');

		$middleware->afterException($this->createMock(Controller::class), 'index', new Exception('unrelated'));
	}

	/** @return array{PasswordChangeMiddleware, IRequest&\PHPUnit\Framework\MockObject\MockObject} */
	private function middleware(?IUser $user, string $flag): array {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$config = $this->createMock(IUserConfig::class);
		$config->method('getValueString')
			->with('admin', Application::APP_ID, Application::FLAG_KEY, '0')
			->willReturn($flag);
		$request = $this->createMock(IRequest::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->with('cloudcix_onboarding.password.show')
			->willReturn('/index.php/apps/cloudcix_onboarding/password');

		return [new PasswordChangeMiddleware($session, $config, $request, $urlGenerator), $request];
	}

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		return $user;
	}
}
