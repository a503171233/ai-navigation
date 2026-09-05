/**
 * DCAI 授权商城 前台公共交互
 *  - 表单按钮防重复提交（loading 态）
 *  - 必填字段客户端校验反馈（data-validate 表单；关闭浏览器原生校验，避免原生拦截与按钮 loading 时序冲突）
 *  - 轻提示 toast
 */
(function () {
    'use strict';

    // 校验指定表单；返回第一个非法字段（无则 null）。同时给出高亮反馈。
    function validateForm(form) {
        var firstInvalid = null;
        form.querySelectorAll('[required]').forEach(function (field) {
            var val = (field.value || '').trim();
            // 支持 minlength/maxlength/pattern 的字段级补充校验（与原生规则一致）
            var ok = field.type === 'checkbox' ? field.checked : val !== '';
            if (ok && field.hasAttribute('minlength') && val.length < parseInt(field.getAttribute('minlength'), 10)) {
                ok = false;
            }
            if (ok && field.hasAttribute('maxlength') && val.length > parseInt(field.getAttribute('maxlength'), 10)) {
                ok = false;
            }
            if (ok && field.hasAttribute('pattern') && field.getAttribute('pattern') !== '') {
                ok = new RegExp(field.getAttribute('pattern')).test(val);
            }
            if (!ok) {
                field.classList.add('invalid');
                if (!firstInvalid) firstInvalid = field;
            } else {
                field.classList.remove('invalid');
            }
        });
        return firstInvalid;
    }

    // ---------- 表单提交：校验 + 按钮加载态 ----------
    // 表单带 data-validate 时走自定义校验（含 minlength 等）；否则仅做按钮防重复提交。
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        var btn = form.querySelector('[type="submit"]');
        var custom = form.hasAttribute('data-validate');

        if (custom) {
            // 自定义校验失败：阻止提交并恢复按钮，聚焦首个非法字段
            var firstInvalid = validateForm(form);
            if (firstInvalid) {
                e.preventDefault();
                if (btn) { btn.disabled = false; btn.classList.remove('loading'); }
                firstInvalid.focus();
                dcaiShopToast('请正确填写表单', 'warning');
                return;
            }
        }

        // data-confirm 二次确认（校验通过后、按钮置 loading 前）
        var confirmMsg = form.getAttribute('data-confirm');
        if (confirmMsg && !window.confirm(confirmMsg)) {
            e.preventDefault();
            return;
        }

        // 校验通过（或无需校验）：按钮置 loading 防重复提交
        if (btn && !btn.disabled) {
            btn.disabled = true;
            btn.classList.add('loading');
        }
    });

    // ---------- 输入时清除非法高亮 ----------
    document.addEventListener('input', function (e) {
        var field = e.target;
        if (field && field.classList) field.classList.remove('invalid');
    });

    // ---------- Toast 轻提示 ----------
    window.dcaiShopToast = function (msg, type) {
        var el = document.createElement('div');
        el.className = 'shop-toast ' + (type || 'info');
        el.textContent = msg;
        document.body.appendChild(el);
        requestAnimationFrame(function () { el.classList.add('show'); });
        setTimeout(function () {
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 300);
        }, 2600);
    };

    // ---------- 二级导航折叠/展开（分组） ----------
    document.querySelectorAll('.shop-nav [data-nav-toggle]').forEach(function (head) {
        head.addEventListener('click', function (e) {
            e.stopPropagation();
            var body = head.nextElementSibling;
            if (!body || !body.classList.contains('nav-group-body')) return;
            var arrow = head.querySelector('.nav-arrow');
            var isHidden = body.classList.contains('hidden');
            if (isHidden) {
                body.classList.remove('hidden');
                head.classList.add('open');
                if (arrow) arrow.textContent = '−';
            } else {
                body.classList.add('hidden');
                head.classList.remove('open');
                if (arrow) arrow.textContent = '+';
            }
        });
    });

    // 点击导航外部关闭展开的下拉
    document.addEventListener('click', function (e) {
        var nav = document.getElementById('shopNav');
        if (!nav) return;
        if (!nav.contains(e.target)) {
            nav.querySelectorAll('.nav-group-body:not(.hidden)').forEach(function (body) {
                body.classList.add('hidden');
                var head = body.previousElementSibling;
                if (head) { head.classList.remove('open'); var a = head.querySelector('.nav-arrow'); if (a) a.textContent = '+'; }
            });
        }
    });

    // ---------- 移动端菜单开关 ----------
    var shopMenuBtn = document.getElementById('shopMenuBtn');
    var shopNav = document.getElementById('shopNav');
    if (shopMenuBtn && shopNav) {
        shopMenuBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            shopNav.classList.toggle('open');
        });
    }
})();
