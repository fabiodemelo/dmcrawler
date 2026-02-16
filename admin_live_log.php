<?php
declare(strict_types=1);

/**
 * Admin live log viewer (AJAX polling)
 * Requires: auth_check.php to protect admin pages
 */
require_once __DIR__ . '/auth_check.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Live Crawler Log</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        /* match the rest of the admin pages (Bootstrap container spacing) */
        #logBox {
            height: 70vh;
            white-space: pre-wrap;
            overflow: auto;
            background: #0b1220;
            color: #d9f99d;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 10px;
            font: 12px/1.4 ui-monospace, Menlo, Consolas, monospace;
        }
        .muted { color: #64748b; }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="container">
    <div class="d-flex align-items-center justify-content-between mb-3 mt-3">
        <div>
            <h1 class="h4 mb-1">Live Crawler Log</h1>
            <div class="muted" id="statusLine">Connecting…</div>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-primary" id="btnPause">Pause</button>
            <button class="btn btn-sm btn-outline-secondary" id="btnResume" disabled>Resume</button>
            <button class="btn btn-sm btn-outline-danger" id="btnClear">Clear View</button>
        </div>
    </div>

    <div class="mb-2 d-flex gap-3 align-items-center">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="chkAutoscroll" checked>
            <label class="form-check-label" for="chkAutoscroll">Auto-scroll</label>
        </div>
        <div class="muted nowrap">Polling: <span id="pollMs">1000</span>ms</div>
    </div>

    <div id="logBox"></div>

    <div class="text-muted mt-2 small">
        Tip: If this stays empty, check that <code>crawler.log</code> is being written and that <code>log_api.php</code> returns JSON when opened directly.
    </div>
</div>

    <script>
        (function () {
            var logBox = document.getElementById('logBox');
            var statusLine = document.getElementById('statusLine');
            var chkAutoscroll = document.getElementById('chkAutoscroll');

            var btnPause = document.getElementById('btnPause');
            var btnResume = document.getElementById('btnResume');
            var btnClear = document.getElementById('btnClear');

            var offset = 0;
            var paused = false;
            var timer = null;
            var pollIntervalMs = 1000;

            function append(text) {
                if (!text) return;
                var atBottom = (logBox.scrollTop + logBox.clientHeight >= logBox.scrollHeight - 5);
                logBox.textContent += text;
                if (chkAutoscroll.checked && atBottom) {
                    logBox.scrollTop = logBox.scrollHeight;
                }
            }

            function setStatus(s) {
                statusLine.textContent = s;
            }

            function poll() {
                if (paused) return;

                fetch('log_api.php?offset=' + encodeURIComponent(String(offset)), {
                    credentials: 'same-origin',
                    cache: 'no-store'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || data.ok !== true) {
                            setStatus('Error: ' + (data && data.error ? data.error : 'unknown'));
                            return;
                        }
                        offset = data.nextOffset || 0;
                        append(data.chunk || '');
                        setStatus(
                            'Crawler: ' + (data.crawlerRunning ? 'RUNNING' : 'IDLE') +
                            ' | Log size: ' + data.fileSize +
                            ' | Offset: ' + offset +
                            (data.serverTime ? (' | Server: ' + data.serverTime) : '')
                        );
                    })
                    .catch(function (e) {
                        setStatus('Connection error: ' + (e && e.message ? e.message : e));
                    });
            }

            function start() {
                if (timer) clearInterval(timer);
                timer = setInterval(poll, pollIntervalMs);
                poll();
            }

            btnPause.addEventListener('click', function () {
                paused = true;
                btnPause.disabled = true;
                btnResume.disabled = false;
                setStatus('Paused');
            });

            btnResume.addEventListener('click', function () {
                paused = false;
                btnPause.disabled = false;
                btnResume.disabled = true;
                poll();
            });

            btnClear.addEventListener('click', function () {
                logBox.textContent = '';
            });

            start();
        })();
    </script>
    </body>
    </html>