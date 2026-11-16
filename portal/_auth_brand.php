<?php // Brand panel shared by the client sign-in, forgot-password and reset-password pages. ?>
  <aside class="auth-brand">
    <div class="auth-brand-top">
      <span class="auth-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span>
      <span class="auth-brand-name">Duque Immigration<small>Client Portal</small></span>
    </div>
    <div class="auth-brand-copy">
      <h1>Your immigration case, step by step.</h1>
      <p>Upload your documents, see what's still needed, and talk to your case team — all in one secure place.</p>
      <ul class="auth-points">
        <li><span class="auth-point-icon"><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i></span>Upload documents securely</li>
        <li><span class="auth-point-icon"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i></span>Track each document's status</li>
        <li><span class="auth-point-icon"><i class="fa-solid fa-comments" aria-hidden="true"></i></span>Message your case team directly</li>
      </ul>
    </div>
    <div class="auth-brand-foot">&copy; <?= date('Y') ?> Duque Immigration</div>
  </aside>
