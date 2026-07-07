/* ==============================================
   assets/js/cfp-upload.js
   Multi Upload + Thumbnail Preview Component
   วิธีใช้:
     var uploader = new CfpUploader({
       assetType : 'Equipment',        // AssetType ใน CFP_AssetImage
       assetID   : 123,                // ID ของ record นั้น
       galleryEl : '#galleryWrap',     // container แสดงรูปที่บันทึกแล้ว
       dropzoneEl: '#dropzoneArea',    // dropzone area
       inputEl   : '#fileInput',       // <input type="file">
       queueEl   : '#uploadQueue',     // container แสดงไฟล์รอ upload
       uploadUrl : '../upload_handler.php',
       csrfToken : '<?= $token ?>',
     });
   ============================================== */

function CfpUploader(opts) {
    this.assetType  = opts.assetType;
    this.assetID    = opts.assetID;
    this.galleryEl  = opts.galleryEl ? document.querySelector(opts.galleryEl) : null;
    this.dropzoneEl = document.querySelector(opts.dropzoneEl);
    this.inputEl    = document.querySelector(opts.inputEl);
    this.queueEl    = document.querySelector(opts.queueEl);
    this.uploadUrl  = opts.uploadUrl || '../upload_handler.php';
    this.csrfToken  = opts.csrfToken || '';
    this.maxFiles   = opts.maxFiles  || 10;
    this.maxSizeMB  = opts.maxSizeMB || 5;
    this.queue         = [];   /* ไฟล์รอ upload (assetID > 0 อัปทันที) */
    this._pendingFiles = [];   /* ไฟล์รอ save ก่อน (assetID = 0) */
    this._lightboxImages = [];
    this._lightboxIndex  = 0;

    this._init();
}

CfpUploader.prototype._init = function() {
    var self = this;

    /* Dropzone click */
    this.dropzoneEl.addEventListener('click', function() {
        self.inputEl.click();
    });

    /* File input change */
    this.inputEl.addEventListener('change', function() {
        self._addFiles(Array.prototype.slice.call(this.files));
        this.value = '';
    });

    /* Drag & drop */
    this.dropzoneEl.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });
    this.dropzoneEl.addEventListener('dragleave', function() {
        this.classList.remove('dragover');
    });
    this.dropzoneEl.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        var files = Array.prototype.slice.call(e.dataTransfer.files);
        self._addFiles(files);
    });

    /* Lightbox init */
    this._initLightbox();
};

CfpUploader.prototype._addFiles = function(files) {
    var self     = this;
    var allowed  = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
    var imgTypes = ['image/jpeg','image/png','image/gif','image/webp'];

    files.forEach(function(file) {
        var totalCount = self.queue.length + self._pendingFiles.length;
        if (totalCount >= self.maxFiles) {
            self._toast('อัปโหลดได้สูงสุด ' + self.maxFiles + ' ไฟล์', true); return;
        }
        if (file.size > self.maxSizeMB * 1024 * 1024) {
            self._toast('"' + file.name + '" มีขนาดเกิน ' + self.maxSizeMB + ' MB', true); return;
        }
        if (allowed.indexOf(file.type) === -1) {
            self._toast('"' + file.name + '" ไม่รองรับ (รองรับ jpg/png/gif/webp/pdf)', true); return;
        }

        var objectURL = imgTypes.indexOf(file.type) !== -1 ? URL.createObjectURL(file) : null;
        var item = { file: file, objectURL: objectURL };

        if (self.assetID > 0) {
            /* มี assetID แล้ว → เข้า queue อัปโหลดทันที */
            self.queue.push(item);
            self._renderQueueItem(item);
        } else {
            /* assetID = 0 → เก็บใน _pendingFiles รอ save ก่อน */
            self._pendingFiles.push(item);
            self._renderQueueItem(item); /* แสดง preview ใน queue UI เหมือนกัน */
        }
    });
};

CfpUploader.prototype._renderQueueItem = function(item) {
    var self = this;
    var div  = document.createElement('div');
    div.className = 'cfp-queue-item';

    /* thumbnail หรือ icon */
    if (item.objectURL) {
        var img = document.createElement('img');
        img.src       = item.objectURL;
        img.className = 'cfp-queue-thumb';
        div.appendChild(img);
    } else {
        var icon = document.createElement('div');
        icon.className = 'cfp-queue-thumb-icon';
        icon.innerHTML = '<i class="bi bi-file-earmark-pdf-fill" style="color:#E05050;"></i>';
        div.appendChild(icon);
    }

    /* ชื่อไฟล์ */
    var name = document.createElement('div');
    name.className   = 'cfp-queue-name';
    name.textContent = item.file.name;
    div.appendChild(name);

    /* progress */
    var prog = document.createElement('div');
    prog.className = 'cfp-queue-progress';
    var bar = document.createElement('div');
    bar.className = 'cfp-queue-progress-bar';
    prog.appendChild(bar);
    div.appendChild(prog);
    item._bar = bar;
    item._div = div;

    /* ปุ่มลบออกจาก queue */
    var btn = document.createElement('button');
    btn.className = 'cfp-queue-remove';
    btn.type = 'button';
    btn.innerHTML = '<i class="bi bi-x"></i>';
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var idx = self.queue.indexOf(item);
        if (idx > -1) { self.queue.splice(idx, 1); }
        if (item.objectURL) { URL.revokeObjectURL(item.objectURL); }
        div.remove();
    });
    div.appendChild(btn);

    this.queueEl.appendChild(div);
};

/* ===== Upload ไฟล์ที่รอ save (assetID=0) — เรียกหลังได้ assetID ใหม่ ===== */
CfpUploader.prototype.uploadPending = function(assetID, callback) {
    var self = this;
    this.assetID = assetID;

    if (this._pendingFiles.length === 0) {
        if (callback) { callback(0, 0); }
        return;
    }

    var total = this._pendingFiles.length;
    var done  = 0; var ok = 0; var fail = 0;

    this._pendingFiles.slice().forEach(function(item) {
        var fd = new FormData();
        fd.append('action',     'upload');
        fd.append('asset_type', self.assetType);
        fd.append('asset_id',   assetID);
        fd.append('csrf_token', self.csrfToken);
        fd.append('file',       item.file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', self.uploadUrl);
        xhr.addEventListener('load', function() {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) { ok++; } else { fail++; }
            } catch(e) { fail++; }
            if (item.objectURL) { URL.revokeObjectURL(item.objectURL); }
            if (item._div) { item._div.remove(); }
            done++;
            if (done === total) {
                self._pendingFiles = [];
                if (callback) { callback(ok, fail); }
            }
        });
        xhr.addEventListener('error', function() {
            fail++; done++;
            if (done === total) {
                self._pendingFiles = [];
                if (callback) { callback(ok, fail); }
            }
        });
        xhr.send(fd);
    });
};

/* ===== Upload ทั้ง queue ===== */
CfpUploader.prototype.uploadAll = function(callback) {
    var self = this;
    if (this.queue.length === 0) {
        if (callback) { callback(0); }
        return;
    }

    var total     = this.queue.length;
    var doneCount = 0;
    var failCount = 0;

    this.queue.slice().forEach(function(item) {
        var fd = new FormData();
        fd.append('action',     'upload');
        fd.append('asset_type', self.assetType);
        fd.append('asset_id',   self.assetID);
        fd.append('csrf_token', self.csrfToken);
        fd.append('file',       item.file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', self.uploadUrl);

        /* progress */
        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable && item._bar) {
                item._bar.style.width = Math.round(e.loaded / e.total * 100) + '%';
            }
        });

        xhr.addEventListener('load', function() {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    /* เพิ่มใน gallery */
                    self._appendGalleryItem(res.image);
                    /* ลบออกจาก queue UI */
                    if (item._div) { item._div.remove(); }
                    if (item.objectURL) { URL.revokeObjectURL(item.objectURL); }
                } else {
                    self._toast('อัปโหลด "' + item.file.name + '" ไม่สำเร็จ: ' + (res.msg||''), true);
                    failCount++;
                }
            } catch(ex) {
                self._toast('Server error ขณะอัปโหลด "' + item.file.name + '"', true);
                failCount++;
            }
            doneCount++;
            if (doneCount === total) {
                /* ล้าง queue array */
                self.queue = [];
                if (callback) { callback(total - failCount, failCount); }
            }
        });

        xhr.addEventListener('error', function() {
            self._toast('เชื่อมต่อ server ไม่ได้', true);
            failCount++;
            doneCount++;
            if (doneCount === total) {
                self.queue = [];
                if (callback) { callback(total - failCount, failCount); }
            }
        });

        xhr.send(fd);
    });
};

/* ===== Render Gallery Item ===== */
CfpUploader.prototype._appendGalleryItem = function(img) {
    if (!this.galleryEl) { return; } /* assetID=0 ยังไม่มี gallery */
    var self = this;
    var item = document.createElement('div');
    item.className = 'cfp-gallery-item' + (img.IsPrimary ? ' is-primary' : '');
    item.dataset.imageId = img.ImageID;

    /* primary badge */
    if (img.IsPrimary) {
        var badge = document.createElement('div');
        badge.className   = 'cfp-gallery-primary-badge';
        badge.textContent = 'หลัก';
        item.appendChild(badge);
    }

    /* thumbnail หรือ icon */
    var isImage = /\.(jpg|jpeg|png|gif|webp)$/i.test(img.FileName);
    if (isImage) {
        var thumb = document.createElement('img');
        thumb.className = 'cfp-gallery-thumb';
        thumb.src       = img.FilePath;
        thumb.alt       = img.Caption || img.OriginalName;
        thumb.loading   = 'lazy';
        item.appendChild(thumb);
    } else {
        var iconDiv = document.createElement('div');
        iconDiv.className = 'cfp-gallery-icon';
        iconDiv.innerHTML = '<i class="bi bi-file-earmark-pdf-fill" style="color:#E05050;"></i>';
        item.appendChild(iconDiv);
    }

    /* overlay actions */
    var overlay = document.createElement('div');
    overlay.className = 'cfp-gallery-overlay';

    if (isImage) {
        var btnView = document.createElement('button');
        btnView.className = 'cfp-gallery-btn btn-view';
        btnView.type      = 'button';
        btnView.title     = 'ดูรูปเต็ม';
        btnView.innerHTML = '<i class="bi bi-zoom-in"></i>';
        btnView.addEventListener('click', function(e) {
            e.stopPropagation();
            self._openLightbox(img.FilePath, img.Caption || img.OriginalName);
        });
        overlay.appendChild(btnView);
    }

    /* ตั้งเป็นรูปหลัก */
    if (!img.IsPrimary) {
        var btnPrimary = document.createElement('button');
        btnPrimary.className = 'cfp-gallery-btn btn-primary-set';
        btnPrimary.type      = 'button';
        btnPrimary.title     = 'ตั้งเป็นรูปหลัก';
        btnPrimary.innerHTML = '<i class="bi bi-star-fill"></i>';
        btnPrimary.addEventListener('click', function(e) {
            e.stopPropagation();
            self._setPrimary(img.ImageID, item);
        });
        overlay.appendChild(btnPrimary);
    }

    /* ลบ */
    var btnDel = document.createElement('button');
    btnDel.className = 'cfp-gallery-btn btn-delete';
    btnDel.type      = 'button';
    btnDel.title     = 'ลบรูป';
    btnDel.innerHTML = '<i class="bi bi-trash"></i>';
    btnDel.addEventListener('click', function(e) {
        e.stopPropagation();
        self._deleteImage(img.ImageID, item);
    });
    overlay.appendChild(btnDel);
    item.appendChild(overlay);

    /* Caption */
    var caption = document.createElement('div');
    caption.className   = 'cfp-gallery-caption';
    caption.textContent = img.Caption || img.OriginalName;
    item.appendChild(caption);

    /* เพิ่มเข้า lightbox images */
    if (isImage) { this._lightboxImages.push({ src: img.FilePath, caption: img.Caption || img.OriginalName }); }

    this.galleryEl.appendChild(item);
};

/* ===== โหลดรูปที่บันทึกแล้ว ===== */
CfpUploader.prototype.loadImages = function() {
    var self = this;
    this.galleryEl.innerHTML = '';
    this._lightboxImages     = [];

    var xhr = new XMLHttpRequest();
    xhr.open('POST', this.uploadUrl);

    var fd = new FormData();
    fd.append('action',     'list');
    fd.append('asset_type', this.assetType);
    fd.append('asset_id',   this.assetID);
    fd.append('csrf_token', this.csrfToken);

    xhr.addEventListener('load', function() {
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.success && res.images) {
                res.images.forEach(function(img) {
                    self._appendGalleryItem(img);
                });
            }
        } catch(ex) { /* silent */ }
    });
    xhr.send(fd);
};

/* ===== Set Primary ===== */
CfpUploader.prototype._setPrimary = function(imageID, itemEl) {
    var self = this;
    var fd   = new FormData();
    fd.append('action',     'set_primary');
    fd.append('image_id',   imageID);
    fd.append('asset_type', this.assetType);
    fd.append('asset_id',   this.assetID);
    fd.append('csrf_token', this.csrfToken);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', this.uploadUrl);
    xhr.addEventListener('load', function() {
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.success) {
                /* อัปเดต UI */
                self.galleryEl.querySelectorAll('.cfp-gallery-item').forEach(function(el) {
                    el.classList.remove('is-primary');
                    var b = el.querySelector('.cfp-gallery-primary-badge');
                    if (b) { b.remove(); }
                });
                itemEl.classList.add('is-primary');
                var badge = document.createElement('div');
                badge.className   = 'cfp-gallery-primary-badge';
                badge.textContent = 'หลัก';
                itemEl.insertBefore(badge, itemEl.firstChild);
                self._toast('ตั้งรูปหลักเรียบร้อย');
            } else {
                self._toast(res.msg || 'เกิดข้อผิดพลาด', true);
            }
        } catch(ex) { self._toast('Server error', true); }
    });
    xhr.send(fd);
};

/* ===== Delete Image ===== */
CfpUploader.prototype._deleteImage = function(imageID, itemEl) {
    var self = this;
    if (!window.Swal) {
        if (!confirm('ยืนยันการลบรูปนี้?')) { return; }
        self._doDelete(imageID, itemEl);
        return;
    }
    Swal.fire({
        title: 'ยืนยันการลบ?',
        text: 'ต้องการลบรูปนี้ออกจากระบบ?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#E05050',
        cancelButtonColor: '#9E9E9E',
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (result.isConfirmed) { self._doDelete(imageID, itemEl); }
    });
};

CfpUploader.prototype._doDelete = function(imageID, itemEl) {
    var self = this;
    var fd   = new FormData();
    fd.append('action',     'delete');
    fd.append('image_id',   imageID);
    fd.append('asset_type', this.assetType);
    fd.append('asset_id',   this.assetID);
    fd.append('csrf_token', this.csrfToken);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', this.uploadUrl);
    xhr.addEventListener('load', function() {
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.success) {
                itemEl.remove();
                self._toast('ลบรูปเรียบร้อย');
            } else {
                self._toast(res.msg || 'เกิดข้อผิดพลาด', true);
            }
        } catch(ex) { self._toast('Server error', true); }
    });
    xhr.send(fd);
};

/* ===== Lightbox ===== */
CfpUploader.prototype._initLightbox = function() {
    var self = this;
    /* สร้าง lightbox DOM ถ้ายังไม่มี */
    if (!document.getElementById('cfpLightbox')) {
        var lb = document.createElement('div');
        lb.id = 'cfpLightbox';
        lb.className = 'cfp-lightbox';
        lb.innerHTML =
            '<button class="cfp-lightbox-close" id="lbClose"><i class="bi bi-x-lg"></i></button>' +
            '<button class="cfp-lightbox-nav prev" id="lbPrev"><i class="bi bi-chevron-left"></i></button>' +
            '<img id="lbImg" src="" alt="">' +
            '<div class="cfp-lightbox-caption" id="lbCaption"></div>' +
            '<button class="cfp-lightbox-nav next" id="lbNext"><i class="bi bi-chevron-right"></i></button>';
        document.body.appendChild(lb);

        document.getElementById('lbClose').addEventListener('click', function() {
            lb.classList.remove('open');
        });
        lb.addEventListener('click', function(e) {
            if (e.target === lb) { lb.classList.remove('open'); }
        });
        document.getElementById('lbPrev').addEventListener('click', function() {
            self._lightboxNav(-1);
        });
        document.getElementById('lbNext').addEventListener('click', function() {
            self._lightboxNav(1);
        });
        document.addEventListener('keydown', function(e) {
            if (!lb.classList.contains('open')) { return; }
            if (e.key === 'ArrowLeft')  { self._lightboxNav(-1); }
            if (e.key === 'ArrowRight') { self._lightboxNav(1);  }
            if (e.key === 'Escape')     { lb.classList.remove('open'); }
        });
    }
};

CfpUploader.prototype._openLightbox = function(src, caption) {
    this._lightboxIndex = this._lightboxImages.findIndex(function(i) { return i.src === src; });
    if (this._lightboxIndex === -1) { this._lightboxIndex = 0; }
    this._showLightboxAt(this._lightboxIndex);
    document.getElementById('cfpLightbox').classList.add('open');
};

CfpUploader.prototype._lightboxNav = function(dir) {
    var len = this._lightboxImages.length;
    if (len === 0) { return; }
    this._lightboxIndex = (this._lightboxIndex + dir + len) % len;
    this._showLightboxAt(this._lightboxIndex);
};

CfpUploader.prototype._showLightboxAt = function(idx) {
    var imgs = this._lightboxImages;
    if (!imgs[idx]) { return; }
    document.getElementById('lbImg').src         = imgs[idx].src;
    document.getElementById('lbCaption').textContent = imgs[idx].caption || '';
    /* ซ่อน nav ถ้ามีรูปแค่ 1 รูป */
    var show = imgs.length > 1 ? '' : 'none';
    document.getElementById('lbPrev').style.display = show;
    document.getElementById('lbNext').style.display = show;
};

/* ===== Toast helper ===== */
CfpUploader.prototype._toast = function(msg, isError) {
    if (window.Swal) {
        Swal.fire({
            toast: true,
            position: 'bottom-end',
            icon: isError ? 'error' : 'success',
            title: msg,
            showConfirmButton: false,
            timer: 3000,
            customClass: { popup: 'font-prompt' }
        });
    } else {
        alert(msg);
    }
};