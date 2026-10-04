"""اختبار HTTP محلي ببيانات اختبار؛ لا يجري أي اتصال بRunway."""
import os, hashlib, http.cookiejar, json, re, shutil, socket, subprocess, tempfile, time
from pathlib import Path
from urllib.request import Request, build_opener, HTTPCookieProcessor
from urllib.error import HTTPError

source = Path(__file__).resolve().parents[1]
count = 0

def check(value):
    global count
    assert value
    count += 1

with tempfile.TemporaryDirectory(prefix="mask-http-") as temp:
    root = Path(temp) / "app"
    shutil.copytree(source, root, ignore=shutil.ignore_patterns(".git", "config.local.php", "*.mp4", "*.json", "*.part", "*.sdk.lock", "node_modules", ".agents", ".claude"))
    sessions = Path(temp) / "sessions"
    sessions.mkdir()
    config = root / "config.local.php"
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        sdk_port = sock.getsockname()[1]
    config.write_text(f"<?php return ['api_key'=>'TEST_ONLY_NEVER_SENT', 'sdk_port'=>{sdk_port}];", encoding="utf-8")
    fixture_server = subprocess.Popen(["node", str(source / "tests" / "fixture-sdk-service.mjs"), str(root / "storage"), str(sdk_port)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    log = open(Path(temp) / "php.log", "w+")
    server = subprocess.Popen(["php", "-d", f"session.save_path={sessions}", "-S", f"127.0.0.1:{port}", "-t", str(root / "public")], stdout=log, stderr=log, env={key: value for key, value in os.environ.items() if key not in ["RUNWAYML_API_SECRET", "RUNWAY_API_KEY"]})
    base = f"http://127.0.0.1:{port}/"
    jar = http.cookiejar.CookieJar()
    browser = build_opener(HTTPCookieProcessor(jar))
    def request(path, data=None, headers=None, method=None, client=None):
        req = Request(base + path, data=data, headers=headers or {}, method=method)
        try:
            res = (client or browser).open(req, timeout=5)
        except HTTPError as error:
            res = error
        return res.status, res.headers, res.read()
    try:
        for _ in range(50):
            try:
                code, headers, page = request("index.php")
                break
            except OSError:
                time.sleep(.1)
        else:
            raise RuntimeError("PHP server did not start")
        check(code == 200 and b'dir="rtl"' in page)
        csrf = re.search(rb'name="csrf-token" content="([a-f0-9]+)"', page).group(1).decode()
        cookie = next(c for c in jar if c.name == "mask_video_session")
        owner = hashlib.sha256(cookie.value.encode()).hexdigest()
        local_id = "a" * 32
        task = dict(id=local_id, owner=owner, prompt="عطر", model="gen4.5", duration=5, ratio="720:1280", status="PENDING", provider_task_id="test-provider-id", created_at="2026-10-04T06:00:00Z", last_checked=int(time.time())+300)
        task_path = root / "storage" / "tasks" / (local_id + ".json")
        task_path.write_text(json.dumps(task), encoding="utf-8")
        php = "ini_set('session.use_strict_mode','1'); session_id($argv[1]); session_start(); $_SESSION['tasks']=[$argv[2]]; session_write_close();"
        subprocess.run(["php", "-d", f"session.save_path={sessions}", "-r", php, cookie.value, local_id], check=True)
        data = json.dumps(dict(prompt="عطر", request_id=local_id)).encode()
        api_headers = {"Content-Type":"application/json", "X-CSRF-Token":csrf}
        code, _, body = request("generate.php", data, api_headers)
        check(code == 200 and json.loads(body)["status"] == "PENDING")
        code, _, body = request("status.php?id=" + local_id)
        check(code == 200 and json.loads(body)["status"] == "NEEDS_REVIEW")
        check("output" not in json.loads(body) and "owner" not in json.loads(body))
        check(request("resume.php", json.dumps({"id":local_id}).encode(), {"Content-Type":"application/json"})[0] == 403)
        check(request("resume.php", json.dumps({"id":local_id}).encode(), api_headers, client=build_opener())[0] == 403)
        check(request("generate.php", data, {"Content-Type":"application/json"})[0] == 403)
        check(request("generate.php")[0] == 405)
        check(request("status.php?id="+local_id, client=build_opener())[0] == 404)
        check(request("status.php?id=../config")[0] == 400)
        mismatch = json.dumps(dict(prompt="وصف آخر", request_id=local_id)).encode()
        check(request("generate.php", mismatch, api_headers)[0] == 409)
        check(request("generate.php", json.dumps(dict(prompt="عطر", request_id="b"*32)).encode(), api_headers)[0] == 409)
        check(request("index.php", headers={"Host":"attacker.example"})[0] == 403)
        check(request("../config.local.php")[0] == 404)
        code, _, _ = request("resume.php", json.dumps({"id":local_id}).encode(), api_headers)
        check(code == 202)
        for _ in range(100):
            if json.loads(task_path.read_text())["status"] == "READY": break
            time.sleep(.02)
        check(json.loads(task_path.read_text())["status"] == "READY")
        task["status"] = "READY"
        task_path.write_text(json.dumps(task), encoding="utf-8")
        # اختبار نطاقات البايت بملف صغير اصطناعي؛ ليس فيديو AI.
        sample = b"\x00\x00\x00\x18ftypisom" + b"x"*100
        (root / "storage" / "videos" / (local_id + ".mp4")).write_bytes(sample)
        code, headers, body = request("media.php?id="+local_id, headers={"Range":"bytes=4-7"})
        check(code == 206 and body == b"ftyp" and headers["Content-Range"] == f"bytes 4-7/{len(sample)}")
        code, headers, body = request("media.php?id="+local_id+"&download=1")
        check(code == 200 and body == sample and "attachment" in headers["Content-Disposition"])
        check(request("media.php?id="+local_id, headers={"Range":"bytes=999-"})[0] == 416)
        check(request("media.php?id="+local_id, client=build_opener())[0] == 404)
        check(request("media.php?id="+local_id, method="HEAD")[2] == b"")
        next_data = json.dumps(dict(prompt="وصف جديد", request_id="b"*32)).encode()
        code, _, _ = request("generate.php", next_data, api_headers)
        check(code == 202)
        next_path = root / "storage" / "tasks" / (("b"*32) + ".json")
        for _ in range(100):
            if json.loads(next_path.read_text())["status"] == "READY": break
            time.sleep(.02)
        check(json.loads(next_path.read_text())["status"] == "READY")
        check("TEST_ONLY" not in next_path.read_text())
        code, _, body = request("generate.php", next_data, api_headers)
        check(code == 200 and json.loads(body)["status"] == "READY")
        task["status"] = "SUBMISSION_UNKNOWN"
        task_path.write_text(json.dumps(task), encoding="utf-8")
        subprocess.run(["php", str(root / "tools" / "recover.php"), local_id, "confirmed-task-123"], check=True, stdout=subprocess.DEVNULL)
        check(json.loads(task_path.read_text())["provider_task_id"] == "confirmed-task-123")
        config.write_text("<?php return ['api_key'=>''];", encoding="utf-8")
        check('خطوة الإعداد المتبقية'.encode() in request("index.php")[2])
        check(request("generate.php", data, api_headers)[0] == 503)
    finally:
        server.terminate()
        server.wait(timeout=5)
        fixture_server.terminate()
        fixture_server.wait(timeout=5)
        log.close()
print(f"PASS: {count} HTTP checks; no paid API requests.")
