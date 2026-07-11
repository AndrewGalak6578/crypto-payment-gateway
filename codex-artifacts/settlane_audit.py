import base64
import json
import os
import subprocess
import time
import urllib.error
import urllib.request
from datetime import datetime


BASE = "https://settlane.tech"
EMAIL = "mail@gsht.com"
PASSWORD = "password"
OUT = os.path.abspath("codex-artifacts")
PORT = 4444


def http(method, url, payload=None, timeout=30):
    data = None
    headers = {"Content-Type": "application/json"}
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read()
            return json.loads(body.decode("utf-8")) if body else {}
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", "replace")
        raise RuntimeError(f"{method} {url} -> {exc.code}: {body[:500]}")


class Browser:
    def __init__(self):
        self.proc = None
        self.session = None

    def start(self):
        log_path = os.path.join(OUT, "geckodriver.log")
        log = open(log_path, "w", encoding="utf-8")
        self.proc = subprocess.Popen(
            ["geckodriver", "--port", str(PORT)],
            stdout=log,
            stderr=subprocess.STDOUT,
        )
        deadline = time.time() + 10
        while time.time() < deadline:
            try:
                urllib.request.urlopen(f"http://127.0.0.1:{PORT}/status", timeout=1).read()
                break
            except Exception:
                time.sleep(0.2)
        payload = {
            "capabilities": {
                "alwaysMatch": {
                    "browserName": "firefox",
                    "acceptInsecureCerts": True,
                    "moz:firefoxOptions": {"args": ["-headless"]},
                }
            }
        }
        res = http("POST", f"http://127.0.0.1:{PORT}/session", payload)
        self.session = res["value"]["sessionId"]

    def stop(self):
        if self.session:
            try:
                http("DELETE", f"http://127.0.0.1:{PORT}/session/{self.session}")
            except Exception:
                pass
        if self.proc:
            self.proc.terminate()
            try:
                self.proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.proc.kill()

    def cmd(self, method, path, payload=None):
        return http(method, f"http://127.0.0.1:{PORT}/session/{self.session}{path}", payload)

    def rect(self, width, height):
        self.cmd("POST", "/window/rect", {"x": 0, "y": 0, "width": width, "height": height})
        time.sleep(0.3)

    def url(self, url):
        self.cmd("POST", "/url", {"url": url})
        time.sleep(2.0)
        self.wait_idle()

    def wait_idle(self, seconds=8):
        deadline = time.time() + seconds
        while time.time() < deadline:
            state = self.js("return document.readyState")
            if state == "complete":
                time.sleep(0.7)
                return
            time.sleep(0.25)

    def js(self, script, *args):
        return self.cmd("POST", "/execute/sync", {"script": script, "args": list(args)})["value"]

    def async_js(self, script, *args):
        return self.cmd("POST", "/execute/async", {"script": script, "args": list(args)})["value"]

    def screenshot(self, name):
        raw = self.cmd("GET", "/screenshot")["value"]
        path = os.path.join(OUT, name)
        with open(path, "wb") as f:
            f.write(base64.b64decode(raw))
        return path

    def state(self):
        return self.js(
            r"""
            return {
              url: location.href,
              title: document.title,
              viewport: {w: innerWidth, h: innerHeight},
              scroll: {w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight},
              text: document.body.innerText.slice(0, 5000),
              links: Array.from(document.querySelectorAll('a')).slice(0, 80).map(a => ({text:a.innerText.trim(), href:a.href})),
              buttons: Array.from(document.querySelectorAll('button')).slice(0, 100).map(b => ({text:b.innerText.trim(), disabled:b.disabled})),
              inputs: Array.from(document.querySelectorAll('input, textarea, select')).slice(0, 100).map(el => ({
                tag: el.tagName, type: el.type || '', placeholder: el.placeholder || '', value: el.type === 'password' ? '***' : el.value,
                required: el.required, disabled: el.disabled, readonly: el.readOnly,
                label: (el.closest('label')?.innerText || document.querySelector(`label[for="${el.id}"]`)?.innerText || '').slice(0, 160)
              })),
              horizontalOverflow: document.documentElement.scrollWidth > innerWidth + 2,
              outside: Array.from(document.body.querySelectorAll('*')).filter(el => {
                const r = el.getBoundingClientRect();
                return r.width > 0 && (r.right > innerWidth + 2 || r.left < -2);
              }).slice(0, 20).map(el => ({tag:el.tagName, cls:el.className, text:el.innerText?.slice(0,80), rect:el.getBoundingClientRect().toJSON()})),
              errors: window.__codexErrors || []
            };
            """
        )

    def install_error_hooks(self):
        self.js(
            r"""
            window.__codexErrors = [];
            window.addEventListener('error', e => window.__codexErrors.push({type:'error', message:e.message, source:e.filename, line:e.lineno}));
            window.addEventListener('unhandledrejection', e => window.__codexErrors.push({type:'unhandledrejection', message:String(e.reason && (e.reason.message || e.reason))}));
            return true;
            """
        )

    def fill_login(self, email, password):
        return self.js(
            r"""
            const [email, password] = arguments;
            const set = (el, val) => {
              el.focus(); el.value = val;
              el.dispatchEvent(new Event('input', {bubbles:true}));
              el.dispatchEvent(new Event('change', {bubbles:true}));
            };
            set(document.querySelector('input[type=email]'), email);
            set(document.querySelector('input[type=password]'), password);
            document.querySelector('button[type=submit]').click();
            return true;
            """,
            email,
            password,
        )

    def fetch_json(self, path, options=None):
        return self.async_js(
            r"""
            const [path, options, done] = arguments;
            fetch(path, Object.assign({headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}, credentials:'include'}, options || {}))
              .then(async r => done({ok:r.ok, status:r.status, url:r.url, body: await r.text()}))
              .catch(e => done({ok:false, error:String(e)}));
            """,
            path,
            options or {},
        )


def save_json(name, data):
    path = os.path.join(OUT, name)
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    return path


def main():
    os.makedirs(OUT, exist_ok=True)
    b = Browser()
    results = {"started_at": datetime.utcnow().isoformat() + "Z", "pages": [], "actions": [], "api": []}
    try:
        b.start()
        for width in (390, 768, 1024, 1440):
            b.rect(width, 1000)
            b.url(BASE + "/")
            b.install_error_hooks()
            shot = b.screenshot(f"home-{width}.png")
            st = b.state()
            st["screenshot"] = shot
            results["pages"].append({"name": f"home-{width}", **st})

        for path in ("/architecture", "/merchant/login", "/merchant/register", "/admin"):
            b.rect(1440, 1000)
            b.url(BASE + path)
            b.install_error_hooks()
            shot = b.screenshot(path.strip("/").replace("/", "-") + "-1440.png")
            results["pages"].append({"name": path, "screenshot": shot, **b.state()})

        b.url(BASE + "/merchant/login")
        b.install_error_hooks()
        b.fill_login("wrong@example.com", "wrong-password")
        time.sleep(2)
        results["actions"].append({"name": "invalid_login", **b.state(), "screenshot": b.screenshot("merchant-invalid-login.png")})

        b.fill_login(EMAIL, PASSWORD)
        time.sleep(4)
        results["actions"].append({"name": "valid_login", **b.state(), "screenshot": b.screenshot("merchant-after-login.png")})

        merchant_paths = [
            "/merchant/dashboard",
            "/merchant/payments",
            "/merchant/payments/new",
            "/merchant/developers",
            "/merchant/settlements",
            "/merchant/team",
            "/merchant/settings",
        ]
        for path in merchant_paths:
            for width in ((390, 1000), (768, 1000), (1440, 1000)) if path in ("/merchant/dashboard", "/merchant/payments/new", "/merchant/team", "/merchant/settings") else ((1440, 1000),):
                b.rect(*width)
                b.url(BASE + path)
                b.install_error_hooks()
                name = (path.strip("/") or "merchant").replace("/", "-") + f"-{width[0]}.png"
                results["pages"].append({"name": f"{path}-{width[0]}", "screenshot": b.screenshot(name), **b.state()})

        b.rect(1440, 1000)
        b.url(BASE + "/merchant/payments/new")
        b.install_error_hooks()
        b.js(
            r"""
            const set = (sel, val) => {
              const el = document.querySelector(sel);
              el.focus(); el.value = val;
              el.dispatchEvent(new Event('input', {bubbles:true}));
              el.dispatchEvent(new Event('change', {bubbles:true}));
            };
            set('input[type=number]', '0');
            const ta = document.querySelector('textarea');
            if (ta) { ta.value = '{"broken":'; ta.dispatchEvent(new Event('input', {bubbles:true})); }
            document.querySelector('button[type=submit]').click();
            return true;
            """
        )
        time.sleep(1.5)
        results["actions"].append({"name": "create_payment_invalid_metadata", **b.state(), "screenshot": b.screenshot("payment-invalid-metadata.png")})

        b.js(
            r"""
            const setByLabelText = (needle, val) => {
              const labels = Array.from(document.querySelectorAll('label'));
              const label = labels.find(l => l.innerText.includes(needle));
              const el = label && label.querySelector('input, textarea');
              if (!el) return false;
              el.focus(); el.value = val;
              el.dispatchEvent(new Event('input', {bubbles:true}));
              el.dispatchEvent(new Event('change', {bubbles:true}));
              return true;
            };
            setByLabelText('Amount USD', '10.00');
            setByLabelText('External ID', 'codex-qa-' + Date.now());
            const ta = document.querySelector('textarea');
            if (ta) { ta.value = '{"source":"codex-audit"}'; ta.dispatchEvent(new Event('input', {bubbles:true})); }
            document.querySelector('button[type=submit]').click();
            return true;
            """
        )
        time.sleep(5)
        results["actions"].append({"name": "create_payment_valid", **b.state(), "screenshot": b.screenshot("payment-created.png")})

        for path in ["/api/auth/merchant/me", "/api/merchant/dashboard", "/api/merchant/settings", "/api/merchant/invoices?per_page=5", "/api/merchant/api-keys", "/api/merchant/webhook-settings", "/api/merchant/merchant-users?per_page=5"]:
            results["api"].append({"path": path, "result": b.fetch_json(path)})

        save_json("settlane-audit-raw.json", results)
    finally:
        b.stop()


if __name__ == "__main__":
    main()
