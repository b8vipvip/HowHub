(() => {
  document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
      if (!window.confirm(form.dataset.confirm || '确认执行操作？')) event.preventDefault();
    });
  });

  const editor = document.getElementById('rich-editor');
  if (!editor) return;

  const form = document.getElementById('guide-form');
  const hidden = document.getElementById('body-html');
  const picker = document.getElementById('image-picker');
  const message = document.getElementById('editor-message');
  const token = form.querySelector('[name="csrf_token"]').value;
  const cover = document.getElementById('cover-image');
  const preview = document.getElementById('cover-preview');
  let imageTarget = 'body';
  let lastSelection = null;

  function messageText(text, isError = false) {
    message.textContent = text;
    message.classList.toggle('is-error', isError);
  }
  function rememberSelection() {
    const selection = window.getSelection();
    if (selection.rangeCount && editor.contains(selection.anchorNode)) lastSelection = selection.getRangeAt(0).cloneRange();
  }
  function restoreSelection() {
    editor.focus();
    if (!lastSelection) return;
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(lastSelection);
  }
  editor.addEventListener('mouseup', rememberSelection);
  editor.addEventListener('keyup', rememberSelection);
  editor.addEventListener('input', rememberSelection);

  function insertImage(src) {
    restoreSelection();
    const img = document.createElement('img');
    img.src = src;
    img.alt = '教程配图';
    img.loading = 'lazy';
    const figure = document.createElement('figure');
    figure.append(img);
    const paragraph = document.createElement('p');
    paragraph.append(document.createElement('br'));
    const selection = window.getSelection();
    if (selection.rangeCount && editor.contains(selection.anchorNode)) {
      const range = selection.getRangeAt(0);
      range.deleteContents();
      range.insertNode(paragraph);
      range.insertNode(figure);
      range.setStart(paragraph, 0);
      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
    } else {
      editor.append(figure, paragraph);
    }
    rememberSelection();
  }

  function setCover(src) {
    cover.value = src;
    preview.replaceChildren();
    const img = document.createElement('img');
    img.src = src;
    img.alt = '封面预览';
    preview.append(img);
  }

  async function uploadFile(file, destination = 'body') {
    if (!file) return;
    if (file.size > 6 * 1024 * 1024) return messageText('图片必须小于 6MB。', true);
    const data = new FormData();
    data.append('csrf_token', token);
    data.append('image', file);
    messageText('正在上传图片…');
    try {
      const response = await fetch('/admin/upload', { method: 'POST', body: data, credentials: 'same-origin' });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || '上传失败');
      if (destination === 'cover') setCover(result.url);
      else insertImage(result.url);
      messageText('图片已上传并插入。');
    } catch (error) {
      messageText(error.message || '上传失败，请重试。', true);
    }
  }

  document.querySelectorAll('[data-command], [data-action]').forEach(button => {
    button.addEventListener('mousedown', event => event.preventDefault());
    button.addEventListener('click', () => {
      const command = button.dataset.command;
      if (command) {
        restoreSelection();
        document.execCommand(command, false, button.dataset.value || null);
        rememberSelection();
        return;
      }
      const action = button.dataset.action;
      if (action === 'image') {
        imageTarget = 'body';
        picker.click();
      } else if (action === 'remote-image') {
        const url = window.prompt('输入图片网址（https://...）：');
        if (url === null) return;
        if (!/^https?:\/\//i.test(url.trim())) return messageText('请输入有效的 http(s) 图片网址。', true);
        insertImage(url.trim());
      } else if (action === 'link') {
        const url = window.prompt('输入链接（https://...）：');
        if (url === null) return;
        if (!/^https?:\/\//i.test(url.trim())) return messageText('请输入有效的 http(s) 链接。', true);
        restoreSelection();
        document.execCommand('createLink', false, url.trim());
        rememberSelection();
      }
    });
  });

  document.getElementById('cover-upload').addEventListener('click', () => {
    imageTarget = 'cover';
    picker.click();
  });
  picker.addEventListener('change', async () => {
    const file = picker.files?.[0];
    await uploadFile(file, imageTarget);
    picker.value = '';
  });
  editor.addEventListener('paste', event => {
    const file = [...(event.clipboardData?.files || [])].find(item => item.type.startsWith('image/'));
    if (file) {
      event.preventDefault();
      rememberSelection();
      uploadFile(file);
    }
  });
  cover.addEventListener('change', () => {
    if (/^(https?:\/\/|\/uploads\/)/.test(cover.value)) setCover(cover.value);
    else preview.replaceChildren();
  });
  form.addEventListener('submit', event => {
    hidden.value = editor.innerHTML;
    const text = editor.innerText.trim();
    if (!text) {
      event.preventDefault();
      messageText('请先填写教程正文。', true);
      editor.focus();
    }
  });
})();
