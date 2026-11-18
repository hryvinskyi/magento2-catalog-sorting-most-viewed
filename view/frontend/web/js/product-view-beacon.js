/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Counts one view of the product page for each [data-hryvinskyi-view-beacon] element, which carries the endpoint
 * URL and the product id. The element is marked as sent, so the view is sent once however often the script runs,
 * and the script starts whenever it runs, before or after the document has loaded.
 */
(function (d, n) {
    'use strict';

    var MARK = 'data-hryvinskyi-view-beacon';

    function send(el) {
        var url = el.getAttribute('data-url'),
            id = el.getAttribute('data-product') || '',
            body = new URLSearchParams({product: id});

        if (el.getAttribute(MARK) !== 'sent' && url && /^[1-9]\d*$/.test(id)) {
            el.setAttribute(MARK, 'sent');
            if (!(n.sendBeacon && n.sendBeacon(url, body)) && typeof fetch === 'function') {
                fetch(url, {method: 'POST', body: body, keepalive: true, credentials: 'omit'})
                    .catch(function (e) {
                        console.warn('Product view not counted', e);
                    });
            }
        }
    }

    function run() {
        [].forEach.call(d.querySelectorAll('[' + MARK + ']'), send);
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', run, {once: true});
    } else {
        run();
    }
}(document, navigator));
