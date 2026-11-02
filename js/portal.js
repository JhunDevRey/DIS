// portal/dashboard.php behaviour: uploads + comments
// showToast/showConfirm/showNotice/openModal/closeModal live in js/ui-kit.js
// (included before this file on every page).

document.querySelectorAll('.upload-input').forEach(input => {
  input.addEventListener('change', async () => {
    if (!input.files.length) return;
    const requestId = input.dataset.requestId;
    const label = input.closest('label');
    const textEl = label.querySelector('.label-text');
    const originalText = textEl.textContent;
    const files = Array.from(input.files);

    let successCount = 0;
    let failCount = 0;

    for (let i = 0; i < files.length; i++) {
      textEl.textContent = files.length > 1 ? `Uploading ${i + 1} of ${files.length}…` : 'Uploading…';

      const fd = new FormData();
      fd.append('request_id', requestId);
      fd.append('document', files[i]);

      try {
        const res = await fetch('api/upload_document.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
          successCount++;
        } else {
          failCount++;
          showToast(`"${files[i].name}": ${data.message || 'Upload failed.'}`, true);
        }
      } catch (err) {
        failCount++;
        showToast(`"${files[i].name}": Network error.`, true);
      }
    }

    if (successCount > 0) {
      showToast(files.length > 1
        ? `${successCount} of ${files.length} file${files.length === 1 ? '' : 's'} uploaded.`
        : 'Document uploaded.');
      setTimeout(() => location.reload(), 600);
    } else {
      textEl.textContent = originalText;
    }

    input.value = '';
  });
});

document.querySelectorAll('[data-delete-file]').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!(await showConfirm('Delete this file? This cannot be undone.', { danger: true, confirmLabel: 'Delete' }))) return;
    const fileId = btn.dataset.deleteFile;
    try {
      const res = await fetch('api/client_delete_file.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `file_id=${encodeURIComponent(fileId)}`
      });
      const data = await res.json();
      if (data.success) {
        const row = btn.closest('.file-row');
        // .closest(), not .parentElement — a row beyond the 5th lives
        // inside .file-list-extra, not directly inside .doc-meta.
        const docMeta = row.closest('.doc-meta');
        const extra = row.closest('.file-list-extra');
        row.remove();

        // Keep the "show N more" toggle in sync: remove it entirely if
        // that was the last collapsed file, otherwise update its count.
        const toggle = docMeta.querySelector('[data-toggle-files]');
        if (extra && toggle) {
          const remaining = extra.querySelectorAll('.file-row').length;
          if (remaining === 0) {
            extra.remove();
            toggle.remove();
          } else if (extra.hidden) {
            toggle.querySelector('.file-list-toggle-label').textContent =
              `Show ${remaining} more file${remaining === 1 ? '' : 's'}`;
          }
        }

        if (!docMeta.querySelector('.file-row')) {
          docMeta.innerHTML = '<span style="font-size:12.5px;color:var(--text-muted);">No file uploaded yet</span>';
        } else {
          renumberFileRows(docMeta);
        }
        showToast('File deleted.');
      } else {
        showToast(data.message || 'Could not delete file.', true);
      }
    } catch (err) {
      showToast('Network error.', true);
    }
  });
});

document.querySelectorAll('[data-toggle-comments]').forEach(btn => {
  btn.addEventListener('click', () => {
    const id = btn.dataset.toggleComments;
    const section = document.getElementById('comment-section-' + id);
    const isOpen = section.classList.toggle('open');
    btn.classList.toggle('open', isOpen);
    // Keeps a collapsed comment section out of the tab order and hidden
    // from screen readers — display:none gave this for free before;
    // the grid-based collapse (needed so this can actually animate) is
    // still technically laid out at 0 height, so this has to be set
    // explicitly now instead.
    section.inert = !isOpen;
  });
});

// Expand/collapse files beyond the 5th in a checklist item — the extra
// files sit in a sibling .file-list-extra container within the same
// .doc-meta block, found by traversal rather than an id so no per-row
// id bookkeeping is needed.
document.querySelectorAll('[data-toggle-files]').forEach(btn => {
  const moreLabel = btn.querySelector('.file-list-toggle-label').textContent;
  btn.addEventListener('click', () => {
    const extra = btn.previousElementSibling;
    const isOpen = extra.hasAttribute('hidden') === false;
    if (isOpen) {
      extra.hidden = true;
      btn.querySelector('.file-list-toggle-label').textContent = moreLabel;
      btn.classList.remove('open');
    } else {
      extra.hidden = false;
      btn.querySelector('.file-list-toggle-label').textContent = 'Show less';
      btn.classList.add('open');
    }
  });
});

document.querySelectorAll('.comment-form').forEach(form => {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const requestId = form.dataset.requestId;
    const textarea = form.querySelector('textarea');
    const text = textarea.value.trim();
    if (!text) return;

    try {
      const res = await fetch('api/add_comment_client.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `request_id=${encodeURIComponent(requestId)}&comment_text=${encodeURIComponent(text)}`
      });
      const data = await res.json();
      if (data.success) {
        const list = form.parentElement.querySelector('.comment-list');
        const div = document.createElement('div');
        div.className = 'comment from-client';
        div.innerHTML = `<div class="comment-head"><span>You</span><span>just now</span></div>${text.replace(/</g,'&lt;').replace(/\n/g,'<br>')}`;
        list.appendChild(div);
        textarea.value = '';

        const docItem = form.closest('.doc-item');
        const countEl = docItem.querySelector('.comment-count');
        if (countEl) countEl.textContent = String(parseInt(countEl.textContent, 10) + 1);
      } else {
        showToast(data.message || 'Could not post comment.', true);
      }
    } catch (err) {
      showToast('Network error.', true);
    }
  });
});

// Application Review — client confirms a staff-uploaded form is correct,
// or flags an issue with a required note explaining what's wrong.
document.querySelectorAll('[data-review-confirm]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const requestId = btn.dataset.reviewConfirm;
    if (!(await showConfirm('Confirm this application is correct as shown?', { confirmLabel: 'Yes, looks correct' }))) return;
    await submitReviewResponse(requestId, 'confirm', null, btn);
  });
});

document.querySelectorAll('[data-review-flag-toggle]').forEach(btn => {
  btn.addEventListener('click', () => {
    const requestId = btn.dataset.reviewFlagToggle;
    const form = document.getElementById('review-flag-form-' + requestId);
    if (form) form.classList.add('open');
  });
});

document.querySelectorAll('[data-review-flag-cancel]').forEach(btn => {
  btn.addEventListener('click', () => {
    const requestId = btn.dataset.reviewFlagCancel;
    const form = document.getElementById('review-flag-form-' + requestId);
    if (form) {
      form.classList.remove('open');
      form.querySelector('textarea').value = '';
    }
  });
});

document.querySelectorAll('.review-flag-form').forEach(form => {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const requestId = form.dataset.requestId;
    const note = form.querySelector('textarea').value.trim();
    if (!note) return;
    const submitBtn = form.querySelector('button[type=submit]');
    await submitReviewResponse(requestId, 'flag', note, submitBtn);
  });
});

async function submitReviewResponse(requestId, action, note, triggerBtn) {
  if (triggerBtn) triggerBtn.disabled = true;
  try {
    const body = new URLSearchParams({ request_id: requestId, action });
    if (note) body.set('note', note);
    const res = await fetch('api/submit_review_response.php', { method: 'POST', body });
    const data = await res.json();
    if (data.success) {
      showToast(data.message || 'Response saved.');
      setTimeout(() => location.reload(), 500);
    } else {
      showToast(data.message || 'Could not save your response.', true);
      if (triggerBtn) triggerBtn.disabled = false;
    }
  } catch (err) {
    showToast('Network error.', true);
    if (triggerBtn) triggerBtn.disabled = false;
  }
}
