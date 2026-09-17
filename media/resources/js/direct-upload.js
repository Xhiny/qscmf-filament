/**
 * cmf-media 浏览器上传：
 * 选文件 → Worker 分片算 hash（带进度）→ check（命中即秒传）→ sign →
 * 直传（云驱动：XHR PUT/POST 至云存储；local 驱动：POST 至应用服务器 upload 端点，
 * 服务器建档后直接返回 media，无需 callback）→ callback 建档 → 回填 media id。
 *
 * 原生 JS，无框架依赖；状态通过 Livewire 写回 Filament 表单字段。
 */
(function () {
    'use strict';

    function initPicker(el) {
        if (el.dataset.cmfInitialized) {
            return;
        }
        el.dataset.cmfInitialized = '1';

        var statePath = el.dataset.statePath;
        var multiple = el.dataset.multiple === '1';
        var maxSize = parseInt(el.dataset.maxSize || '0', 10);
        var rule = el.dataset.rule || '';

        var input = el.querySelector('[data-cmf-media-input]');
        var progressWrap = el.querySelector('[data-cmf-media-progress-wrap]');
        var progressBar = el.querySelector('[data-cmf-media-progress-bar]');
        var progressLabel = el.querySelector('[data-cmf-media-progress-label]');
        var errorBox = el.querySelector('[data-cmf-media-error]');
        var itemsList = el.querySelector('[data-cmf-media-items]');
        var modal = el.querySelector('[data-cmf-media-modal]');
        var libraryBtn = el.querySelector('[data-cmf-media-library-btn]');
        var libraryList = el.querySelector('[data-cmf-media-library-list]');
        var previewModal = el.querySelector('[data-cmf-media-preview-modal]');
        var previewTitle = el.querySelector('[data-cmf-media-preview-title]');
        var previewBody = el.querySelector('[data-cmf-media-preview-body]');

        function setProgress(label, ratio) {
            progressWrap.classList.remove('hidden');
            progressLabel.textContent = label;
            progressBar.style.width = Math.round(ratio * 100) + '%';
        }

        function hideProgress() {
            progressWrap.classList.add('hidden');
            progressBar.style.width = '0%';
        }

        function showError(message) {
            errorBox.textContent = message;
            errorBox.classList.remove('hidden');
        }

        function clearError() {
            errorBox.textContent = '';
            errorBox.classList.add('hidden');
        }

        function livewireComponent() {
            var host = el.closest('[wire\\:id]');
            return host ? window.Livewire.find(host.getAttribute('wire:id')) : null;
        }

        function currentIds() {
            var lw = livewireComponent();
            var state = lw ? lw.get(statePath) : null;

            if (Array.isArray(state)) {
                return state.map(Number).filter(function (id) { return id > 0; });
            }

            var id = parseInt(state, 10);
            return id > 0 ? [id] : [];
        }

        function writeIds(ids) {
            var lw = livewireComponent();
            if (!lw) {
                return;
            }
            lw.set(statePath, multiple ? ids : (ids.length ? ids[ids.length - 1] : null));
        }

        function addItem(media) {
            if (!multiple) {
                itemsList.innerHTML = '';
            }
            if (itemsList.querySelector('[data-cmf-media-id="' + media.id + '"]')) {
                return;
            }

            var li = document.createElement('li');
            li.className = 'relative cursor-pointer rounded-lg border border-gray-200 p-2 text-xs dark:border-gray-700';
            li.dataset.cmfMediaId = media.id;
            li.dataset.cmfMediaUrl = media.url || '';
            li.dataset.cmfMediaMime = media.mime || '';
            li.dataset.cmfMediaName = media.original_name || '';

            if (media.thumb_url) {
                var img = document.createElement('img');
                img.src = media.thumb_url;
                img.alt = media.original_name;
                img.className = 'mb-1 h-16 w-full rounded object-cover';
                li.appendChild(img);
            }

            var name = document.createElement('div');
            name.className = 'truncate';
            name.title = media.original_name;
            name.textContent = media.original_name;
            li.appendChild(name);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'absolute right-1 top-1 rounded bg-white/80 px-1 text-gray-500 hover:text-danger-600 dark:bg-gray-800/80';
            remove.textContent = '×';
            remove.dataset.cmfMediaRemove = media.id;
            li.appendChild(remove);

            itemsList.appendChild(li);
        }

        function acceptMedia(media) {
            var ids = currentIds();
            if (ids.indexOf(Number(media.id)) === -1) {
                ids.push(Number(media.id));
            }
            writeIds(multiple ? ids : [Number(media.id)]);
            addItem(media);
        }

        function csrfToken() {
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        // 入口规则（cmf-media.rules）透传给服务端：check / sign / callback 全链路校验
        function withRule(payload) {
            if (rule) {
                payload.rule = rule;
            }
            return payload;
        }

        function postJson(url, payload) {
            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(payload),
            }).then(function (res) {
                if (!res.ok) {
                    return res.json().catch(function () { return {}; }).then(function (body) {
                        var first = body.errors ? Object.values(body.errors)[0] : null;
                        throw new Error((first && first[0]) || body.message || ('请求失败：' + res.status));
                    });
                }
                return res.json();
            });
        }

        function hashFile(file) {
            return new Promise(function (resolve, reject) {
                var worker = new Worker(el.dataset.workerUrl);
                worker.postMessage({ file: file, sparkUrl: el.dataset.sparkUrl });
                worker.onmessage = function (event) {
                    if (event.data.error) {
                        worker.terminate();
                        reject(new Error('计算文件指纹失败：' + event.data.error));
                        return;
                    }
                    if (typeof event.data.progress === 'number') {
                        setProgress('正在校验文件指纹…', event.data.progress);
                        return;
                    }
                    if (event.data.hash) {
                        worker.terminate();
                        resolve(event.data.hash);
                    }
                };
                worker.onerror = function (e) {
                    worker.terminate();
                    reject(new Error('计算文件指纹失败：' + (e.message || 'Worker 异常')));
                };
            });
        }

        function directUpload(file, credential) {
            return new Promise(function (resolve, reject) {
                var xhr = new XMLHttpRequest();
                xhr.open(credential.method, credential.upload_url, true);

                Object.keys(credential.headers || {}).forEach(function (name) {
                    xhr.setRequestHeader(name, credential.headers[name]);
                });

                xhr.upload.onprogress = function (event) {
                    if (event.lengthComputable) {
                        setProgress('正在上传…', event.loaded / event.total);
                    }
                };

                xhr.onload = function () {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        var body = null;
                        try { body = JSON.parse(xhr.responseText); } catch (e) { /* 云存储响应为空 */ }
                        resolve(body || {});
                    } else {
                        var message = '上传失败：HTTP ' + xhr.status;
                        try {
                            var errBody = JSON.parse(xhr.responseText);
                            var first = errBody.errors ? Object.values(errBody.errors)[0] : null;
                            message = (first && first[0]) || errBody.message || message;
                        } catch (e) { /* 非 JSON 响应 */ }
                        reject(new Error(message));
                    }
                };
                xhr.onerror = function () { reject(new Error('上传失败：网络错误')); };

                if (credential.method === 'POST') {
                    var form = new FormData();
                    Object.keys(credential.fields || {}).forEach(function (name) {
                        form.append(name, credential.fields[name]);
                    });
                    form.append('file', file);
                    xhr.send(form);
                } else {
                    xhr.send(file);
                }
            });
        }

        function handleFile(file) {
            clearError();

            if (maxSize && file.size > maxSize) {
                showError('文件超过大小上限 ' + Math.round(maxSize / 1024 / 1024) + 'MB');
                return;
            }

            var hash;
            var path;
            var isLocal = false;
            hashFile(file)
                .then(function (h) {
                    hash = h;
                    return postJson(el.dataset.checkUrl, withRule({ hash: hash, size: file.size, mime: file.type || 'application/octet-stream' }))
                        .then(function (res) { return { hit: true, media: res.media }; })
                        .catch(function (err) {
                            if (/未命中/.test(err.message)) {
                                return { hit: false };
                            }
                            throw err;
                        });
                })
                .then(function (result) {
                    if (result.hit) {
                        hideProgress();
                        acceptMedia(result.media);
                        return;
                    }

                    return postJson(el.dataset.signUrl, withRule({
                        hash: hash,
                        name: file.name,
                        mime: file.type || 'application/octet-stream',
                        size: file.size,
                    }))
                        .then(function (credential) {
                            path = credential.path;
                            isLocal = credential.local === true;
                            return directUpload(file, credential);
                        })
                        .then(function (uploadRes) {
                            // local 驱动：upload 端点已直接建档并返回 media，无需 callback
                            if (isLocal) {
                                hideProgress();
                                acceptMedia(uploadRes.media);
                                return;
                            }

                            setProgress('正在登记…', 1);
                            return postJson(el.dataset.callbackUrl, withRule({
                                hash: hash,
                                path: path,
                                name: file.name,
                                mime: file.type || 'application/octet-stream',
                                size: file.size,
                            }))
                                .then(function (res) {
                                    hideProgress();
                                    acceptMedia(res.media);
                                });
                        });
                })
                .catch(function (err) {
                    hideProgress();
                    showError(err.message || '上传失败');
                });
        }

        input.addEventListener('change', function () {
            Array.prototype.forEach.call(input.files, handleFile);
            input.value = '';
        });

        /**
         * 预览/播放/下载弹窗：按 mime 分发——图片大图预览、视频音频内嵌播放、
         * 其他类型给出新窗口下载链接（是否落盘由对象 Content-Disposition 元数据决定）。
         */
        function openPreview(url, mime, name) {
            if (!url) {
                return;
            }
            previewTitle.textContent = name || '';
            previewBody.innerHTML = '';

            if (mime.indexOf('image/') === 0) {
                var img = document.createElement('img');
                img.src = url;
                img.alt = name || '';
                img.className = 'mx-auto max-h-[70vh] rounded';
                previewBody.appendChild(img);
            } else if (mime.indexOf('video/') === 0) {
                var video = document.createElement('video');
                video.src = url;
                video.controls = true;
                video.autoplay = true;
                video.className = 'mx-auto max-h-[70vh] w-full rounded';
                previewBody.appendChild(video);
            } else if (mime.indexOf('audio/') === 0) {
                var audio = document.createElement('audio');
                audio.src = url;
                audio.controls = true;
                audio.autoplay = true;
                audio.className = 'w-full';
                previewBody.appendChild(audio);
            } else {
                var link = document.createElement('a');
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.className = 'fi-btn fi-btn-color-gray fi-btn-size-sm inline-flex items-center rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600';
                link.textContent = '下载 ' + (name || '文件');
                previewBody.appendChild(link);
            }

            previewModal.classList.remove('hidden');
            previewModal.classList.add('flex');
        }

        function closePreview() {
            // 清空 body 以停止音视频播放
            previewBody.innerHTML = '';
            previewModal.classList.add('hidden');
            previewModal.classList.remove('flex');
        }

        previewModal.querySelector('[data-cmf-media-preview-close]').addEventListener('click', closePreview);
        previewModal.addEventListener('click', function (event) {
            if (event.target === previewModal) {
                closePreview();
            }
        });

        itemsList.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-cmf-media-remove]');
            if (btn) {
                var id = Number(btn.dataset.cmfMediaRemove);
                writeIds(currentIds().filter(function (x) { return x !== id; }));
                var li = itemsList.querySelector('[data-cmf-media-id="' + id + '"]');
                if (li) {
                    li.remove();
                }
                return;
            }

            var item = event.target.closest('[data-cmf-media-id]');
            if (item) {
                openPreview(item.dataset.cmfMediaUrl, item.dataset.cmfMediaMime || '', item.dataset.cmfMediaName || '');
            }
        });

        // 「从媒体库选择」入口默认不渲染（cmf-media.picker_library 开关），
        // 按钮缺失时跳过整段媒体库弹窗接线，避免 null 引用中断 picker 初始化
        if (libraryBtn) {
            libraryBtn.addEventListener('click', function () {
                modal.classList.remove('hidden');
                modal.classList.add('flex');

                fetch(el.dataset.libraryUrl, { headers: { 'Accept': 'application/json' } })
                    .then(function (res) { return res.json(); })
                    .then(function (res) {
                        libraryList.innerHTML = '';
                        (res.data || []).forEach(function (media) {
                            var li = document.createElement('li');
                            li.className = 'cursor-pointer rounded-lg border border-gray-200 p-2 text-xs hover:border-primary-500 dark:border-gray-700';
                            if (media.thumb_url) {
                                var img = document.createElement('img');
                                img.src = media.thumb_url;
                                img.alt = media.original_name;
                                img.className = 'mb-1 h-16 w-full rounded object-cover';
                                li.appendChild(img);
                            }
                            var name = document.createElement('div');
                            name.className = 'truncate';
                            name.title = media.original_name;
                            name.textContent = media.original_name;
                            li.appendChild(name);
                            li.addEventListener('click', function () {
                                acceptMedia(media);
                                modal.classList.add('hidden');
                                modal.classList.remove('flex');
                            });
                            libraryList.appendChild(li);
                        });
                    });
            });

            modal.querySelector('[data-cmf-media-modal-close]').addEventListener('click', function () {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            });
        }
    }

    function boot() {
        document.querySelectorAll('[data-cmf-media-picker]').forEach(initPicker);
    }

    /**
     * 后挂载 picker 的初始化入口，由视图的 x-init 调用。
     *
     * 弹窗（Filament Action / Modal）内容由 Alpine 挂载：既不触发 Livewire 的
     * morph.added，也不在首屏 boot() 的扫描范围内，而 Alpine 的 x-init 是弹窗
     * 内容挂载时必然会执行的钩子，故用它声明式触发，不再全局监听 DOM
     * （全局 MutationObserver 会持续跟随整棵树的开销与副作用）。
     * initPicker 自带 data-cmf-initialized 幂等保护，重复调用安全。
     */
    window.cmfMediaPickerInit = function (el) {
        if (el) {
            initPicker(el);
        }
    };

    document.addEventListener('DOMContentLoaded', boot);
    document.addEventListener('livewire:navigated', boot);
    boot();
})();
