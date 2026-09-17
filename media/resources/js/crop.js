/**
 * 媒体字段的本地裁剪。
 *
 * 字段声明了比例时，选中的图片先在本层裁好再交给上传链路。裁剪发生在计算内容
 * 指纹之前，因此秒传去重基于裁剪后的内容，上传的字节也与登记的 hash 一致
 * （callback 会拿云端对象 ETag 与客户端上报的 hash 比对）。
 *
 * 裁剪是体验增强而非强制：服务端不校验比例，所以任何异常都退回原文件、不阻断
 * 上传。GIF 裁完会丢动画、SVG 是矢量取不到像素，这两类直接放行。
 */
(function () {
    'use strict';

    var LAYER_CLASS = 'cmf-media-crop';

    /**
     * 裁剪产物是 Blob，没有 name；包成 File 让上传链路照常读 name / type / size。
     */
    function asFile(blob, name, mime) {
        if (typeof File === 'function') {
            return new File([blob], name, { type: mime });
        }

        blob.name = name;
        blob.type = mime;

        return blob;
    }

    /**
     * PNG 保持 PNG 以留住透明通道，其余统一转 JPEG。
     */
    function outputMime(file) {
        return /^image\/png$/i.test(file.type || '') ? 'image/png' : 'image/jpeg';
    }

    function renameFor(name, mime) {
        var base = (name || 'image').replace(/\.[^./\\]*$/, '');

        return base + (mime === 'image/png' ? '.png' : '.jpg');
    }

    function isCropSkipped(file) {
        return /^image\/(gif|svg\+xml)$/i.test(file.type || '');
    }

    /**
     * 输出像素宽超过上限时等比缩小，避免把手机原图的几千万像素直接传上去。
     */
    function scaleToMaxWidth(canvas, maxWidth) {
        if (!maxWidth || canvas.width <= maxWidth) {
            return canvas;
        }

        var scaled = document.createElement('canvas');
        scaled.width = maxWidth;
        scaled.height = Math.round(maxWidth * canvas.height / canvas.width);
        scaled.getContext('2d').drawImage(canvas, 0, 0, scaled.width, scaled.height);

        return scaled;
    }

    function renderCanvas(cropper, mime, maxWidth) {
        var canvas = cropper.getCroppedCanvas({
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high',
            // JPEG 没有透明通道，不铺底色透明区域会变黑
            fillColor: mime === 'image/jpeg' ? '#ffffff' : 'transparent',
        });

        return canvas ? scaleToMaxWidth(canvas, maxWidth) : null;
    }

    function canvasToBlob(canvas, mime, quality) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) {
                    resolve(blob);
                    return;
                }

                reject(new Error('导出裁剪结果失败'));
            }, mime, quality);
        });
    }

    function createLayer() {
        var layer = document.createElement('div');
        layer.className = LAYER_CLASS;
        layer.innerHTML = [
            '<div class="' + LAYER_CLASS + '__panel">',
            '    <p class="' + LAYER_CLASS + '__hint">拖动或缩放图片，调整裁剪范围</p>',
            '    <div class="' + LAYER_CLASS + '__stage"><img alt="" /></div>',
            '    <div class="' + LAYER_CLASS + '__actions">',
            '        <button type="button" data-cmf-crop-reset>还原</button>',
            '        <button type="button" data-cmf-crop-cancel>取消</button>',
            '        <button type="button" data-cmf-crop-confirm>确定</button>',
            '    </div>',
            '</div>',
        ].join('\n');

        return layer;
    }

    /**
     * 打开裁剪层，返回裁剪后的 File；用户在层里取消时返回 null（该文件不上传）。
     *
     * @returns {Promise<File|null>}
     */
    window.cmfMediaCropFile = function (file, options) {
        options = options || {};

        var aspectRatio = Number(options.aspectRatio);

        if (!window.Cropper || !file || isCropSkipped(file) || !(aspectRatio > 0)) {
            return Promise.resolve(file);
        }

        return new Promise(function (resolve) {
            var layer = createLayer();
            var image = layer.querySelector('img');
            var objectUrl = URL.createObjectURL(file);
            var cropper = null;
            var settled = false;
            var exporting = false;

            function cleanup() {
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }

                URL.revokeObjectURL(objectUrl);
                document.removeEventListener('keydown', onKeydown);
                document.body.classList.remove(LAYER_CLASS + '-open');
                layer.remove();
            }

            function finish(result) {
                if (settled) {
                    return;
                }

                settled = true;
                cleanup();
                resolve(result);
            }

            /**
             * 退回原文件：裁剪失败不该让人传不上东西。
             */
            function skip(reason) {
                if (reason) {
                    window.console.warn('[cmf-media] 跳过裁剪：' + reason);
                }

                finish(file);
            }

            function onKeydown(event) {
                if (event.key !== 'Escape') {
                    return;
                }

                // 裁剪层不是 Filament 注册的弹窗，它在 window 上监听 Escape 关弹窗
                // （document → window 冒泡），这里不拦住，用户在表单弹窗里按 Esc
                // 取消裁剪会连底层表单一起关掉。导出中同样拦，只是不关裁剪层。
                event.stopPropagation();

                if (! exporting) {
                    finish(null);
                }
            }

            function confirm() {
                var mime = outputMime(file);

                if (!cropper || settled || exporting) {
                    return;
                }

                var canvas = renderCanvas(cropper, mime, options.maxWidth);

                if (!canvas) {
                    skip('无法导出裁剪结果');
                    return;
                }

                exporting = true;

                canvasToBlob(canvas, mime, options.quality)
                    .then(function (blob) {
                        exporting = false;
                        finish(asFile(blob, renameFor(file.name, mime), mime));
                    })
                    .catch(function (error) {
                        exporting = false;
                        skip(error.message);
                    });
            }

            image.onload = function () {
                cropper = new window.Cropper(image, {
                    aspectRatio: aspectRatio,
                    viewMode: 1,
                    dragMode: 'move',
                    autoCropArea: 1,
                    background: false,
                    // 手机竖拍照片带 EXIF 旋转，交给 Cropper 校正后再裁
                    checkOrientation: true,
                });
            };

            image.onerror = function () {
                skip('浏览器无法解码该图片');
            };

            layer.querySelector('[data-cmf-crop-confirm]').addEventListener('click', confirm);
            layer.querySelector('[data-cmf-crop-cancel]').addEventListener('click', function () {
                finish(null);
            });
            layer.querySelector('[data-cmf-crop-reset]').addEventListener('click', function () {
                if (cropper) {
                    cropper.reset();
                }
            });

            document.addEventListener('keydown', onKeydown);
            document.body.appendChild(layer);
            document.body.classList.add(LAYER_CLASS + '-open');
            image.src = objectUrl;
        });
    };
})();
