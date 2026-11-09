<?php
/**
 * Sent when an admin resets a user's password from the Panel and ticks
 * "Email the new password" (bsbi-web#869). A site overrides it with its own
 * site/templates/emails/password-reset-by-admin.text.php.
 *
 * @var string $name
 * @var string $email
 * @var string $password
 * @var string $siteName
 * @var string $loginUrl
 */
?>
Hello <?= $name ?>,

Your password for the <?= $siteName ?> website has been reset by one of our administrators.

Your username: <?= $email ?>

Your new password: <?= $password ?>


Log in at <?= $loginUrl ?>

If you weren't expecting this, please reply to this email.
