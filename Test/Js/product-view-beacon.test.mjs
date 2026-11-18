/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Behavioural tests for the product view beacon, run as the browser runs it: the classic script is executed in a
 * fresh context holding a fake document with beacon elements and a recording navigator. Plain Node, no dependencies:
 * node --test app/code/Hryvinskyi/CatalogSortingMostViewed/Test/Js/product-view-beacon.test.mjs
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const script = new vm.Script(
    readFileSync(new URL('../../view/frontend/web/js/product-view-beacon.js', import.meta.url), 'utf8'),
    {filename: 'product-view-beacon.js'}
);

const URL_OF_ENDPOINT = 'https://shop.test/hryvinskyi_sorting/view/count/';

/**
 * An element that keeps its attributes the way the DOM does
 *
 * @param {Object<string, string>} attributes
 */
function element(attributes) {
    const values = new Map(Object.entries(attributes));

    return {
        getAttribute: (name) => (values.has(name) ? values.get(name) : null),
        setAttribute: (name, value) => values.set(name, String(value))
    };
}

/**
 * A beacon element as the template prints it
 *
 * @param {string} product
 * @param {string} url
 */
function beacon(product = '42', url = URL_OF_ENDPOINT) {
    return element({'data-hryvinskyi-view-beacon': '', 'data-url': url, 'data-product': product});
}

/**
 * A browser context: a document holding the elements, a navigator whose sendBeacon records each call, and a
 * fetch that records each call
 *
 * @param {Array<Object>} elements
 * @param {{readyState?: string, sendBeacon?: (false|function(): boolean)}} options
 */
function browser(elements, {readyState = 'complete', sendBeacon = () => true} = {}) {
    const beacons = [];
    const fetches = [];
    const listeners = [];
    const navigator = {};
    if (sendBeacon !== false) {
        navigator.sendBeacon = (url, body) => {
            beacons.push({url, body: String(body)});
            return sendBeacon();
        };
    }

    const context = vm.createContext({
        document: {
            readyState,
            querySelectorAll: (selector) => {
                assert.equal(selector, '[data-hryvinskyi-view-beacon]');
                return elements;
            },
            addEventListener: (type, listener, options) => listeners.push({type, listener, options})
        },
        navigator,
        URLSearchParams,
        console: {warn: () => {}},
        fetch: (url, init) => {
            fetches.push({url, init, body: String(init.body)});
            return Promise.resolve({ok: true});
        }
    });

    return {
        beacons,
        fetches,
        listeners,
        context,
        run: () => script.runInContext(context)
    };
}

test('a beacon element sends one view with the endpoint URL and the product id', () => {
    const page = browser([beacon('42')]);

    page.run();

    assert.deepEqual(page.beacons, [{url: URL_OF_ENDPOINT, body: 'product=42'}]);
    assert.deepEqual(page.fetches, []);
});

test('running the script again sends nothing more for the same element', () => {
    const page = browser([beacon('42')]);

    page.run();
    page.run();

    assert.equal(page.beacons.length, 1);
});

test('each beacon element sends its own view once', () => {
    const page = browser([beacon('7'), beacon('9')]);

    page.run();
    page.run();

    assert.deepEqual(page.beacons.map((call) => call.body), ['product=7', 'product=9']);
});

test('a product that is not a positive whole number sends nothing', () => {
    for (const product of ['', '0', '-3', '1.5', '12abc', ' 42', '042']) {
        const page = browser([beacon(product)]);

        page.run();

        assert.deepEqual(page.beacons, [], `data-product="${product}"`);
        assert.deepEqual(page.fetches, [], `data-product="${product}"`);
    }
});

test('an element without a product or without an endpoint URL sends nothing', () => {
    const page = browser([
        element({'data-hryvinskyi-view-beacon': '', 'data-url': URL_OF_ENDPOINT}),
        beacon('42', '')
    ]);

    page.run();

    assert.deepEqual(page.beacons, []);
    assert.deepEqual(page.fetches, []);
});

test('without sendBeacon the view is posted by a keepalive fetch that sends no cookies', () => {
    const page = browser([beacon('42')], {sendBeacon: false});

    page.run();

    assert.equal(page.fetches.length, 1);
    assert.equal(page.fetches[0].url, URL_OF_ENDPOINT);
    assert.equal(page.fetches[0].body, 'product=42');
    assert.equal(page.fetches[0].init.method, 'POST');
    assert.equal(page.fetches[0].init.keepalive, true);
    assert.equal(page.fetches[0].init.credentials, 'omit');
});

test('a beacon the browser refuses to queue is posted by fetch instead', () => {
    const page = browser([beacon('42')], {sendBeacon: () => false});

    page.run();

    assert.equal(page.beacons.length, 1);
    assert.equal(page.fetches.length, 1);
    assert.equal(page.fetches[0].body, 'product=42');
});

test('a fetch that fails is reported, not left unhandled', async () => {
    const page = browser([beacon('42')], {sendBeacon: false});
    const warnings = [];
    page.context.console = {warn: (...args) => warnings.push(args)};
    page.context.fetch = () => Promise.reject(new Error('offline'));

    page.run();
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(warnings.length, 1);
});

test('while the document is still loading the view is sent on DOMContentLoaded, not before', () => {
    const page = browser([beacon('42')], {readyState: 'loading'});

    page.run();

    assert.deepEqual(page.beacons, []);
    assert.equal(page.listeners.length, 1);
    assert.equal(page.listeners[0].type, 'DOMContentLoaded');
    assert.equal(page.listeners[0].options.once, true);

    page.listeners[0].listener();

    assert.deepEqual(page.beacons, [{url: URL_OF_ENDPOINT, body: 'product=42'}]);
});

test('the script leaves no global behind', () => {
    const page = browser([beacon('42')]);
    const before = Object.keys(page.context).sort();

    page.run();

    assert.deepEqual(Object.keys(page.context).sort(), before);
});
