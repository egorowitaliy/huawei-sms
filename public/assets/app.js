const configuredSessionLimit = Number(window.APP_SESSION_LIFETIME);
const SESSION_LIMIT = Number.isFinite(configuredSessionLimit)
    ? Math.max(0, configuredSessionLimit)
    : 900;

let idle = SESSION_LIMIT;
let warned = false;
let lastPingAt = 0;

function resetIdleTimer() {
    if (SESSION_LIMIT <= 0) {
        return;
    }

    idle = SESSION_LIMIT;
    warned = false;

    const box = document.querySelector('.idle-warning');

    if (box) {
        box.remove();
    }

    pingSession();
}

function pingSession() {
    const now = Date.now();

    if (now - lastPingAt < 30000) {
        return;
    }

    lastPingAt = now;

    fetch('/?ping=1', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
    }).catch(() => {});
}

function updateSmsCounter() {
    const textarea = document.querySelector('[data-sms-text]');
    const counter = document.querySelector('[data-sms-counter]');

    if (!textarea || !counter) {
        return;
    }

    const text = textarea.value;
    const chars = text.length;
    const isUnicode = /[^\x00-\x7F]/.test(text);

    let smsCount = 0;

    if (chars > 0) {
        if (isUnicode) {
            smsCount = chars <= 70 ? 1 : Math.ceil(chars / 67);
        } else {
            smsCount = chars <= 160 ? 1 : Math.ceil(chars / 153);
        }
    }

    counter.textContent = 'Символов: ' + chars + ' · SMS: ' + smsCount;
}

function initNoticeAutoHide() {
    document.querySelectorAll('.notice').forEach((notice) => {
        setTimeout(() => {
            notice.style.opacity = '0';

            setTimeout(() => {
                notice.remove();
            }, 250);
        }, 5000);
    });
}

if (SESSION_LIMIT > 0) {
    setInterval(() => {
        idle--;

        if (idle <= 10 && !warned) {
            warned = true;

            const box = document.createElement('div');
            box.className = 'idle-warning';
            box.textContent = 'Сессия завершится через ' + idle + ' сек.';
            document.body.appendChild(box);
        }

        const box = document.querySelector('.idle-warning');

        if (box) {
            box.textContent = 'Сессия завершится через ' + Math.max(0, idle) + ' сек.';
        }

        if (idle <= 0) {
            location.href = '/?login=1&timeout=1';
        }
    }, 1000);

    ['click', 'keydown', 'mousemove', 'touchstart'].forEach((eventName) => {
        document.addEventListener(eventName, resetIdleTimer, { passive: true });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    updateSmsCounter();
    initNoticeAutoHide();
    pingSession();

    const textarea = document.querySelector('[data-sms-text]');

    if (textarea) {
        textarea.addEventListener('input', updateSmsCounter);
    }
});
