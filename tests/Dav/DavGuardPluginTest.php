<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\Tests\Dav;

use OCA\CloudCIXOnboarding\AppInfo\Application;
use OCA\CloudCIXOnboarding\Dav\DavGuardPlugin;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SabrePluginEvent;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\NotAuthenticated;
use Sabre\DAV\Server;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

final class DavGuardPluginTest extends TestCase {
	public function testAddsItselfToDavServer(): void {
		[$plugin] = $this->plugin(null, '0');
		$server = $this->createMock(Server::class);
		$server->expects(self::once())->method('addPlugin')->with($plugin);

		$plugin->handle(new SabrePluginEvent($server));
	}

	public function testIgnoresOtherEvents(): void {
		[$plugin, $session, $config] = $this->plugin(null, '0');
		$session->expects(self::never())->method('getUser');
		$config->expects(self::never())->method('getValueString');

		$plugin->handle(new Event());
	}

	public function testRunsAfterDavAuthentication(): void {
		[$plugin] = $this->plugin(null, '0');
		$server = $this->createMock(Server::class);
		$server->expects(self::once())->method('on')->with(
			'beforeMethod:*',
			[$plugin, 'beforeMethod'],
			15,
		);

		$plugin->initialize($server);
	}

	public function testAllowsAnonymousDavRequest(): void {
		[$plugin, , $config] = $this->plugin(null, '0');
		$config->expects(self::never())->method('getValueString');

		$plugin->beforeMethod(
			$this->createMock(RequestInterface::class),
			$this->createMock(ResponseInterface::class),
		);
	}

	public function testAllowsUnflaggedDavSession(): void {
		$this->expectNotToPerformAssertions();
		[$plugin] = $this->plugin('admin', '0');

		$plugin->beforeMethod(
			$this->createMock(RequestInterface::class),
			$this->createMock(ResponseInterface::class),
		);
	}

	public function testRejectsFlaggedDavSession(): void {
		[$plugin] = $this->plugin('admin', '1');

		$this->expectException(NotAuthenticated::class);
		$this->expectExceptionMessage('Password change required');
		$plugin->beforeMethod(
			$this->createMock(RequestInterface::class),
			$this->createMock(ResponseInterface::class),
		);
	}

	/** @return array{DavGuardPlugin, IUserSession&\PHPUnit\Framework\MockObject\MockObject, IUserConfig&\PHPUnit\Framework\MockObject\MockObject} */
	private function plugin(?string $uid, string $flag): array {
		$session = $this->createMock(IUserSession::class);
		$config = $this->createMock(IUserConfig::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
			$config->method('getValueString')
				->with($uid, Application::APP_ID, Application::FLAG_KEY, '0')
				->willReturn($flag);
		}

		return [new DavGuardPlugin($session, $config), $session, $config];
	}
}
