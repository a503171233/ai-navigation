document.addEventListener('DOMContentLoaded', function () {
    // 模态框
    document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-modal-open'));
            if (target) target.classList.add('show');
        });
    });
    document.querySelectorAll('.modal-mask').forEach(function (mask) {
        mask.addEventListener('click', function (e) {
            if (e.target === mask) mask.classList.remove('show');
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var mask = btn.closest('.modal-mask');
            if (mask) mask.classList.remove('show');
        });
    });

    // 删除确认
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            var msg = el.getAttribute('data-confirm') || '确定执行该操作吗？';
            if (!window.confirm(msg)) e.preventDefault();
        });
    });

    // 危险操作（disable 命令等）二次确认
    document.querySelectorAll('[data-confirm-danger]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            var msg = el.getAttribute('data-confirm-danger') || '此操作不可恢复，确定继续？';
            if (!window.confirm(msg)) e.preventDefault();
        });
    });

    // 批量选择
    document.querySelectorAll('table [data-check-all]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var name = cb.getAttribute('data-check-all');
            document.querySelectorAll('input[name="' + name + '"]').forEach(function (item) {
                item.checked = cb.checked;
            });
        });
    });

    // 弹窗预览
    document.querySelectorAll('[data-popup-preview]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var title = btn.getAttribute('data-title') || '';
            var content = btn.getAttribute('data-content') || '';
            var type = btn.getAttribute('data-type') || '1';
            var icons = {1: '📢', 2: '🔔', 3: '⚠️'};
            var preview = document.getElementById('popup-preview');
            if (!preview) return;
            preview.querySelector('.pp-icon').textContent = icons[type] || '📢';
            preview.querySelector('.pp-title').textContent = title;
            preview.querySelector('.pp-content').innerHTML = content;
            var mask = document.getElementById('popup-preview-mask');
            if (mask) mask.classList.add('show');
        });
    });

    // 技能函数面板展开/收起（同一时刻仅展开一个）
    document.querySelectorAll('[data-toggle-func]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-toggle-func'));
            if (!target) return;
            var willShow = target.style.display === 'none';
            document.querySelectorAll('.func-panel').forEach(function (p) { p.style.display = 'none'; });
            document.querySelectorAll('[data-toggle-func]').forEach(function (b) { b.classList.remove('active'); });
            if (willShow) {
                target.style.display = '';
                btn.classList.add('active');
            }
        });
    });

    // ---------- 按钮加载态（防重复提交）----------
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('[type="submit"]');
            if (!btn || btn.disabled) return;
            // 确认类表单（带 data-confirm 的按钮在 submit 前已弹窗确认，这里仅防重复）
            btn.disabled = true;
            btn.classList.add('loading');
        });
    });

    // ---------- Toast 轻提示 ----------
    window.dcaiToast = function (msg, type) {
        var el = document.createElement('div');
        el.className = 'dcai-toast ' + (type || 'info');
        el.textContent = msg;
        document.body.appendChild(el);
        requestAnimationFrame(function () { el.classList.add('show'); });
        setTimeout(function () {
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 300);
        }, 2600);
    };
    // 任何 data-toast 点击触发轻提示（不阻断原有行为）
    document.querySelectorAll('[data-toast]').forEach(function (el) {
        el.addEventListener('click', function () {
            window.dcaiToast(el.getAttribute('data-toast'), el.getAttribute('data-toast-type') || 'success');
        });
    });

    // ---------- 一键复制（data-copy="内容" 或从 data-copy-from="#id" 取文本）----------
    document.querySelectorAll('[data-copy], [data-copy-from]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            var text = el.getAttribute('data-copy');
            if (text === null) {
                var src = document.querySelector(el.getAttribute('data-copy-from'));
                text = src ? (src.value !== undefined ? src.value : src.textContent) : '';
            }
            text = (text || '').trim();
            if (!text) { window.dcaiToast('无可复制内容', 'warning'); return; }
            var done = function () {
                window.dcaiToast('已复制到剪贴板', 'success');
                var label = el.getAttribute('data-copy-label');
                if (label) {
                    var html = el.innerHTML;
                    el.innerHTML = label;
                    setTimeout(function () { el.innerHTML = html; }, 1600);
                }
            };
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(); });
                } else { fallbackCopy(); }
            } catch (err) { fallbackCopy(); }
            function fallbackCopy() {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); } catch (err) { window.dcaiToast('复制失败，请手动选择复制', 'danger'); }
                document.body.removeChild(ta);
            }
        });
    });

    // ---------- 展示/隐藏完整值（data-reveal="元素id" 切换）----------
    document.querySelectorAll('[data-reveal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-reveal'));
            if (!target) return;
            var revealed = btn.classList.contains('revealed');
            if (revealed) {
                target.textContent = target.getAttribute('data-short') || target.textContent;
                btn.textContent = btn.getAttribute('data-show-label') || '显示完整';
            } else {
                target.textContent = btn.getAttribute('data-full') || target.textContent;
                btn.textContent = btn.getAttribute('data-hide-label') || '收起';
            }
            btn.classList.toggle('revealed');
        });
    });

    // ---------- 表单客户端校验反馈（空必填高亮） ----------
    document.querySelectorAll('form[data-validate]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var firstInvalid = null;
            form.querySelectorAll('[required]').forEach(function (field) {
                var val = (field.value || '').trim();
                var ok = field.type === 'checkbox' ? field.checked : val !== '';
                if (!ok) {
                    field.classList.add('invalid');
                    if (!firstInvalid) firstInvalid = field;
                } else {
                    field.classList.remove('invalid');
                }
            });
            if (firstInvalid) {
                e.preventDefault();
                firstInvalid.focus();
                window.dcaiToast('请填写必填项', 'warning');
            }
        });
        form.querySelectorAll('[required]').forEach(function (field) {
            field.addEventListener('input', function () { field.classList.remove('invalid'); });
        });
    });

    // ---------- ESC 关闭弹窗 + 点击内容区不冒泡 ----------
    document.querySelectorAll('.modal').forEach(function (modal) {
        modal.addEventListener('click', function (e) { e.stopPropagation(); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal-mask.show').forEach(function (mask) {
            mask.classList.remove('show');
        });
        // 同时关闭移动端侧边栏
        var sidebar = document.getElementById('appSidebar');
        var mask = document.getElementById('sidebarMask');
        if (sidebar) sidebar.classList.remove('open');
        if (mask) mask.classList.remove('show');
    });

    // ---------- 二级导航折叠/展开（分组） ----------
    document.querySelectorAll('[data-nav-toggle]').forEach(function (head) {
        head.addEventListener('click', function () {
            var body = head.nextElementSibling;
            if (!body || !body.classList.contains('nav-group-body')) return;
            var isOpen = !body.classList.contains('hidden');
            var arrow = head.querySelector('.nav-arrow');
            if (isOpen) {
                body.classList.add('hidden');
                head.classList.remove('open');
                if (arrow) arrow.textContent = '+';
            } else {
                body.classList.remove('hidden');
                head.classList.add('open');
                if (arrow) arrow.textContent = '−';
            }
        });
    });

    // ---------- 移动端侧边栏开关 ----------
    var sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            var sidebar = document.getElementById('appSidebar');
            var mask = document.getElementById('sidebarMask');
            if (sidebar) sidebar.classList.toggle('open');
            if (mask) mask.classList.toggle('show');
        });
    }
    var sidebarMask = document.getElementById('sidebarMask');
    if (sidebarMask) {
        sidebarMask.addEventListener('click', function () {
            var sidebar = document.getElementById('appSidebar');
            if (sidebar) sidebar.classList.remove('open');
            sidebarMask.classList.remove('show');
        });
    }
});
