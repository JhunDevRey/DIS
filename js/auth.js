// js/auth.js
// Small helpers for the staff and client login pages: show/hide the
// password, warn when Caps Lock is on, and show "Signing in…" so a slow
// connection doesn't invite a second click.
(function () {
  const form = document.getElementById('authForm');
  if (!form) return;
  const password = document.getElementById('password');
  const eye = document.getElementById('togglePassword');
  const caps = document.getElementById('capsWarning');
  const submit = document.getElementById('authSubmit');

  eye.addEventListener('click', () => {
    const show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    eye.innerHTML = show ? '<i class="fa-regular fa-eye-slash"></i>' : '<i class="fa-regular fa-eye"></i>';
    const label = show ? 'Hide password' : 'Show password';
    eye.setAttribute('aria-label', label);
    eye.title = label;
    password.focus();
  });

  const checkCaps = (e) => {
    if (typeof e.getModifierState === 'function') caps.hidden = !e.getModifierState('CapsLock');
  };
  password.addEventListener('keydown', checkCaps);
  password.addEventListener('keyup', checkCaps);
  password.addEventListener('blur', () => { caps.hidden = true; });

  form.addEventListener('submit', () => {
    // Only fires once the browser's own required-field checks pass.
    password.type = 'password';
    submit.disabled = true;
    submit.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Signing in…';
  });

  // Coming back via the browser's Back button can restore the page with
  // the button still disabled — reset it.
  window.addEventListener('pageshow', () => {
    submit.disabled = false;
    submit.innerHTML = 'Sign in <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>';
  });
})();
