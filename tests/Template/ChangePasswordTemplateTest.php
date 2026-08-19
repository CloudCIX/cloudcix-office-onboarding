<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace {
	if (!function_exists('p')) {
		function p(mixed $value): void {
			echo htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}
	}

	if (!function_exists('style')) {
		function style(string $app, string $file): void {
		}
	}
}

namespace OCA\CloudCIXOnboarding\Tests\Template {
	use PHPUnit\Framework\TestCase;

	final class ChangePasswordTemplateTest extends TestCase {
		public function testLogoutLinkIncludesEncodedCsrfToken(): void {
			$_ = [
				'error' => null,
				'submitUrl' => '/apps/cloudcix_onboarding/password',
				'logoutUrl' => '/logout',
				'requesttoken' => 'csrf:token+value=',
			];
			$l = new class {
				public function t(string $text): string {
					return $text;
				}
			};

			ob_start();
			require dirname(__DIR__, 2) . '/templates/change-password.php';
			$output = (string)ob_get_clean();

			self::assertStringContainsString(
				'href="/logout?requesttoken=csrf%3Atoken%2Bvalue%3D"',
				$output,
			);
		}
	}
}
