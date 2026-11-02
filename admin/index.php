<?php
// Bare admin.duqueimmigration.ca requests land here — send to login,
// which itself redirects to clients.php if already signed in.
header('Location: login.php');
exit;
