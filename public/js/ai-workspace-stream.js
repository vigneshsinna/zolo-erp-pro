(function (window) {
    'use strict';
    window.ZoloAiStream = {
        async request(url, headers, payload, onDelta) {
            const response = await fetch(url, { method: 'POST', credentials: 'same-origin',
                headers: Object.assign({}, headers, { Accept: 'text/event-stream', 'Content-Type': 'application/json' }),
                body: JSON.stringify(payload) });
            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                throw { status: response.status, responseJSON: body };
            }
            if (!response.body) throw { status: 0, responseJSON: { error_message: 'Streaming is unavailable in this browser.' } };
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '', result = null;
            try {
                while (true) {
                    const chunk = await reader.read();
                    buffer += decoder.decode(chunk.value || new Uint8Array(), { stream: !chunk.done });
                    buffer = buffer.replace(/\r\n/g, '\n');
                    let boundary;
                    while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                        const block = buffer.slice(0, boundary);
                        buffer = buffer.slice(boundary + 2);
                        const event = block.split('\n').find(line => line.startsWith('event:'))?.slice(6).trim();
                        const content = block.split('\n').filter(line => line.startsWith('data:')).map(line => line.slice(5).trimStart()).join('\n');
                        if (!content) continue;
                        const data = JSON.parse(content);
                        if (event === 'delta') onDelta(data.text || '');
                        if (event === 'error') throw { status: 502, responseJSON: data };
                        if (event === 'complete') result = data;
                    }
                    if (chunk.done) break;
                }
            } finally { reader.releaseLock(); }
            if (!result) throw { status: 0, responseJSON: { error_code: 'AI_STREAM_INTERRUPTED',
                error_message: 'Response interrupted. Reload the conversation before retrying.' } };
            return result;
        }
    };
})(window);
