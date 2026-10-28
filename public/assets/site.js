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
  const sourceEditor = document.getElementById('source-editor');
  const sourceHelp = document.getElementById('source-editor-help');
  const sourceToggle = document.getElementById('editor-toggle-source');
  const previewButton = document.getElementById('editor-preview-button');
  const previewDialog = document.getElementById('editor-preview-dialog');
  const previewFrame = document.getElementById('editor-preview-frame');
  const optimizeButton = document.getElementById('editor-ai-optimize');
  const undoOptimizeButton = document.getElementById('editor-ai-undo');
  let sourceMode = false;
  let previousAIHtml = null;
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
  function currentHtml() {
    if (sourceMode) return sourceEditor.value;
    // Strip editing-only selection outline without changing the live editor DOM.
    const clone = editor.cloneNode(true);
    clone.querySelectorAll('.editor-image-selected').forEach(img => img.classList.remove('editor-image-selected'));
    return clone.innerHTML;
  }
  async function cleanHtml(html) {
    const data = new FormData();
    data.append('csrf_token', token);
    data.append('html', html);
    const response = await fetch('/admin/import/clean', { method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store' });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'HTML 过滤失败');
    return result.html;
  }
  function setSourceMode(active) {
    sourceMode = active;
    editor.hidden = active;
    sourceEditor.hidden = !active;
    sourceHelp.hidden = !active;
    sourceToggle.setAttribute('aria-pressed', String(active));
    sourceToggle.textContent = active ? '返回可视化编辑' : '</> HTML 源代码';
    document.querySelectorAll('[data-command], [data-action]').forEach(button => { button.disabled = active; });
  }
  sourceToggle.addEventListener('click', async () => {
    if (!sourceMode) {
      sourceEditor.value = currentHtml();
      setSourceMode(true);
      sourceEditor.focus();
      messageText('已进入 HTML 源码模式。可直接编辑代码，保存时由服务端过滤危险标签。');
      return;
    }
    sourceToggle.disabled = true;
    try {
      const clean = await cleanHtml(sourceEditor.value);
      editor.innerHTML = clean;
      selectImage(null);
      setSourceMode(false);
      messageText('已切换回可视化编辑，危险脚本和不支持的 CSS 已被清理。');
    } catch (err) {
      messageText('HTML 校验失败：' + (err.message || '未知错误') + '；源码仍保留。', true);
    } finally {
      sourceToggle.disabled = false;
    }
  });
  previewButton.addEventListener('click', async () => {
    previewButton.disabled = true;
    messageText('正在生成当前未保存正文的安全预览…');
    try {
      const clean = await cleanHtml(currentHtml());
      // No same-origin access, scripts, forms, or active content in preview.
      previewFrame.srcdoc = '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/style.css"><style>body{background:#fff;padding:25px;margin:0}.article-content{max-width:850px;margin:auto}@media(max-width:600px){body{padding:15px}}</style></head><body><main class="reader-article"><article class="article-content">' + clean + '</article></main></body></html>';
      previewDialog.showModal();
      messageText('预览的是编辑器当前内容，没有保存或发布。');
    } catch (err) {
      messageText('预览失败：' + (err.message || '未知错误'), true);
    } finally { previewButton.disabled = false; }
  });
  document.getElementById('editor-preview-close').addEventListener('click', () => previewDialog.close());
  previewDialog.addEventListener('close', () => { previewFrame.removeAttribute('srcdoc'); });
  optimizeButton.addEventListener('click', async () => {
    const original = currentHtml();
    if (!original.trim()) { messageText('请输入正文后再进行 AI 优化。', true); return; }
    if (!window.confirm('将当前未保存的正文发送给已配置的 AI 供应商进行润色与排版。返回的 HTML 会填入源码模式，但不会自动保存或发布。确定继续？')) return;
    const formData = new FormData();
    formData.append('csrf_token', token);
    formData.append('html', original);
    formData.append('title', form.querySelector('input[name="title"]')?.value || '');
    optimizeButton.disabled = true;
    messageText('AI 正在优化文案、配色、版式和图片尺寸。当前文章不会被自动保存…');
    try {
      const response = await fetch('/admin/guides/optimize', { method: 'POST', body: formData, credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'AI 优化失败');
      if (currentHtml() !== original) throw new Error('AI 运行期间正文已经变化，为避免覆盖新内容，未应用优化；请重新操作');
      previousAIHtml = original;
      sourceEditor.value = data.html;
      setSourceMode(true);
      undoOptimizeButton.hidden = false;
      sourceEditor.focus();
      messageText(`AI 优化 HTML 已填入源码编辑器，已保留 ${data.image_count} 张原图。请预览后再保存；可以撤销本次优化。`);
    } catch (err) {
      messageText('AI 优化未应用：' + (err.message || '网络错误') + '；原有正文保留。', true);
    } finally { optimizeButton.disabled = false; }
  });
  undoOptimizeButton.addEventListener('click', () => {
    if (previousAIHtml === null) return;
    sourceEditor.value = previousAIHtml;
    setSourceMode(true);
    previousAIHtml = null;
    undoOptimizeButton.hidden = true;
    messageText('已恢复本次 AI 优化前的 HTML，请记得保存或继续编辑。');
  });
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
  // Other webpages put both text/html and text/plain into the clipboard. Clean rich HTML
  // on the server before inserting it; never allow foreign scripts/styles/event handlers.
  editor.addEventListener('paste', async event => {
    const file = [...(event.clipboardData?.files || [])].find(item => item.type.startsWith('image/'));
    if (file) {
      event.preventDefault();
      rememberSelection();
      await uploadFile(file);
      return;
    }
    const html = event.clipboardData?.getData('text/html') || '';
    if (!html) return; // Browser keeps its native plain-text paste behaviour.
    event.preventDefault();
    if (html.length > 300000) return messageText('复制内容超过 300KB，请分段粘贴。', true);
    rememberSelection();
    messageText('正在整理复制的网页图文…');
    const data = new FormData();
    data.append('csrf_token', token);
    data.append('html', html);
    try {
      const response = await fetch('/admin/import/clean', { method: 'POST', credentials: 'same-origin', body: data });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || '解析失败');
      const template = document.createElement('template');
      template.innerHTML = result.html;
      insertNode(template.content);
      selectImage(null);
      const images = editor.querySelectorAll('img').length;
      messageText(`网页图文已粘贴（正文现有 ${images} 张图片），请保存文章。若外链图片失效，可从素材库替换。`);
    } catch (error) {
      messageText('网页粘贴处理失败：' + error.message + '。可使用上方 AI 导入网页代码。', true);
    }
  });

  const importButton = document.getElementById('ai-import-generate');
  const importInput = document.getElementById('ai-source-html');
  const importStatus = document.getElementById('ai-import-status');
  if (importButton && importInput && importStatus) {
    importButton.addEventListener('click', async () => {
      const source = importInput.value.trim();
      if (!source) { importStatus.textContent = '请先粘贴网页代码或文章内容。'; importInput.focus(); return; }
      if (new Blob([source]).size > 300000) { importStatus.textContent = '内容不能超过 300KB。'; return; }
      if (form.querySelector('[name="id"]')?.value !== '0' &&
          !window.confirm('AI 将创建一篇新的草稿，不修改当前正在编辑的文章。确定继续？')) return;
      const data = new FormData();
      data.append('csrf_token', token);
      data.append('html', source);
      importButton.disabled = true;
      importStatus.textContent = 'AI 正在提取步骤、图片顺序和标题，生成后会创建草稿…';
      try {
        const response = await fetch('/admin/import/generate', { method: 'POST', body: data, credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'AI 未能创建草稿');
        importStatus.textContent = `已创建草稿，保留 ${result.image_count} 张原图，正在打开编辑器…`;
        window.location.assign(result.edit_url);
      } catch (err) {
        importStatus.textContent = 'AI 导入失败：' + (err.message || '网络错误') + '（原始输入仍保留，可重试）';
      } finally {
        importButton.disabled = false;
      }
    });
  }
  cover.addEventListener('change', () => {
    if (/^(https?:\/\/|\/uploads\/)/.test(cover.value)) setCover(cover.value);
    else preview.replaceChildren();
  });
  form.addEventListener('submit', event => {
    // Editor-only selection decorations must never be persisted to the published article.
    if (selectedImage) selectedImage.classList.remove('editor-image-selected');
    hidden.value = currentHtml();
    // In source mode the rich-text editor is hidden; do not accidentally submit stale HTML.
    const text = new DOMParser().parseFromString(hidden.value, 'text/html').body.textContent.trim();
    if (!text) {
      event.preventDefault();
      messageText('请先填写教程正文。', true);
      if (sourceMode) sourceEditor.focus(); else editor.focus();
    }
  });
})();
