(function (window, document) {
    'use strict';

    function buildToast(message, type) {
        var toast = document.createElement('div');
        var isSuccess = type !== 'error';

        toast.style.background = isSuccess ? '#00a32a' : '#d63638';
        toast.style.position = 'fixed';
        toast.style.top = '60px';
        toast.style.right = '20px';
        toast.style.zIndex = '99999';
        toast.style.color = '#fff';
        toast.style.padding = '12px 20px';
        toast.style.borderRadius = '6px';
        toast.style.boxShadow = '0 4px 20px rgba(0,0,0,0.25)';
        toast.style.fontSize = '14px';
        toast.style.fontWeight = '500';
        toast.style.maxWidth = '400px';
        toast.style.display = 'flex';
        toast.style.alignItems = 'center';
        toast.style.gap = '10px';

        var text = document.createElement('span');
        text.textContent = message || '';

        var close = document.createElement('button');
        close.type = 'button';
        close.textContent = '\u00d7';
        close.style.background = 'none';
        close.style.border = 'none';
        close.style.color = '#fff';
        close.style.cursor = 'pointer';
        close.style.fontSize = '18px';
        close.style.lineHeight = '1';
        close.style.padding = '0';
        close.style.marginLeft = 'auto';

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
        var ttl = typeof duration === 'number' ? duration : (type === 'error' ? 8000 : 4000);

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
