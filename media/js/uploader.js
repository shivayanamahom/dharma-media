class DharmaMediaUpload extends HTMLElement {
  connectedCallback() {
    const picker = this.querySelector('joomla-field-media');
    const input = picker?.querySelector('.field-media-input');
    if (!input) return;

    this.listeners = new AbortController();
    this.drop = this.querySelector('.dharma-media__drop');
    this.file = this.querySelector('.dharma-media__file');
    this.choose = this.querySelector('.dharma-media__choose');
    this.progress = this.querySelector('.dharma-media__progress');
    this.status = this.querySelector('.dharma-media__status');
    this.picker = picker;
    this.input = input;
    this.server = picker.querySelector('.button-select');
    if (this.server) this.server.textContent = this.dataset.serverLabel;

    const options = { signal: this.listeners.signal };
    const sync = () => {
      this.toggleAttribute('data-filled', Boolean(input.value));
      input.title = input.value.split('#', 1)[0];
    };
    sync();
    input.addEventListener('input', sync, options);
    input.addEventListener('change', sync, options);
    this.picker.addEventListener('change', sync, options);

    this.choose.addEventListener('click', () => this.file.click(), options);
    this.file.addEventListener('change', () => {
      const [file] = this.file.files;
      this.file.value = '';
      if (file) this.upload(file);
    }, options);

    this.drop.addEventListener('keydown', (event) => {
      if (event.target === this.drop && ['Enter', ' '].includes(event.key)) {
        event.preventDefault();
        if (!this.request) this.file.click();
      }
    }, options);

    this.drop.addEventListener('dragover', (event) => {
      if (!Array.from(event.dataTransfer.types).includes('Files')) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = this.request ? 'none' : 'copy';
      this.drop.toggleAttribute('data-dragging', !this.request);
    }, options);
    this.drop.addEventListener('dragleave', (event) => {
      if (!this.drop.contains(event.relatedTarget)) this.drop.removeAttribute('data-dragging');
    }, options);
    this.drop.addEventListener('drop', (event) => {
      this.drop.removeAttribute('data-dragging');
      const [file] = event.dataTransfer.files;
      if (!file) return;
      event.preventDefault();
      this.upload(file);
    }, options);

    this.addEventListener('paste', (event) => {
      const file = Array.from(event.clipboardData?.files ?? []).find((item) => item.type.startsWith('image/'));
      if (!file) return;
      event.preventDefault();
      this.upload(file);
    }, options);

    input.form?.addEventListener('submit', (event) => {
      if (this.request && !input.form.elements.namedItem('task')?.value?.endsWith('.cancel')) {
        event.preventDefault();
        this.announce(this.dataset.pending);
      }
    }, options);
  }

  disconnectedCallback() {
    this.listeners?.abort();
    this.request?.abort();
  }

  announce(message, error = false) {
    this.status.textContent = message;
    this.status.toggleAttribute('data-error', error);
  }

  setBusy(busy) {
    this.toggleAttribute('data-busy', busy);
    this.progress.hidden = !busy;
    this.choose.disabled = busy;
    if (this.server) this.server.disabled = busy;
    this.input.readOnly = busy;
    const clear = this.picker.querySelector('.button-clear');
    if (clear) clear.disabled = busy;
  }

  upload(file) {
    if (this.request) return;
    if (file.size > Number(this.dataset.maxBytes)) {
      this.announce(this.dataset.sizeError, true);
      return;
    }

    const data = new FormData();
    data.set('image', file);
    data.set(this.dataset.token, '1');
    ['context', 'field', 'author', 'signature'].forEach((key) => data.set(key, this.dataset[key]));

    const request = new XMLHttpRequest();
    this.request = request;
    this.setBusy(true);
    this.announce(this.dataset.pending);
    this.progress.removeAttribute('value');

    request.open('POST', this.dataset.url);
    request.responseType = 'json';
    request.timeout = 120000;
    request.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable && event.loaded < event.total) {
        this.progress.value = (event.loaded / event.total) * 100;
      } else {
        this.progress.removeAttribute('value');
      }
    });
    request.addEventListener('load', () => {
      const response = request.response;
      const result = response?.data?.[0];
      if (request.status === 200 && response?.success && result?.value) {
        const mediaUrl = new URL(this.picker.url, document.baseURI);
        if (mediaUrl.origin === location.origin && mediaUrl.searchParams.get('option') === 'com_media') {
          mediaUrl.searchParams.set('path', result.folder);
          this.picker.url = mediaUrl.href;
        }
        this.picker.setValue(result.value);
        this.picker.markValid();
        this.announce(`${this.dataset.uploaded} ${result.name} · ${result.width} × ${result.height}`);
      } else {
        this.announce(response?.message || this.dataset.uploadError, true);
      }
    });
    request.addEventListener('error', () => this.announce(this.dataset.uploadError, true));
    request.addEventListener('timeout', () => this.announce(this.dataset.uploadError, true));
    request.addEventListener('loadend', () => {
      this.request = null;
      this.setBusy(false);
    });
    request.send(data);
  }
}

if (!customElements.get('dharma-media-upload')) {
  customElements.define('dharma-media-upload', DharmaMediaUpload);
}
