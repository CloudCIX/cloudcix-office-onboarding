<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array{error: ?string, submitUrl: string, logoutUrl: string, requesttoken: string} $_
 */

style('cloudcix_onboarding', 'change-password');
?>

<main class="cloudcix-onboarding">
	<section class="cloudcix-onboarding__card" aria-labelledby="cloudcix-onboarding-title">
		<div class="cloudcix-onboarding__brand">
			<img class="cloudcix-onboarding__logo" src="<?php p(image_path('cloudcix_onboarding', 'cloudcix-logo.png')); ?>" alt="CloudCIX">
		</div>

		<h1 id="cloudcix-onboarding-title"><?php p($l->t('Change your password')); ?></h1>
		<p><?php p($l->t('For security, you must choose a new password before continuing to CloudCIX Office.')); ?></p>

		<?php if ($_['error'] !== null): ?>
			<p class="cloudcix-onboarding__error" role="alert" aria-live="polite"><?php p($_['error']); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php p($_['submitUrl']); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">

			<label for="current-password"><?php p($l->t('Current password')); ?></label>
			<input id="current-password" name="currentPassword" type="password" autocomplete="current-password" required autofocus>

			<label for="new-password"><?php p($l->t('New password')); ?></label>
			<input id="new-password" name="newPassword" type="password" autocomplete="new-password" required>

			<label for="confirm-password"><?php p($l->t('Confirm new password')); ?></label>
			<input id="confirm-password" name="confirmPassword" type="password" autocomplete="new-password" required>

			<button type="submit" class="primary"><?php p($l->t('Change password')); ?></button>
		</form>

		<a class="cloudcix-onboarding__logout" href="<?php p($_['logoutUrl'] . '?requesttoken=' . urlencode($_['requesttoken'])); ?>"><?php p($l->t('Log out')); ?></a>
	</section>
</main>
