/* Browser adapters for VoiceSession (see voice-session.js).  These touch real hardware/browser APIs, so they are NOT covered by the node tests:
   they must be tried in Chrome/Edge with a microphone.  Everything that decides what to do lives in voice-session.js. */
(function (root) {
  'use strict';
  var SR = root.SpeechRecognition || root.webkitSpeechRecognition;

  function supported() { return !!(SR && root.navigator && navigator.mediaDevices && root.MediaRecorder && root.speechSynthesis); }

  /** Continuous recognition used ONLY to hear the wake phrase.  In Chrome this audio is processed by the browser vendor's speech service — the consent text says so. */
  function wakeAdapter(lang) {
    var rec = null, want = false, cb = null;
    function boot() {
      if (!want) { return; }
      rec = new SR();
      rec.continuous = true; rec.interimResults = true; rec.lang = lang();
      rec.onresult = function (e) {
        for (var i = e.resultIndex; i < e.results.length; i++) { if (cb) { cb(e.results[i][0].transcript); } }
      };
      rec.onend = function () { if (want) { setTimeout(boot, 250); } };          // the browser ends sessions on its own: restart while wanted
      rec.onerror = function (e) { if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { want = false; } };
      try { rec.start(); } catch (e) { /* already started */ }
    }
    return {
      start: function (onText) { cb = onText; if (want) { return; } want = true; boot(); },
      stop: function () { want = false; cb = null; if (rec) { try { rec.onend = null; rec.stop(); } catch (e) { /* ignore */ } rec = null; } }
    };
  }

  /** Records one utterance with MediaRecorder and reports loudness for the end-pointer. */
  function captureAdapter() {
    var stream = null, mr = null, chunks = [], ctx = null, an = null, buf = null;
    function cleanup() {
      if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); }
      if (ctx) { try { ctx.close(); } catch (e) { /* ignore */ } }
      stream = ctx = an = buf = null;
    }
    return {
      start: function () {
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
          stream = s; chunks = [];
          mr = new MediaRecorder(s);
          mr.ondataavailable = function (e) { if (e.data && e.data.size) { chunks.push(e.data); } };
          mr.start();
          var AC = root.AudioContext || root.webkitAudioContext;
          ctx = new AC(); an = ctx.createAnalyser(); an.fftSize = 1024; buf = new Float32Array(an.fftSize);
          ctx.createMediaStreamSource(s).connect(an);
        }).catch(function () { cleanup(); });
      },
      level: function () {
        if (!an) { return 0; }
        an.getFloatTimeDomainData(buf);
        var sum = 0; for (var i = 0; i < buf.length; i++) { sum += buf[i] * buf[i]; }
        return Math.sqrt(sum / buf.length);
      },
      stop: function () {
        return new Promise(function (resolve) {
          if (!mr || mr.state === 'inactive') { cleanup(); resolve(null); return; }
          mr.onstop = function () { var b = new Blob(chunks, { type: mr.mimeType || 'audio/webm' }); mr = null; cleanup(); resolve(b); };
          try { mr.stop(); } catch (e) { cleanup(); resolve(null); }
        });
      }
    };
  }

  /** Reads text aloud with the browser's own voices (free, local).  */
  function speakerAdapter(lang) {
    return {
      speak: function (text) {
        return new Promise(function (resolve) {
          var u = new SpeechSynthesisUtterance(String(text).slice(0, 2000));
          u.lang = lang(); u.onend = resolve; u.onerror = resolve;
          root.speechSynthesis.cancel(); root.speechSynthesis.speak(u);
        });
      },
      cancel: function () { try { root.speechSynthesis.cancel(); } catch (e) { /* ignore */ } }
    };
  }

  root.VoiceBrowser = { supported: supported, wakeAdapter: wakeAdapter, captureAdapter: captureAdapter, speakerAdapter: speakerAdapter };
})(this);
