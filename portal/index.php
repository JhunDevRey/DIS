<?php
// Bare portal.duqueimmigration.ca requests land here — send to login,
// which itself redirects to documents.php if already signed in.
header('Location: login.php');
exit;
