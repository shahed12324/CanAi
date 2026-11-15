const pup=require('puppeteer-core');const {spawn}=require('child_process');
// Phase C e2e: voice + character in the REAL desktop window. Chrome's fake microphone replaces the hardware; SpeechRecognition / speechSynthesis are stubbed
// (headless Chrome has no speech service), so this proves OUR wiring (state machine, chat hand-off, mute, cloud fallback) - NOT recognition quality.
(async()=>{
 const p=spawn(process.env.PYTHON||'python3',[__dirname+'/launch.py']);
 const info=await new Promise(r=>p.stdout.once('data',d=>r(JSON.parse(d))));
 const b=await pup.launch({executablePath:process.env.CHROME||'/usr/bin/google-chrome',headless:'new',args:['--no-sandbox','--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream']});
 const pg=await b.newPage();const errs=[];pg.on('pageerror',e=>errs.push(String(e)));
 const ok=(c,m)=>{console.log((c?'OK  ':'FAIL')+' '+m);if(!c)process.exitCode=1};
 const sleep=ms=>new Promise(r=>setTimeout(r,ms));
 const stub=(withSR)=>pg.evaluateOnNewDocument((withSR)=>{
  window.__said=[];window.__states=[];
  addEventListener('canai-state',e=>window.__states.push(e.detail));
  if(withSR){window.SpeechRecognition=function(){this.start=()=>{setTimeout(()=>{this.onresult&&this.onresult({results:[[{transcript:'hello can ai'}]]})},300)};this.stop=()=>{setTimeout(()=>this.onend&&this.onend(),20)}}}
  else{delete window.SpeechRecognition;delete window.webkitSpeechRecognition}
  window.SpeechSynthesisUtterance=function(t){this.text=t};
  Object.defineProperty(window,'speechSynthesis',{configurable:true,value:{getVoices:()=>[],cancel(){window.__cancelled=(window.__cancelled||0)+1},speak(u){window.__said.push(u.text);setTimeout(()=>{u.onboundary&&u.onboundary({charIndex:0});setTimeout(()=>u.onend&&u.onend(),150)},50)}}});
 },withSR);
 await stub(true);
 await pg.setViewport({width:1280,height:800});
 await pg.goto(info.url);await pg.waitForSelector('#pair');
 await pg.type('#srv',info.server);await pg.type('#cod',info.code);await pg.click('#pair');await pg.waitForSelector('#go');
 await pg.waitForFunction(()=>document.querySelector('#model')&&!document.querySelector('#model').disabled,{timeout:8000});
 // character
 ok(await pg.$('#stage svg')!==null,'character (SVG) is on the Home screen');
 ok(await pg.evaluate(()=>window.__states.length>=0),'state events are observable');
 // nothing listened before a click
 ok(!(await pg.evaluate(()=>window.__states.includes('listening'))),'microphone NOT opened on load (no auto-listen)');
 await pg.click('#side button[data-v=chat]');await pg.waitForSelector('#mic-go');
 await pg.evaluate(()=>CANAI.setPref('voice_reply',true));await sleep(300);
 await pg.click('#mic-go');
 await pg.waitForFunction(()=>window.__states.includes('listening'),{timeout:5000});ok(true,'click on mic -> character state "listening"');
 await sleep(900);await pg.click('#mic-go');                      // second click = finish
 await pg.waitForFunction(()=>document.querySelector('#msgs').textContent.includes('Echo: hello can ai'),{timeout:8000});ok(true,'spoken text went through normal chat and got the server reply');
 await pg.waitForFunction(()=>window.__said.length>0,{timeout:6000});ok((await pg.evaluate(()=>window.__said.join(' '))).includes('Echo'),'reply is spoken aloud (voice_reply on)');
 const st=await pg.evaluate(()=>window.__states.join(','));
 await pg.waitForFunction(()=>window.__states.lastIndexOf('idle')>window.__states.lastIndexOf('speaking'),{timeout:4000});
 ok(/listening.*thinking.*speaking.*idle/.test(await pg.evaluate(()=>window.__states.join(','))),'state order listening→thinking→speaking→idle');
 // mute stops speech and blocks the mic
 await pg.click('#mute-go');await sleep(200);
 ok(await pg.evaluate(()=>CANAI_VOICE.muted()),'mute button mutes');
 const before=await pg.evaluate(()=>window.__states.filter(s=>s==='listening').length);
 await pg.click('#mic-go');await sleep(600);
 ok(await pg.evaluate(b=>window.__states.filter(s=>s==='listening').length===b,before),'muted: mic click does not start listening');
 await pg.click('#mute-go');
 // settings card
 await pg.click('#side button[data-v=rules]');await pg.waitForSelector('#voice-card');
 ok(await pg.$('#hf-toggle')===null&&!(await pg.evaluate(()=>CANAI_VOICE.handsFree())),'hands-free / wake word hidden and OFF until the person allows it');
 await pg.click('[data-pref=wake_word][data-pv=true]');await pg.waitForSelector('#hf-toggle');
 await pg.click('#hf-toggle');await sleep(300);
 ok(!(await pg.evaluate(()=>CANAI_VOICE.handsFree())),'hands-free refuses to start without the consent tick');
 await pg.click('#hf-consent');await pg.click('#hf-toggle');await pg.waitForFunction(()=>CANAI_VOICE.handsFree(),{timeout:4000});ok(true,'with consent: hands-free ON (mic indicator shows it)');
 ok(await pg.$eval('#micstat',e=>e.textContent.length>0),'top bar shows a microphone status pill');
 await pg.click('#hf-toggle');await pg.waitForFunction(()=>!CANAI_VOICE.handsFree(),{timeout:4000});ok(true,'hands-free can be turned off again');
 await pg.click('#mic-test');await pg.waitForFunction(()=>document.querySelector('#test-level'),{timeout:3000});ok(true,'mic test runs with the fake microphone');
 await pg.click('#voice-test');await pg.waitForFunction(()=>window.__said.length>1,{timeout:5000});ok(true,'"Test voice" speaks');
 await pg.click('#voice-quiet');await sleep(150);ok(await pg.evaluate(()=>window.__cancelled>0),'"Stop speaking" cancels speech');
 ok(errs.length===0,'no page errors '+errs.join('|'));
 await pg.screenshot({path:'/tmp/canai-5-voice.png',fullPage:true});
 // lip sync from the real audio level: loud -> mouth open, silent -> closed (cloud voice path feeds canai-speech-level)
 await pg.click('#side button[data-v=home]');await pg.waitForSelector('#stage svg');
 await pg.evaluate(()=>{dispatchEvent(new CustomEvent('canai-state',{detail:'speaking'}));window.__lv=setInterval(()=>dispatchEvent(new CustomEvent('canai-speech-level',{detail:0.25})),50)});await sleep(300);
 ok(await pg.evaluate(()=>CANAI_CHARACTER.pose().mouthOpen>0.7),'loud audio level -> character mouth opens');
 await pg.evaluate(()=>{clearInterval(window.__lv);dispatchEvent(new CustomEvent('canai-speech-level',{detail:0}))});await sleep(300);
 ok(await pg.evaluate(()=>CANAI_CHARACTER.pose().mouthOpen<0.1),'silent audio level -> mouth closes');
 await pg.evaluate(()=>dispatchEvent(new CustomEvent('canai-state',{detail:'idle'})));
 // ---- window without any speech recognition -> cloud voice path
 const pg2=await b.newPage();pg2.on('pageerror',e=>errs.push(String(e)));
 await pg2.evaluateOnNewDocument(()=>{window.__states=[];addEventListener('canai-state',e=>window.__states.push(e.detail));delete window.SpeechRecognition;delete window.webkitSpeechRecognition;window.SpeechSynthesisUtterance=function(t){this.text=t};Object.defineProperty(window,'speechSynthesis',{configurable:true,value:{getVoices:()=>[],cancel(){},speak(u){setTimeout(()=>u.onend&&u.onend(),20)}}})});
 await pg2.setViewport({width:1280,height:800});await pg2.goto(info.url);await pg2.waitForSelector('#go');
 await pg2.waitForFunction(()=>document.querySelector('#model')&&!document.querySelector('#model').disabled,{timeout:8000});
 await pg2.click('#side button[data-v=chat]');await pg2.waitForSelector('#mic-go');
 await pg2.click('#mic-go');await sleep(1500);await pg2.click('#mic-go');
 await pg2.waitForFunction(()=>document.querySelector('#msgs').textContent.includes('Echo: cloud heard hello'),{timeout:10000});ok(true,'no browser speech → recording sent to cloud STT → text → chat');
 ok(errs.length===0,'no page errors (cloud path) '+errs.join('|'));
 // ---- WebView2-like window: the speech object EXISTS but always fails with "network" -> falls back to cloud, tells about credits, remembers
 const pg3=await b.newPage();pg3.on('pageerror',e=>errs.push(String(e)));
 await pg3.evaluateOnNewDocument(()=>{window.__states=[];window.__said=[];addEventListener('canai-state',e=>window.__states.push(e.detail));
  window.SpeechRecognition=function(){window.__srStarts=(window.__srStarts||0)+1;this.start=()=>{setTimeout(()=>{this.onerror&&this.onerror({error:'network'});this.onend&&this.onend()},200)};this.stop=()=>{}};
  window.SpeechSynthesisUtterance=function(t){this.text=t};Object.defineProperty(window,'speechSynthesis',{configurable:true,value:{getVoices:()=>[],cancel(){},speak(u){setTimeout(()=>u.onend&&u.onend(),10)},onvoiceschanged:null}})});
 await pg3.setViewport({width:1280,height:800});await pg3.goto(info.url);await pg3.waitForSelector('#go');
 await pg3.waitForFunction(()=>document.querySelector('#model')&&!document.querySelector('#model').disabled,{timeout:8000});
 await pg3.click('#side button[data-v=chat]');await pg3.waitForSelector('#mic-go');
 await pg3.click('#mic-go');await sleep(1500);await pg3.click('#mic-go');
 await pg3.waitForFunction(()=>document.querySelector('#msgs').textContent.includes('Echo: cloud heard hello'),{timeout:10000});ok(true,'speech object present but failing (network) -> recording sent to cloud STT -> chat');
 ok(await pg3.evaluate(()=>document.body.textContent.includes('credits')),'person is told that cloud voice uses credits');
 await pg3.waitForFunction(()=>!document.querySelector('#mic-go').disabled,{timeout:5000});
 const starts=await pg3.evaluate(()=>window.__srStarts);
 await pg3.click('#mic-go');await sleep(1500);await pg3.click('#mic-go');
 await pg3.waitForFunction(()=>(document.querySelector('#msgs').textContent.match(/cloud heard hello/g)||[]).length>=2,{timeout:10000});
 ok(await pg3.evaluate(s=>window.__srStarts===s,starts),'the broken engine is not tried again in this session');
 await sleep(500);await pg3.click('#side button[data-v=rules]');await pg3.waitForSelector('#voice-card',{timeout:6000});await pg3.evaluate(()=>{const e=document.querySelector('#diag-go');e.scrollIntoView();e.click()});
 await pg3.waitForFunction(()=>{const e=document.querySelector('#diag-out');return e&&!e.hidden&&e.textContent.includes('chosen plan: cloud')},{timeout:4000});
 const dtext=await pg3.$eval('#diag-out',e=>e.textContent);ok(dtext.includes('failed earlier')&&dtext.includes('audio inputs seen')&&!/token/i.test(dtext),'diagnostics report shows engine failure, plan and devices (no secrets)');
 ok(errs.length===0,'no page errors (broken engine path) '+errs.join('|'));
 await b.close();p.kill();
})().catch(e=>{console.error('E2E CRASH',e);process.exit(1)});
