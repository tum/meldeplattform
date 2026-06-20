// Progressive enhancement for an `audio` field (oral reporting). Lets a
// reporter record a voice message in the browser via MediaRecorder and feeds
// the recording into the field's <input type="file"> so it is submitted and
// stored exactly like an uploaded audio file. If the browser lacks
// MediaRecorder / getUserMedia, the controls stay hidden and the plain file
// input remains the fallback.
(function () {
    'use strict';

    var supported =
        typeof window.MediaRecorder !== 'undefined' &&
        navigator.mediaDevices &&
        typeof navigator.mediaDevices.getUserMedia === 'function' &&
        typeof DataTransfer !== 'undefined';

    // Pick a container/extension the browser can actually produce.
    function pickMime() {
        var candidates = [
            { mime: 'audio/webm', ext: 'webm' },
            { mime: 'audio/mp4', ext: 'mp4' },
            { mime: 'audio/ogg', ext: 'ogg' },
        ];
        for (var i = 0; i < candidates.length; i++) {
            if (MediaRecorder.isTypeSupported(candidates[i].mime)) {
                return candidates[i];
            }
        }
        return { mime: '', ext: 'webm' };
    }

    function setStatus(node, text) {
        if (node) node.textContent = text || '';
    }

    function initRecorder(container) {
        var input = container.querySelector('[data-audio-input]');
        var recordBtn = container.querySelector('[data-audio-record]');
        var stopBtn = container.querySelector('[data-audio-stop]');
        var preview = container.querySelector('[data-audio-preview]');
        var status = container.querySelector('[data-audio-status]');
        if (!input || !recordBtn || !stopBtn) return;

        // Reveal the record control now that JS support is confirmed.
        recordBtn.hidden = false;

        var picked = pickMime();
        var recorder = null;
        var chunks = [];

        recordBtn.addEventListener('click', function () {
            navigator.mediaDevices
                .getUserMedia({ audio: true })
                .then(function (stream) {
                    chunks = [];
                    recorder = picked.mime
                        ? new MediaRecorder(stream, { mimeType: picked.mime })
                        : new MediaRecorder(stream);

                    recorder.addEventListener('dataavailable', function (e) {
                        if (e.data && e.data.size > 0) chunks.push(e.data);
                    });

                    recorder.addEventListener('stop', function () {
                        stream.getTracks().forEach(function (t) {
                            t.stop();
                        });
                        var blob = new Blob(chunks, { type: picked.mime || 'audio/webm' });
                        var file = new File([blob], 'voice-message.' + picked.ext, {
                            type: blob.type,
                        });

                        // Inject the recording into the file input so it is
                        // submitted with the form (and picked up by the
                        // file-input.js preview / form validation).
                        var dt = new DataTransfer();
                        dt.items.add(file);
                        input.files = dt.files;
                        input.dispatchEvent(new Event('change', { bubbles: true }));

                        if (preview) {
                            preview.src = URL.createObjectURL(blob);
                            preview.hidden = false;
                        }
                        setStatus(status, '');
                        recordBtn.hidden = false;
                        stopBtn.hidden = true;
                    });

                    recorder.start();
                    recordBtn.hidden = true;
                    stopBtn.hidden = false;
                    setStatus(status, recordBtn.getAttribute('data-recording-label') || 'Recording…');
                })
                .catch(function () {
                    setStatus(status, recordBtn.getAttribute('data-denied-label') || 'Microphone unavailable.');
                });
        });

        stopBtn.addEventListener('click', function () {
            if (recorder && recorder.state !== 'inactive') {
                recorder.stop();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!supported) return; // Plain file input remains as the fallback.
        document.querySelectorAll('[data-audio-recorder]').forEach(initRecorder);
    });
})();
