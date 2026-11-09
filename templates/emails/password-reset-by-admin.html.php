<?php
/**
 * Sent when an admin resets a user's password from the Panel and ticks
 * "Email the new password" (bsbi-web#869). A site overrides it with its own
 * site/templates/emails/password-reset-by-admin.html.php.
 *
 * @var string $name
 * @var string $email
 * @var string $password
 * @var string $siteName
 * @var string $loginUrl
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Your new password</title>
</head>
<body>
<div style="font-family: sans-serif; max-width: 600px;">
  <p>Hello <?= html($name) ?>,</p>

  <p>Your password for the <?= html($siteName) ?> website has been reset by one of our administrators.</p>

  <p>Your username: <strong><?= html($email) ?></strong></p>

  <p>Your new password:</p>
  <p style="background-color: #f3f3f3; padding: 15px; font-family: monospace; font-size: 18px; text-align: center;"><?= html($password) ?></p>

  <p><a href="<?= html($loginUrl) ?>">Log in to <?= html($siteName) ?></a></p>

  <p>If you weren't expecting this, please reply to this email.</p>
</div>
</body>
</html>
