(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        const button = document.getElementById('btn-dictate');
        const input = document.getElementById('chat-input');
        const status = document.getElementById('ai-response-status');
        const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!button || !input || !Recognition || input.disabled) return;
        let recognition = null;
        button.hidden = false;
        button.addEventListener('click', function () {
            if (recognition) { recognition.stop(); return; }
            if (document.getElementById('btn-send')?.disabled) return;
            const session = new Recognition();
            recognition = session;
            session.lang = document.documentElement.lang || 'en-IN';
            session.continuous = false;
            session.interimResults = false;
            session.onstart = function () {
                button.textContent = 'Stop dictation';
                button.setAttribute('aria-label', 'Stop voice input');
                button.setAttribute('aria-pressed', 'true');
                status.textContent = 'Browser speech service is listening. Review the text before sending.';
            };
            session.onresult = function (event) {
                const text = Array.from(event.results).filter(result => result.isFinal).map(result => result[0].transcript).join(' ');
                input.value = [input.value.trim(), text.trim()].filter(Boolean).join(' ').slice(0, input.maxLength);
                input.dispatchEvent(new Event('input', {bubbles: true}));
                status.textContent = 'Dictation added. Review the text before sending.';
            };
            session.onerror = function (event) {
                status.textContent = event.error === 'not-allowed' ? 'Microphone access denied. Type your message instead.'
                    : 'Dictation unavailable. Type your message or use Record.';
            };
            session.onend = function () {
                recognition = null;
                button.textContent = 'Dictate';
                button.setAttribute('aria-label', 'Start voice input');
                button.setAttribute('aria-pressed', 'false');
            };
            try { session.start(); }
            catch (_) { session.onend(); status.textContent = 'Dictation unavailable. Type your message instead.'; }
        });
        document.getElementById('chat-form')?.addEventListener('submit', function () { recognition?.abort(); });
        document.addEventListener('visibilitychange', function () { if (document.hidden) recognition?.abort(); });
        window.addEventListener('pagehide', function () { recognition?.abort(); });
    });
})();
