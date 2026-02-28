(function () {
    const titleInput = document.querySelector('#cc_seo_title');
    const descInput = document.querySelector('#cc_meta_description');

    const box = document.querySelector('.cc-seo-preview');
    if (!box) return;

    const elDomain = box.querySelector('.cc-seo-preview-domain');
    const elTitle = box.querySelector('.cc-seo-preview-title');
    const elDesc = box.querySelector('.cc-seo-preview-desc');

    const siteUrl = (window.CC_SEO_PREVIEW && CC_SEO_PREVIEW.siteUrl) || '';
    const defaultTitle = (window.CC_SEO_PREVIEW && CC_SEO_PREVIEW.defaultTitle) || '';
    const defaultDesc = (window.CC_SEO_PREVIEW && CC_SEO_PREVIEW.defaultDesc) || '';

    const domain = siteUrl.replace(/^https?:\/\//, '').replace(/\/$/, '');
    elDomain.textContent = domain;

    function clamp(str, max) {
        str = (str || '').trim();
        if (!str) return '';
        return str.length > max ? str.slice(0, max - 1) + '…' : str;
    }

    function render() {
        const title = titleInput ? titleInput.value : '';
        const desc = descInput ? descInput.value : '';

        const finalTitle = clamp(title || defaultTitle, 60);
        const finalDesc = clamp(desc || defaultDesc, 160);

        elTitle.textContent = finalTitle || '';
        elDesc.textContent = finalDesc || '';
    }

    render();

    if (titleInput) titleInput.addEventListener('input', render);
    if (descInput) descInput.addEventListener('input', render);
})();
