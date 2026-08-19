<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\Tests\Dav;

use OCA\CloudCIXOnboarding\AppInfo\Application;
use OCA\CloudCIXOnboarding\Dav\DavSessionGuard;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DavSessionGuardTest extends TestCase {
	private const DAV_AUTHENTICATED = 'AUTHENTICATED_TO_DAV_BACKEND';

	/** @return array<string, array{string}> */
	public static function davServices(): array {
		return [
			'dav' => ['dav'],
			'webdav' => ['webdav'],
			'files' => ['files'],
			'caldav' => ['caldav'],
			'calendar' => ['calendar'],
			'carddav' => ['carddav'],
			'contacts' => ['contacts'],
		];
	}

	#[DataProvider('davServices')]
	public function testRejectsFlaggedSessionForEveryDavService(string $service): void {
		[$guard, $session] = $this->guard('/nextcloud/remote.php', '/' . $service . '/resource', 'admin', '1');
		$session->expects(self::once())->method('set')->with(self::DAV_AUTHENTICATED, '');

		$guard->enforce();
	}

	public function testAllowsAnonymousDavRequest(): void {
		$this->expectNotToPerformAssertions();
		[$guard] = $this->guard('/remote.php', '/dav/files/admin', null, '0');
		$guard->enforce();
	}

	public function testAllowsUnflaggedDavSession(): void {
		$this->expectNotToPerformAssertions();
		[$guard] = $this->guard('/remote.php', '/dav/files/admin', 'admin', '0');
		$guard->enforce();
	}

	public function testClearsItsDavBlockAfterFlagRemoval(): void {
		[$guard, $session] = $this->guard('/remote.php', '/dav/files/admin', 'admin', '0', '');
		$session->expects(self::once())->method('remove')->with(self::DAV_AUTHENTICATED);

		$guard->enforce();
	}

	public function testAllowsNonDavRemoteService(): void {
		$this->expectNotToPerformAssertions();
		[$guard] = $this->guard('/remote.php', '/status', 'admin', '1');
		$guard->enforce();
	}

	public function testAllowsNonRemoteScript(): void {
		$this->expectNotToPerformAssertions();
		[$guard] = $this->guard('/index.php', '/dav/files/admin', 'admin', '1');
		$guard->enforce();
	}

	/** @return array{DavSessionGuard, ISession&\PHPUnit\Framework\MockObject\MockObject} */
	private function guard(string $script, string $path, ?string $uid, string $flag, ?string $davAuthenticated = null): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getScriptName')->willReturn($script);
		$request->method('getPathInfo')->willReturn($path);
		$session = $this->createMock(IUserSession::class);
		$nextcloudSession = $this->createMock(ISession::class);
		$nextcloudSession->method('get')->with(self::DAV_AUTHENTICATED)->willReturn($davAuthenticated);
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
		return [new DavSessionGuard($request, $session, $config, $nextcloudSession), $nextcloudSession];
	}
}
