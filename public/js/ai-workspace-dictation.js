(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        const button = document.getElementById('btn-dictate');
        const input = document.getElementById('chat-input');
        const status = document.getElementById('ai-response-status');
        const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!button || !input || !Recognition || input.disabled) return;
        let recognition = null;
        let baseInputText = '';
        let accumulatedFinal = '';
        button.hidden = false;

        function stopDictation() {
            if (recognition) {
                try { recognition.stop(); } catch (_) {}
            }
        }

        button.addEventListener('click', function () {
            if (recognition) {
                stopDictation();
                return;
            }
            if (document.getElementById('btn-send')?.disabled) return;

            const session = new Recognition();
            recognition = session;

            // Intelligent language resolution: prefer browser's current active speech locale
            const browserLocale = (navigator.languages && navigator.languages[0]) || navigator.language || 'en-US';
            const pageLocale = (document.documentElement.lang && document.documentElement.lang.trim()) || '';
            session.lang = pageLocale || browserLocale;
            session.continuous = true;
            session.interimResults = true;

            baseInputText = input.value.trim();
            accumulatedFinal = '';

            session.onstart = function () {
                button.textContent = 'Stop dictation';
                button.classList.add('btn-danger', 'active');
                button.classList.remove('btn-outline-secondary');
                button.setAttribute('aria-label', 'Stop voice input');
                button.setAttribute('aria-pressed', 'true');
                if (status) {
                    status.textContent = 'Browser speech service is listening. Review the text before sending.';
                }
            };

            session.onresult = function (event) {
                let currentInterim = '';
                const results = event.results || [];
                const startIndex = typeof event.resultIndex === 'number' ? event.resultIndex : 0;

                for (let i = 0; i < results.length; ++i) {
                    const res = results[i];
                    if (!res || !res[0]) continue;
                    if (res.isFinal) {
                        if (i >= startIndex) {
                            accumulatedFinal += (accumulatedFinal ? ' ' : '') + res[0].transcript.trim();
                        }
                    } else {
                        currentInterim += (currentInterim ? ' ' : '') + res[0].transcript.trim();
                    }
                }

                const parts = [baseInputText, accumulatedFinal, currentInterim].filter(Boolean);
                input.value = parts.join(' ').slice(0, input.maxLength);
                input.dispatchEvent(new Event('input', { bubbles: true }));

                if (status) {
                    status.textContent = currentInterim
                        ? 'Listening… speak clearly into your microphone.'
                        : 'Dictation added. Review the text before sending.';
                }
            };

            session.onerror = function (event) {
                const err = event && event.error;
                if (err === 'not-allowed') {
                    if (status) status.textContent = 'Microphone access denied. Type your message instead.';
                } else if (err === 'no-speech') {
                    if (status) status.textContent = 'No speech detected. Speak clearly into your microphone or try again.';
                } else if (err === 'network') {
                    if (status) status.textContent = 'Speech service network error. Type your message or use Record.';
                } else if (err === 'language-not-supported') {
                    if (status) status.textContent = 'Speech language not supported. You can use Record or type instead.';
                } else if (err !== 'aborted') {
                    if (status) status.textContent = 'Dictation unavailable. Type your message or use Record.';
                }
            };

            session.onend = function () {
                recognition = null;
                button.textContent = 'Dictate';
                button.classList.remove('btn-danger', 'active');
                button.classList.add('btn-outline-secondary');
                button.setAttribute('aria-label', 'Start voice input');
                button.setAttribute('aria-pressed', 'false');

                // Final sync of input text
                const finalParts = [baseInputText, accumulatedFinal].filter(Boolean);
                if (finalParts.length > 0) {
                    input.value = finalParts.join(' ').slice(0, input.maxLength);
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            };

            try {
                session.start();
            } catch (_) {
                session.onend();
                if (status) status.textContent = 'Dictation unavailable. Type your message instead.';
            }
        });

        document.getElementById('chat-form')?.addEventListener('submit', function () {
            stopDictation();
        });
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) stopDictation();
        });
        window.addEventListener('pagehide', function () {
            stopDictation();
        });
    });
})();
