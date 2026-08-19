<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\Tests\Listener;

use OCA\CloudCIXOnboarding\AppInfo\Application;
use OCA\CloudCIXOnboarding\Listener\DirectLoginGuardListener;
use OCP\Config\IUserConfig;
use OCP\HintException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\Events\BeforeUserLoggedInEvent;
use PHPUnit\Framework\TestCase;

final class DirectLoginGuardListenerTest extends TestCase {
	public function testAllowsUnknownUser(): void {
		[$listener, $users, $config] = $this->listener('/remote.php/dav/files/missing');
		$users->method('get')->with('missing')->willReturn(null);
		$config->expects(self::never())->method('getValueString');

		$listener->handle(new BeforeUserLoggedInEvent('missing', 'secret'));
	}

	public function testAllowsUnflaggedUser(): void {
		$this->expectNotToPerformAssertions();
		[$listener, $users, $config] = $this->listener('/remote.php/dav/files/admin');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$users->method('get')->with('admin')->willReturn($user);
		$config->method('getValueString')->willReturn('0');

		$listener->handle(new BeforeUserLoggedInEvent('admin', 'secret'));
	}

	public function testAllowsFlaggedUserOnBrowserLoginRoute(): void {
		[$listener, $users, $config] = $this->listener('/login');
		$users->expects(self::never())->method('get');
		$config->expects(self::never())->method('getValueString');

		$listener->handle(new BeforeUserLoggedInEvent('admin', 'secret'));
	}

	public function testRejectsFlaggedDirectAuthenticationWithoutLeakingPassword(): void {
		[$listener, $users, $config] = $this->listener('/remote.php/dav/files/admin');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$users->method('get')->with('admin')->willReturn($user);
		$config->method('getValueString')
			->with('admin', Application::APP_ID, Application::FLAG_KEY, '0')
			->willReturn('1');

		try {
			$listener->handle(new BeforeUserLoggedInEvent('admin', 'bootstrap-secret'));
			self::fail('Flagged direct authentication was allowed');
		} catch (HintException $exception) {
			self::assertStringNotContainsString('bootstrap-secret', (string)$exception);
			self::assertSame('Change your password in CloudCIX Office before using this service.', $exception->getHint());
		}
	}

	/** @return array{DirectLoginGuardListener, IUserManager&\PHPUnit\Framework\MockObject\MockObject, IUserConfig&\PHPUnit\Framework\MockObject\MockObject} */
	private function listener(string $path): array {
		$users = $this->createMock(IUserManager::class);
		$config = $this->createMock(IUserConfig::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return [new DirectLoginGuardListener($users, $config, $request, $l10n), $users, $config];
	}
}
