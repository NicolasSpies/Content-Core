(function (window, document) {
    'use strict';

    function normalizeType(type) {
        if (type === 'warning') {
            return 'warning';
        }
        if (type === 'error' || type === 'danger') {
            return 'danger';
        }
        return 'success';
    }

    function buildToast(message, type) {
        var toast = document.createElement('div');
        var variant = normalizeType(type);

        toast.className = 'cc-toast cc-toast--' + variant;

        var text = document.createElement('span');
        text.className = 'cc-toast__text';
        text.textContent = message || '';

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'cc-toast__close';
        close.textContent = '\u00d7';

        close.addEventListener('click', function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        });

        toast.appendChild(text);
        toast.appendChild(close);

        return toast;
    }

    function show(message, type, duration) {
        var existing = document.getElementById('cc-shared-toast');
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }

        var toast = buildToast(message, type);
        var normalized = normalizeType(type);
        var ttl = typeof duration === 'number' ? duration : (normalized === 'danger' ? 8000 : 4000);

        toast.id = 'cc-shared-toast';
        document.body.appendChild(toast);

        window.setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, ttl);
    }

    window.CCToast = window.CCToast || {
        show: show
    };
})(window, document);
