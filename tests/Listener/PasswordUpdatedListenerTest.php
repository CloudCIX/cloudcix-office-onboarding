<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\Tests\Listener;

use OCA\CloudCIXOnboarding\AppInfo\Application;
use OCA\CloudCIXOnboarding\Listener\PasswordUpdatedListener;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\PasswordUpdatedEvent;
use PHPUnit\Framework\TestCase;

final class PasswordUpdatedListenerTest extends TestCase {
	public function testClearsFlagForFlaggedUser(): void {
		$config = $this->createMock(IUserConfig::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$config->method('getValueString')
			->with('admin', Application::APP_ID, Application::FLAG_KEY, '0')
			->willReturn('1');
		$config->expects(self::once())
			->method('deleteUserConfig')
			->with('admin', Application::APP_ID, Application::FLAG_KEY);

		(new PasswordUpdatedListener($config))->handle(new PasswordUpdatedEvent($user, 'not-inspected'));
	}

	public function testIgnoresAbsentOrUnflaggedSetting(): void {
		$config = $this->createMock(IUserConfig::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$config->method('getValueString')->willReturn('0');
		$config->expects(self::never())->method('deleteUserConfig');

		(new PasswordUpdatedListener($config))->handle(new PasswordUpdatedEvent($user, 'not-inspected'));
	}

	public function testIgnoresOtherEventTypes(): void {
		$config = $this->createMock(IUserConfig::class);
		$config->expects(self::never())->method('getValueString');
		$config->expects(self::never())->method('deleteUserConfig');

		(new PasswordUpdatedListener($config))->handle(new Event());
	}
}
