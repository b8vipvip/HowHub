(() => {
  document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
      if (!window.confirm(form.dataset.confirm || '确认执行操作？')) event.preventDefault();
    });
  });

  document.querySelectorAll('button[data-copy]').forEach(button => {
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(button.dataset.copy);
        button.textContent = '已复制';
      } catch {
        window.prompt('复制素材地址：', button.dataset.copy);
      }
    });
  });

  async function uploadAsset(file, token) {
    if (!file) throw new Error('请选择文件');
    const data = new FormData();
    data.append('csrf_token', token);
    data.append('asset', file);
    const response = await fetch('/admin/upload', { method: 'POST', body: data, credentials: 'same-origin' });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || '上传失败');
    return result.asset;
  }

  const uploadInput = document.getElementById('media-upload-input');
  if (uploadInput) {
    const token = document.querySelector('input[name="csrf_token"]')?.value;
    const status = document.getElementById('media-status');
    uploadInput.addEventListener('change', async () => {
      if (!token) return;
      const files = [...uploadInput.files];
      let saved = 0;
      const errors = [];
      for (const file of files) {
        status.textContent = `正在上传 ${saved + errors.length + 1}/${files.length}：${file.name}`;
        try { await uploadAsset(file, token); saved++; } catch (err) { errors.push(`${file.name}: ${err.message}`); }
      }
      status.textContent = `成功 ${saved} 个` + (errors.length ? `；失败 ${errors.length} 个：${errors.join('；')}` : '，正在刷新列表');
      if (saved) window.location.reload();
      uploadInput.value = '';
    });
  }

  const editor = document.getElementById('rich-editor');
  if (!editor) return;

  const form = document.getElementById('guide-form');
  const hidden = document.getElementById('body-html');
  const picker = document.getElementById('image-picker');
  const message = document.getElementById('editor-message');
  const token = form.querySelector('[name="csrf_token"]').value;
  const cover = document.getElementById('cover-image');
  const preview = document.getElementById('cover-preview');
  const dialog = document.getElementById('media-dialog');
  const dialogList = document.getElementById('media-dialog-list');
  let imageTarget = 'body';
  let libraryTarget = 'body';
  let selectedImage = null;
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
    try {
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(lastSelection);
    } catch { lastSelection = null; }
  }
  editor.addEventListener('mouseup', rememberSelection);
  editor.addEventListener('keyup', rememberSelection);
  editor.addEventListener('input', rememberSelection);
  editor.addEventListener('click', event => {
    const img = event.target.closest?.('img');
    if (img && editor.contains(img)) selectImage(img);
    else selectImage(null);
    rememberSelection();
  });
  function selectImage(img) {
    if (selectedImage) selectedImage.classList.remove('editor-image-selected');
    selectedImage = img && editor.contains(img) ? img : null;
    if (selectedImage) selectedImage.classList.add('editor-image-selected');
  }
  function imageFigure() {
    return selectedImage?.closest('figure') || selectedImage;
  }
  function replaceSelected(src) {
    if (selectedImage?.isConnected) {
      selectedImage.src = src;
      messageText('已替换选中的图片，请记得保存教程。');
      return true;
    }
    return false;
  }
  function insertImage(src, name = '教程配图') {
    restoreSelection();
    const img = document.createElement('img');
    img.src = src;
    img.alt = name;
    img.loading = 'lazy';
    const figure = document.createElement('figure');
    figure.append(img);
    insertNode(figure);
    selectImage(img);
  }
  function insertNode(node) {
    restoreSelection();
    const paragraph = document.createElement('p');
    paragraph.append(document.createElement('br'));
    const selection = window.getSelection();
    if (selection.rangeCount && editor.contains(selection.anchorNode)) {
      const range = selection.getRangeAt(0);
      range.deleteContents();
      range.insertNode(paragraph);
      range.insertNode(node);
      range.setStart(paragraph, 0);
      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
    } else {
      editor.append(node, paragraph);
    }
    rememberSelection();
  }
  function insertFile(url, name) {
    const paragraph = document.createElement('p');
    paragraph.append('附件：');
    const a = document.createElement('a');
    a.href = url;
    a.textContent = name;
    a.rel = 'noopener noreferrer';
    a.target = '_blank';
    paragraph.append(a);
    insertNode(paragraph);
    messageText('文档链接已插入，请记得保存教程。');
  }
  function setCover(src) {
    cover.value = src;
    preview.replaceChildren();
    if (!src) return;
    const img = document.createElement('img');
    img.src = src;
    img.alt = '封面预览';
    preview.append(img);
  }
  async function uploadFile(file, destination = 'body') {
    if (!file) return;
    messageText('正在上传文件…');
    try {
      const asset = await uploadAsset(file, token);
      if (destination === 'cover') {
        if (asset.kind !== 'image') throw new Error('封面必须使用图片');
        setCover(asset.url);
      } else if (destination === 'replace') {
        if (asset.kind !== 'image') throw new Error('请选择图片');
        if (!replaceSelected(asset.url)) insertImage(asset.url, asset.original_name);
      } else if (asset.kind === 'image') insertImage(asset.url, asset.original_name);
      else insertFile(asset.url, asset.original_name);
      messageText('上传成功；请保存教程以应用内容修改。');
    } catch (error) {
      messageText(error.message || '上传失败，请重试。', true);
    }
  }

  async function showLibrary(target = 'body', kind = 'all') {
    libraryTarget = target;
    if (target === 'cover' || target === 'replace') kind = 'image';
    dialog.showModal();
    await loadLibrary(kind);
  }
  async function loadLibrary(kind = 'all') {
    dialogList.textContent = '正在读取素材…';
    document.querySelectorAll('[data-media-kind]').forEach(el => {
      el.classList.toggle('active', el.dataset.mediaKind === kind);
      el.disabled = libraryTarget !== 'body' && el.dataset.mediaKind === 'file';
    });
    try {
      const response = await fetch('/admin/media/list?kind=' + encodeURIComponent(kind), { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error('素材列表读取失败');
      const data = await response.json();
      dialogList.replaceChildren();
      if (!data.assets.length) { dialogList.textContent = '暂无素材，可使用上方上传按钮添加。'; return; }
      data.assets.forEach(asset => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'media-pick-card';
        if (asset.kind === 'image') {
          const img = document.createElement('img');
          img.src = asset.url;
          img.alt = asset.original_name;
          item.append(img);
        } else {
          const icon = document.createElement('span');
          icon.className = 'media-file-icon';
          icon.textContent = '📄';
          item.append(icon);
        }
        const label = document.createElement('span');
        label.textContent = asset.original_name;
        label.title = asset.original_name;
        item.append(label);
        item.addEventListener('click', () => {
          if (libraryTarget === 'cover') {
            if (asset.kind !== 'image') return;
            setCover(asset.url);
          } else if (libraryTarget === 'replace') {
            if (asset.kind !== 'image') return;
            if (!replaceSelected(asset.url)) messageText('请先点击正文中的图片，再尝试替换。', true);
          } else if (asset.kind === 'image') insertImage(asset.url, asset.original_name);
          else insertFile(asset.url, asset.original_name);
          dialog.close();
          messageText('已选择素材，请保存教程。');
        });
        dialogList.append(item);
      });
    } catch (err) { dialogList.textContent = err.message; }
  }

  document.querySelectorAll('[data-media-kind]').forEach(button => {
    button.addEventListener('click', () => {
      if (button.dataset.mediaKind === 'file' && libraryTarget !== 'body') return;
      loadLibrary(button.dataset.mediaKind);
    });
  });
  document.getElementById('media-dialog-close').addEventListener('click', () => dialog.close());
  const dialogUpload = document.getElementById('dialog-upload');
  dialogUpload.addEventListener('change', async () => {
    const files = [...dialogUpload.files];
    let success = 0;
    for (const file of files) {
      try {
        const asset = await uploadAsset(file, token);
        if (libraryTarget !== 'body' && asset.kind !== 'image') throw new Error('这里只能选择图片');
        success++;
      } catch (err) { messageText(`${file.name}：${err.message}`, true); }
    }
    dialogUpload.value = '';
    if (success) await loadLibrary(libraryTarget !== 'body' ? 'image' : 'all');
  });

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
      } else if (action === 'library') {
        showLibrary('body');
      } else if (action === 'replace-image') {
        if (!selectedImage) return messageText('请先点击正文里需要替换的图片。', true);
        showLibrary('replace');
      } else if (action === 'remove-image') {
        if (!selectedImage) return messageText('请先点击图片。', true);
        imageFigure().remove();
        selectImage(null);
        messageText('已从正文删除图片，请保存教程。素材库中的文件仍会保留。');
      } else if (action === 'image-alt') {
        if (!selectedImage) return messageText('请先点击图片。', true);
        const text = window.prompt('图片描述（供读屏软件和图片无法加载时显示）', selectedImage.alt);
        if (text !== null) selectedImage.alt = text.slice(0, 200);
      } else if (action === 'image-size') {
        if (!selectedImage) return messageText('请先点击图片。', true);
        const sizes = ['', 'guide-img-medium', 'guide-img-small'];
        const current = selectedImage.classList.contains('guide-img-small') ? 2 : selectedImage.classList.contains('guide-img-medium') ? 1 : 0;
        const next = (current + 1) % sizes.length;
        selectedImage.classList.remove(...sizes.filter(Boolean));
        if (sizes[next]) selectedImage.classList.add(sizes[next]);
        messageText(['全宽', '中等 70%', '小图 40%'][next] + '，保存教程后生效。');
      } else if (action === 'image-up' || action === 'image-down') {
        if (!selectedImage) return messageText('请先点击图片。', true);
        const block = imageFigure();
        if (action === 'image-up' && block.previousElementSibling) block.parentElement.insertBefore(block, block.previousElementSibling);
        if (action === 'image-down' && block.nextElementSibling) block.parentElement.insertBefore(block.nextElementSibling, block);
        messageText('已调整图片顺序，请保存教程。');
      } else if (action === 'remote-image') {
        const url = window.prompt('输入图片网址（外链可能被防盗链限制，建议上传本地图片）：');
        if (url === null) return;
        if (!/^https?:\/\//i.test(url.trim())) return messageText('请输入有效 http(s) 图片网址。', true);
        insertImage(url.trim());
      } else if (action === 'link') {
        const url = window.prompt('输入链接（https://...）：');
        if (url === null) return;
        if (!/^https?:\/\//i.test(url.trim())) return messageText('请输入有效 http(s) 链接。', true);
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
  document.getElementById('cover-library').addEventListener('click', () => showLibrary('cover', 'image'));
  document.getElementById('cover-clear').addEventListener('click', () => setCover(''));
  picker.addEventListener('change', async () => {
    await uploadFile(picker.files?.[0], imageTarget);
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
    // Editor-only selection decorations must never be persisted to the published article.
    if (selectedImage) selectedImage.classList.remove('editor-image-selected');
    hidden.value = editor.innerHTML;
    const text = editor.innerText.trim();
    if (!text) {
      event.preventDefault();
      messageText('请先填写教程正文。', true);
      editor.focus();
    }
  });
})();
