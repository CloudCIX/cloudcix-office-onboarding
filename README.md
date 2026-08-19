# CloudCIX Onboarding

`cloudcix_onboarding` is a small Nextcloud 34 app for CloudCIX Office. It forces only an explicitly marked user—the administrator created during appliance provisioning—to replace the bootstrap password before using Nextcloud.

It does not mark administrators or newly created users automatically. CloudCIX bootstrap selects the one affected account with `occ user:setting`.

## Requirements

- Nextcloud 34.x (`appinfo/info.xml` declares 34–34)
- PHP 8.2 or newer
- A local Nextcloud user backend that supports password changes
- The app directory must be named `cloudcix_onboarding`

The app performs no direct database access and stores no password. PostgreSQL, Redis, and EuroOffice require no changes.

## Appliance installation

Package or clone the app at this persistent host path:

```text
/opt/cloudcix-office/cloudcix_onboarding
```

Add the following bind mount to both the `nextcloud` and `cron` services in `/opt/cloudcix-office/compose.yaml`:

```yaml
services:
  nextcloud:
    volumes:
      - nextcloud_data:/var/www/html
      - /opt/cloudcix-office/cloudcix_onboarding:/var/www/html/custom_apps/cloudcix_onboarding:ro

  cron:
    volumes:
      - nextcloud_data:/var/www/html
      - /opt/cloudcix-office/cloudcix_onboarding:/var/www/html/custom_apps/cloudcix_onboarding:ro
```

The nested read-only mount remains visible inside the existing `/var/www/html` named volume and survives `docker compose down`, `docker compose up`, and container replacement.

This bind mount is preferable for the current appliance to a plain custom image. The official Nextcloud entrypoint copies `/usr/src/nextcloud/custom_apps` only when the persistent `/var/www/html/custom_apps` directory is empty, so an image containing only a copied app would not reliably update an existing volume. A custom image is viable only with an additional synchronization entrypoint.

Do not use `docker cp` as the production installation mechanism. It is not declared by Compose and does not deterministically populate a new volume.

For development, the same two read-only mounts can point to a local checkout instead.

## Bootstrap integration

In `/opt/cloudcix-office/scripts/bootstrap.sh`, insert these commands after the existing `Nextcloud ready.` message and before credentials are published or `/var/lib/cloudcix-office/.provisioned` is created:

```bash
echo "Enabling CloudCIX onboarding..."

docker exec -u www-data cloudcix-nextcloud \
    php occ app:enable cloudcix_onboarding

docker exec -u www-data cloudcix-nextcloud \
    php occ user:setting \
    "$NEXTCLOUD_ADMIN_USER" \
    cloudcix_onboarding \
    must_change_password \
    1
```

This is the exact Nextcloud 34 `user:setting` set syntax:

```text
php occ user:setting <uid> <app> <key> <value>
```

The existing `set -euo pipefail` makes provisioning fail if the app cannot be enabled or the administrator cannot be marked. Both commands are safe to repeat before the provision marker is created.

Useful inspection and recovery commands are:

```bash
# Read the flag
docker exec -u www-data cloudcix-nextcloud \
    php occ user:setting \
    "$NEXTCLOUD_ADMIN_USER" \
    cloudcix_onboarding \
    must_change_password

# Remove the flag manually
docker exec -u www-data cloudcix-nextcloud \
    php occ user:setting \
    "$NEXTCLOUD_ADMIN_USER" \
    cloudcix_onboarding \
    must_change_password \
    --delete
```

## How enforcement works

The app registers global Nextcloud AppFramework middleware. For authenticated users whose per-user flag is exactly `1`, it allows only:

- the onboarding GET and POST controller;
- the core GET logout route;
- GET/HEAD requests for generated `/css/` and `/js/` resources.

All other AppFramework controllers redirect to the password page. Static CSS, JavaScript, and images normally bypass PHP and are served directly by Apache.

AppFramework middleware does not wrap raw WebDAV endpoints. A `BeforeUserLoggedInEvent` listener therefore rejects the marked user's bootstrap password when it is used for direct authentication outside the normal `/login` route. The newly provisioned account must be marked before credentials are distributed and before any app password or client token is created.

Missing or unexpected flag values do not affect a user. This prevents unrelated users from being trapped.

## Secure password update

The GET route renders a small server-side Nextcloud page. It is authenticated and CSRF-exempt because it only displays HTML.

The POST route keeps Nextcloud's default CSRF protection. It:

1. verifies that the session user is still marked;
2. checks empty, mismatched, oversized, and reused passwords;
3. validates the current password with public `IUserManager::checkPassword()`;
4. throttles incorrect-current-password responses;
5. changes the password through public `IUser::setPassword()`;
6. exposes safe `HintException` text when the configured password policy rejects the new password;
7. redirects with `IURLGenerator` to the Files route.

`IUser::setPassword()` invokes Nextcloud's configured password policy. The app never hashes, logs, or persists a submitted password.

Nextcloud 34 has no public method to update the encrypted password stored with the active browser session token. Nextcloud's own Settings password controller uses `OC\User\Session::updateSessionTokenPassword()`. This app uses that one internal method so the successful request can remain logged in and redirect to Files. Compatibility must be reviewed before increasing the declared maximum Nextcloud version.

## Flag clearing

The public `OCP\User\Events\PasswordUpdatedEvent` fires only after a password backend reports success. The app's listener deletes `must_change_password` only when its value is `1` and never reads the password carried by the event.

Because the event is synchronous, the flag is gone before the controller redirects. A successful password change through another supported Nextcloud mechanism also completes onboarding; failed changes leave the flag intact.

## Automated tests

The tests follow Nextcloud's app PHPUnit layout. Mount or symlink this repository into a Nextcloud 34 source checkout as `custom_apps/cloudcix_onboarding`, install that checkout's development dependencies, then run:

```bash
phpunit -c custom_apps/cloudcix_onboarding/tests/phpunit.xml
```

Syntax and metadata checks:

```bash
xmllint --noout \
    custom_apps/cloudcix_onboarding/appinfo/info.xml \
    custom_apps/cloudcix_onboarding/tests/phpunit.xml

find custom_apps/cloudcix_onboarding -name '*.php' -print0 | \
    xargs -0 -n1 php -l
```

## Manual acceptance test

```text
1. Enable cloudcix_onboarding.

2. Mark admin:
   must_change_password=1

3. Open Nextcloud in private/incognito browser.

4. Login as admin using current password.

EXPECTED:
Automatically redirected to CloudCIX password-change screen.

5. Try navigating directly to Files.

EXPECTED:
Redirected back to password-change screen.

6. Submit wrong current password.

EXPECTED:
Clear error; password unchanged.

7. Submit valid current password and a new password.

EXPECTED:
Password changed successfully.

8. Application redirects to Files.

9. Browse around Nextcloud.

EXPECTED:
No further forced redirects.

10. Logout.

11. Attempt login with old password.

EXPECTED:
Rejected.

12. Login with new password.

EXPECTED:
Normal Nextcloud login; onboarding page does not appear.
```

Before step 7, also attempt WebDAV authentication with the bootstrap password. It must be rejected.

## License

GNU Affero General Public License v3.0 or later. See [LICENSE](LICENSE).
