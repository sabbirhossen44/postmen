'use strict';

/**
 * pm-runner.js — Postman-style pm.* sandbox runner.
 *
 * Reads one JSON document on stdin and writes one JSON document on stdout:
 *
 *   input:  { script, variables: { environment, collectionVariables, globals, data },
 *             request: { method, url, headers, body },
 *             response: null | { code, status, headers, body } }
 *   output: { tests: [{ name, passed, error }], logs: [],
 *             variables: { environment, collectionVariables, globals },
 *             error: string | null }
 *
 * User code runs inside Node's `vm` module in a context that exposes ONLY
 * `pm` and `console`. There is no `require`, `process`, `Buffer`, `fs` or
 * network access for user code, and synchronous execution is capped by the
 * vm timeout (async work by a Promise.race timeout on top of it).
 *
 * This is a sandbox for the owning user's own scripts — the same trust
 * model as Postman's local sandbox — not a hardened boundary against a
 * determined attacker: the `Function` constructor reachable through JS
 * intrinsics (e.g. `({}).constructor.constructor`) can still escape a
 * `vm` context. Never run scripts from untrusted third parties here.
 *
 * Plain JS, no dependencies. Run with: node resources/js/sandbox/pm-runner.js
 */

import vm from 'node:vm';
import { isDeepStrictEqual } from 'node:util';
import fs from 'node:fs';

const VM_TIMEOUT_MS = 4000;
const MAX_LOG_ENTRIES = 100;
const MAX_LOG_CHARS = 2000;
const MAX_TESTS = 500;

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

function stringify(value) {
    if (typeof value === 'string') return value;
    if (value === undefined) return 'undefined';
    try {
        return JSON.stringify(value);
    } catch (e) {
        return String(value);
    }
}

function truncate(text, max) {
    return text.length > max ? text.slice(0, max) + '… (truncated)' : text;
}

function timeoutError(ms) {
    const error = new Error('Script timed out after ' + ms + 'ms.');
    error.code = 'PM_SCRIPT_TIMEOUT';
    return error;
}

function withTimeout(promise, ms) {
    let timer = null;
    const timeout = new Promise((_, reject) => {
        timer = setTimeout(() => reject(timeoutError(ms)), ms);
    });
    return Promise.race([
        Promise.resolve(promise).finally(() => clearTimeout(timer)),
        timeout,
    ]);
}

/** Case-insensitive header lookup over a plain { Name: value } map. */
function headerBag(raw) {
    const bag = {};
    for (const [key, value] of Object.entries(raw || {})) {
        bag[key] = value;
    }
    bag.get = function (name) {
        const wanted = String(name).toLowerCase();
        for (const [key, value] of Object.entries(raw || {})) {
            if (key.toLowerCase() === wanted) return value;
        }
        return undefined;
    };
    bag.has = function (name) {
        return bag.get(name) !== undefined;
    };
    return bag;
}

/* ------------------------------------------------------------------ */
/* pm.expect                                                           */
/* ------------------------------------------------------------------ */

class AssertionError extends Error {
    constructor(message) {
        super(message);
        this.name = 'AssertionError';
    }
}

class Assertion {
    constructor(actual) {
        this.actual = actual;
        this.negate = false;
    }

    // Chain words — all no-ops that return the assertion.
    get to() { return this; }
    get be() { return this; }
    get been() { return this; }
    get is() { return this; }
    get that() { return this; }
    get which() { return this; }
    get and() { return this; }
    get have() { return this; }
    get has() { return this; }
    get with() { return this; }
    get at() { return this; }
    get of() { return this; }
    get same() { return this; }
    get deep() { return this; }

    get not() {
        this.negate = !this.negate;
        return this;
    }

    check(passed, message) {
        if (this.negate) passed = !passed;
        if (!passed) throw new AssertionError(message);
        return this;
    }

    describe(value) {
        return stringify(value);
    }

    equal(expected) {
        return this.check(
            this.actual === expected,
            'expected ' + this.describe(this.actual) + ' to equal ' + this.describe(expected),
        );
    }

    eql(expected) {
        return this.check(
            isDeepStrictEqual(this.actual, expected),
            'expected ' + this.describe(this.actual) + ' to deeply equal ' + this.describe(expected),
        );
    }

    get true() {
        return this.check(this.actual === true, 'expected ' + this.describe(this.actual) + ' to be true');
    }

    get false() {
        return this.check(this.actual === false, 'expected ' + this.describe(this.actual) + ' to be false');
    }

    get null() {
        return this.check(this.actual === null, 'expected ' + this.describe(this.actual) + ' to be null');
    }

    get undefined() {
        return this.check(this.actual === undefined, 'expected ' + this.describe(this.actual) + ' to be undefined');
    }

    get exist() {
        return this.check(
            this.actual !== null && this.actual !== undefined,
            'expected ' + this.describe(this.actual) + ' to exist',
        );
    }

    get ok() {
        return this.check(Boolean(this.actual), 'expected ' + this.describe(this.actual) + ' to be truthy');
    }

    get empty() {
        const value = this.actual;
        const isEmpty = value === '' || value === null || value === undefined
            || (Array.isArray(value) && value.length === 0)
            || (typeof value === 'object' && value !== null && Object.keys(value).length === 0);
        return this.check(isEmpty, 'expected ' + this.describe(value) + ' to be empty');
    }

    include(expected) {
        const value = this.actual;
        let found = false;
        if (typeof value === 'string') found = value.includes(String(expected));
        else if (Array.isArray(value)) found = value.some((item) => isDeepStrictEqual(item, expected));
        else if (value !== null && typeof value === 'object') found = Object.prototype.hasOwnProperty.call(value, expected);
        return this.check(found, 'expected ' + this.describe(value) + ' to include ' + this.describe(expected));
    }

    property(name, expected) {
        const value = this.actual;
        const has = value !== null && typeof value === 'object'
            && Object.prototype.hasOwnProperty.call(value, name);
        if (arguments.length > 1) {
            return this.check(
                has && isDeepStrictEqual(value[name], expected),
                'expected ' + this.describe(value) + ' to have property ' + JSON.stringify(name)
                    + ' equal to ' + this.describe(expected),
            );
        }
        return this.check(has, 'expected ' + this.describe(value) + ' to have property ' + JSON.stringify(name));
    }

    above(expected) {
        return this.check(this.actual > expected, 'expected ' + this.describe(this.actual) + ' to be above ' + expected);
    }

    below(expected) {
        return this.check(this.actual < expected, 'expected ' + this.describe(this.actual) + ' to be below ' + expected);
    }

    least(expected) {
        return this.check(this.actual >= expected, 'expected ' + this.describe(this.actual) + ' to be at least ' + expected);
    }

    most(expected) {
        return this.check(this.actual <= expected, 'expected ' + this.describe(this.actual) + ' to be at most ' + expected);
    }

    lengthOf(expected) {
        const value = this.actual;
        const length = value != null && typeof value.length === 'number' ? value.length : undefined;
        return this.check(length === expected, 'expected ' + this.describe(value) + ' to have a length of ' + expected);
    }

    match(regex) {
        return this.check(
            typeof this.actual === 'string' && regex.test(this.actual),
            'expected ' + this.describe(this.actual) + ' to match ' + String(regex),
        );
    }

    a(type) {
        return this.check(typeof this.actual === type, 'expected ' + this.describe(this.actual) + ' to be a ' + type);
    }

    an(type) {
        return this.a(type);
    }
}

/* ------------------------------------------------------------------ */
/* pm object                                                           */
/* ------------------------------------------------------------------ */

function makeScope(store) {
    const scope = {
        get(key) {
            return Object.prototype.hasOwnProperty.call(store, key) ? store[key] : undefined;
        },
        set(key, value) {
            // Postman stores variable values as strings.
            if (value === null || value === undefined) store[String(key)] = '';
            else if (typeof value === 'object') store[String(key)] = JSON.stringify(value);
            else store[String(key)] = String(value);
        },
        unset(key) {
            delete store[String(key)];
        },
        has(key) {
            return Object.prototype.hasOwnProperty.call(store, key);
        },
        toObject() {
            return Object.assign({}, store);
        },
    };
    return Object.freeze(scope);
}

function buildPm(input, logs, registeredTests) {
    const variables = input.variables || {};
    const envStore = Object.assign({}, variables.environment || {});
    const collectionStore = Object.assign({}, variables.collectionVariables || {});
    const globalsStore = Object.assign({}, variables.globals || {});
    const dataStore = Object.assign({}, variables.data || {});

    const request = input.request || {};
    const response = input.response || null;

    const pm = {
        environment: makeScope(envStore),
        collectionVariables: makeScope(collectionStore),
        globals: makeScope(globalsStore),

        variables: Object.freeze({
            get(key) {
                for (const store of [dataStore, envStore, collectionStore, globalsStore]) {
                    if (Object.prototype.hasOwnProperty.call(store, key)) return store[key];
                }
                return undefined;
            },
            has(key) {
                return [dataStore, envStore, collectionStore, globalsStore]
                    .some((store) => Object.prototype.hasOwnProperty.call(store, key));
            },
        }),

        test(name, fn) {
            if (typeof fn !== 'function') {
                throw new TypeError('pm.test("' + String(name) + '") requires a function.');
            }
            registeredTests.push({ name: String(name), fn });
        },

        expect(actual) {
            return new Assertion(actual);
        },

        request: Object.freeze({
            method: request.method || 'GET',
            url: request.url || '',
            headers: headerBag(request.headers || {}),
            body: request.body !== undefined ? request.body : null,
        }),
    };

    // pm.response only exists for post-response (test) scripts.
    if (response) {
        const responseText = () => (typeof response.body === 'string' ? response.body : stringify(response.body));
        const responseJson = () => JSON.parse(responseText());
        const headerBagForResponse = headerBag(response.headers || {});

        pm.response = Object.freeze({
            code: response.code,
            status: response.status || '',
            headers: headerBagForResponse,
            text() {
                return responseText();
            },
            json() {
                return responseJson();
            },
            // Postman idiom: pm.response.to.have.status(200) etc.
            // The UI snippet picker inserts exactly this form.
            to: Object.freeze({
                have: Object.freeze({
                    status(expected) {
                        if (response.code !== expected) {
                            throw new Error('expected response to have status ' + expected + ' but got ' + response.code);
                        }
                    },
                    body(expected) {
                        const text = responseText();
                        if (expected === undefined) {
                            if (!text) throw new Error('expected response to have a body');
                        } else if (!text.includes(String(expected))) {
                            throw new Error('expected response body to include ' + JSON.stringify(String(expected)));
                        }
                    },
                    header(name, value) {
                        const actual = headerBagForResponse.get(name);
                        if (actual === undefined) throw new Error('expected response to have header ' + name);
                        if (value !== undefined && actual !== String(value)) {
                            throw new Error('expected header ' + name + ' to be ' + value + ' but got ' + actual);
                        }
                    },
                    jsonBody(expected) {
                        const actual = responseJson();
                        if (JSON.stringify(actual) !== JSON.stringify(expected)) {
                            throw new Error('expected response jsonBody to equal ' + JSON.stringify(expected));
                        }
                    },
                }),
                be: Object.freeze({
                    ok() {
                        if (response.code < 200 || response.code >= 300) {
                            throw new Error('expected response to be ok but got status ' + response.code);
                        }
                    },
                    success() {
                        if (response.code < 200 || response.code >= 300) {
                            throw new Error('expected response to be successful but got status ' + response.code);
                        }
                    },
                }),
            }),
        });
    }

    return {
        pm: Object.freeze(pm),
        stores: { environment: envStore, collectionVariables: collectionStore, globals: globalsStore },
    };
}

function makeConsole(logs) {
    const capture = (level, args) => {
        if (logs.length >= MAX_LOG_ENTRIES) return;
        const line = args.map((arg) => stringify(arg)).join(' ');
        logs.push(truncate(line, MAX_LOG_CHARS));
    };
    return Object.freeze({
        log: (...args) => capture('log', args),
        info: (...args) => capture('info', args),
        warn: (...args) => capture('warn', args),
        error: (...args) => capture('error', args),
        debug: (...args) => capture('debug', args),
    });
}

/* ------------------------------------------------------------------ */
/* Run                                                                 */
/* ------------------------------------------------------------------ */

async function main() {
    let input;
    try {
        input = JSON.parse(fs.readFileSync(0, 'utf8') || '{}');
    } catch (e) {
        return { tests: [], logs: [], variables: { environment: {}, collectionVariables: {}, globals: {} }, error: 'Invalid JSON input to pm-runner: ' + e.message };
    }

    const logs = [];
    const registeredTests = [];
    const script = typeof input.script === 'string' ? input.script : '';

    const { pm, stores } = buildPm(input, logs, registeredTests);
    const output = () => ({
        tests: [],
        logs,
        variables: {
            environment: Object.assign({}, stores.environment),
            collectionVariables: Object.assign({}, stores.collectionVariables),
            globals: Object.assign({}, stores.globals),
        },
        error: null,
    });

    if (script.trim() === '') {
        return output();
    }

    const sandbox = { pm, console: makeConsole(logs) };
    const context = vm.createContext(sandbox);

    // Top-level await / return work: the user code runs as an async IIFE.
    const wrapped = '(async () => {\n' + script + '\n})();';

    let result;
    try {
        result = vm.runInContext(wrapped, context, { timeout: VM_TIMEOUT_MS, filename: 'pm-script.js' });
    } catch (e) {
        const out = output();
        out.error = e && e.message ? e.message : String(e);
        return out;
    }

    if (result && typeof result.then === 'function') {
        try {
            await withTimeout(result, VM_TIMEOUT_MS);
        } catch (e) {
            result.catch(() => {});
            const out = output();
            out.error = e && e.message ? e.message : String(e);
            return out;
        }
    }

    // Run the registered tests in order, each with its own timeout.
    const tests = [];
    for (const { name, fn } of registeredTests.slice(0, MAX_TESTS)) {
        try {
            const value = fn();
            if (value && typeof value.then === 'function') {
                await withTimeout(value, VM_TIMEOUT_MS);
            }
            tests.push({ name, passed: true, error: null });
        } catch (e) {
            tests.push({ name, passed: false, error: e && e.message ? e.message : String(e) });
        }
    }

    const out = output();
    out.tests = tests;
    return out;
}

main()
    .then((result) => {
        process.stdout.write(JSON.stringify(result));
    })
    .catch((e) => {
        process.stdout.write(JSON.stringify({
            tests: [],
            logs: [],
            variables: { environment: {}, collectionVariables: {}, globals: {} },
            error: 'pm-runner crashed: ' + (e && e.message ? e.message : String(e)),
        }));
    });
