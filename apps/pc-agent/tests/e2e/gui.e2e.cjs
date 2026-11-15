const pup=require('puppeteer-core');const {spawn}=require('child_process');
// Needs: npm i puppeteer-core ; a Chrome/Chromium (CHROME=/path, default google-chrome). Run: node tests/e2e/gui.e2e.cjs
// Drives the REAL desktop window (agent's local server + the premium UI) against a fake Laravel server. Set E2E_OLD_SERVER=1 to test an older server without /desktop.
(async()=>{
 const p=spawn(process.env.PYTHON||'python3',[__dirname+'/launch.py']);
 const info=await new Promise(r=>p.stdout.once('data',d=>r(JSON.parse(d)) ));
 const b=await pup.launch({executablePath:process.env.CHROME||'/usr/bin/google-chrome',headless:'new',args:['--no-sandbox']});
 const pg=await b.newPage();const errs=[];pg.on('pageerror',e=>errs.push(String(e)));pg.on('console',m=>{if(m.type()==='error')errs.push(m.text())});
 await pg.setViewport({width:1280,height:800});
 const ok=(c,m)=>{console.log((c?'OK  ':'FAIL')+' '+m);if(!c)process.exitCode=1};
 const txt=s=>pg.$eval(s,e=>e.textContent);
 const nav=async v=>{await click('#side button[data-v='+v+']');await pg.waitForFunction(v=>document.querySelector('#side button[data-v='+v+']').getAttribute('aria-current')==='true',{},v)};
 const click=async sel=>{for(let i=0;i<5;i++){try{await pg.click(sel);return}catch(e){if(!/detached|not clickable|No node/.test(String(e))||i===4)throw e;await new Promise(r=>setTimeout(r,300))}}};
 const sleep=ms=>new Promise(r=>setTimeout(r,ms));
 await pg.goto(info.url);await pg.waitForSelector('#pair');
 ok((await txt('h1')).includes('Pair'),'unpaired: pairing form on Home');
 ok(await pg.$$eval('#side button[data-v]',a=>a.length)===14,'sidebar has 14 menu items');
 await pg.screenshot({path:'/tmp/canai-1-pair.png'});
 await pg.type('#srv',info.server);await pg.type('#cod','WRONG');await click('#pair');
 await pg.waitForFunction(()=>document.querySelector('#msg').textContent.includes('code'));ok(true,'wrong code shows an error');
 await pg.$eval('#cod',e=>e.value='');await pg.type('#cod',info.code);await click('#pair');
 await pg.waitForSelector('#go');ok(true,'paired -> Home with Start button');
 if(!process.env.E2E_OLD_SERVER){await pg.waitForFunction(()=>document.querySelector('#model')&&!document.querySelector('#model').disabled,{timeout:8000});
 ok(await pg.$$eval('#model option',o=>o.length)===2,'model selector filled from the server');}
 else ok(await pg.$eval('#model',e=>e.disabled),'old server: model selector stays disabled');
 await nav('monitor');await pg.waitForSelector('.meter i');ok(await pg.$eval('.meter i',e=>e.style.width!==''),'monitor meters get their width (CSP-safe)');await nav('home');
 if(!process.env.E2E_OLD_SERVER)ok((await txt('#prof')).includes('Rafi'),'profile shows the server user name');
 await pg.screenshot({path:'/tmp/canai-2-home.png'});
 // ---- mode switch
 await click('[data-mode=companion]');await pg.waitForFunction(()=>document.querySelector('[data-mode=companion]').getAttribute('aria-pressed')==='true');ok(true,'mode switch -> Companion');
 await click('[data-mode=agent]');await pg.waitForFunction(()=>document.querySelector('[data-mode=agent]').getAttribute('aria-pressed')==='true');
 // ---- rules / settings
 await nav('rules');
 await pg.waitForSelector('#crash-list [data-crash]');ok(await pg.$eval('#crash-card',e=>e.textContent.includes('ValueError')&&!e.textContent.includes('QQQQ')),'settings: crash report listed, secret not shown');
 await click('#crash-list [data-crash]');await pg.waitForFunction(()=>{const d=document.querySelector('#crash-detail');return !d.hidden&&d.textContent.includes('"type": "ValueError"')&&!d.textContent.includes('QQQQQ')});ok(true,'settings: crash details shown (redacted)');
 await click('#crash-clear');await pg.waitForFunction(()=>document.querySelector('#crash-list').textContent.includes('No crash reports.'));ok(true,'settings: crash reports deleted');
 await pg.type('#in-root',info.root);await click('[data-add=root]');await pg.waitForSelector('[data-rm=root]');ok(true,'root added');
 await pg.type('#in-root','/');await click('[data-add=root]');
 await pg.waitForFunction(()=>document.querySelector('#msg').textContent.includes('drive'));ok(true,'"/" refused');
 await click('[data-kind=run_command]');await pg.waitForFunction(()=>document.querySelector('[data-kind=run_command]').checked);ok(true,'run_command switch on');
 await click('[data-pref=theme][data-pv=light]');await pg.waitForFunction(()=>document.documentElement.getAttribute('data-theme')==='light');ok(true,'theme -> light (persisted via /api/prefs)');
 await pg.screenshot({path:'/tmp/canai-3-settings-light.png',fullPage:true});
 await click('[data-pref=theme][data-pv=dark]');await pg.waitForFunction(()=>document.documentElement.getAttribute('data-theme')==='dark');
 ok((await pg.$$('[data-pref=language]')).length===0,'no UI language selector (English-only)');ok(await pg.$eval('#side button[data-v=home]',e=>e.textContent.includes('Home')),'UI is English');ok(await pg.evaluate(()=>!/[\u0980-\u09FF]/.test(document.body.innerText.replace(/বাংলা/g,''))),'no Bengali text in the page');
 await click('[data-pref=compact][data-pv=true]');await pg.waitForFunction(()=>document.body.classList.contains('compact'));
 ok(await pg.$eval('#side',e=>getComputedStyle(e).display==='none'),'compact layout hides the sidebar');
 await click('[data-pref=compact][data-pv=false]');await pg.waitForFunction(()=>!document.body.classList.contains('compact'));
 await click('[data-pref=reduced_motion][data-pv=on]');await pg.waitForFunction(()=>document.body.classList.contains('rm'));ok(true,'reduced motion class applied');
 await click('[data-pref=reduced_motion][data-pv=auto]');await pg.waitForFunction(()=>!document.body.classList.contains('rm'));
 // ---- files
 await nav('files');await pg.waitForFunction(()=>document.querySelector('#flist [data-dir]'));
 ok(true,'files: allowed folder listed');
 await click('#flist [data-dir]');await pg.waitForFunction(()=>document.querySelector('#flist').textContent.includes('a.txt'));ok(true,'files: folder opened, a.txt listed');
 await pg.screenshot({path:'/tmp/canai-4-files.png'});
 // ---- cloud screens
 const old=process.env.E2E_OLD_SERVER==='1';
 await nav('chat');
 if(old){await pg.waitForFunction(()=>document.querySelector('#chat-list').textContent.includes('update the server'));ok(true,'old server: clear "update the server" message');}
 else{
  await pg.waitForFunction(()=>document.querySelector('#chat-list [data-conv]'));ok(true,'chat: conversation list from server');
  await pg.type('#chat-in','Hello CAN AI');await click('#chat-send');
  await pg.waitForFunction(()=>document.querySelector('#msgs').textContent.includes('Echo: Hello CAN AI'));ok(true,'chat: message sent and reply streamed in and shown');
  await pg.waitForFunction(()=>document.querySelector('#chat-stop').hidden&&!document.querySelector('#chat-send').disabled,{timeout:5000});ok(true,'chat: Stop hidden and Send enabled again after the answer');
  // Stop in the middle of a long answer keeps what was written and frees the box
  await pg.type('#chat-in','SLOW please');await click('#chat-send');
  await pg.waitForFunction(()=>!document.querySelector('#chat-stop').hidden&&document.querySelector('#msgs').textContent.includes('tick tick'),{timeout:8000});ok(true,'chat: text appears while it is still being written, Stop is visible');
  await click('#chat-stop');
  await pg.waitForFunction(()=>document.querySelector('#chat-stop').hidden&&!document.querySelector('#chat-send').disabled&&document.querySelector('#msgs').textContent.includes('■'),{timeout:8000});
  const kept=await pg.$eval('#msgs',e=>e.textContent);ok(kept.includes('tick')&&!kept.includes('⚠'),'chat: Stop keeps the partial answer (marked ■), no error shown');
  // a server that cannot stream: the window falls back to the one-piece answer, once
  await pg.type('#chat-in','OLDSTREAM hello');await click('#chat-send');
  await pg.waitForFunction(()=>document.querySelector('#msgs').textContent.includes('Echo: OLDSTREAM hello'),{timeout:8000});ok(true,'chat: server without streaming -> fallback answer shown');
  await click('#chat-list [data-conv=C1]');await pg.waitForFunction(()=>document.querySelector('#msgs pre code'));
  ok(await pg.$eval('#msgs pre code',e=>e.textContent.includes('print(1)')),'chat: code block rendered as <pre><code>');
  ok(await pg.$$eval('#msgs b',e=>e.length>0),'chat: **bold** rendered');
  await pg.screenshot({path:'/tmp/canai-5-chat.png'});
  await nav('agents');await pg.waitForSelector('[data-arun=AG1]');await pg.type('#ain-AG1','find x');await click('[data-arun=AG1]');
  await pg.waitForFunction(()=>document.querySelector('#ares-AG1').textContent.includes('Agent says: find x'));ok(true,'agents: run shows output');
  await nav('workflows');await pg.waitForSelector('[data-wrun=W1]');
  ok(await pg.$eval('[data-wrun=W2]',e=>e.disabled),'workflows: a paused workflow cannot be run');
  await click('[data-wrun=W1]');await pg.waitForSelector('[data-wcancel=R2]');ok(true,'workflows: run started, appears in recent runs');
  await click('[data-wcancel=R2]');await pg.waitForFunction(()=>document.querySelector('#wruns').textContent.includes('cancelled'));ok(true,'workflows: run cancelled');
  await nav('memory');await pg.waitForSelector('[data-mdel]');await pg.type('#mem-new','আমি কফি পছন্দ করি');await click('#mem-add');
  await pg.waitForFunction(()=>document.querySelector('#mlist').textContent.includes('কফি'));ok(true,'memory: saved and listed');
  await click('[data-mdel=M1]');await pg.waitForFunction(()=>!document.querySelector('#mlist').textContent.includes('চা পছন্দ'));ok(true,'memory: deleted');
  await nav('tools');await pg.waitForFunction(()=>document.querySelector('#toollist').textContent.includes('tasks.add'));ok(true,'tools: workflow tool list with risks');
  await nav('approvals');await pg.waitForFunction(()=>document.querySelector('#pend').textContent.includes('report.docx'));ok(true,'approvals: pending list; no approve button here');
  ok(await pg.$$eval('button',b=>b.filter(x=>/approve/i.test(x.textContent)).length)===0,'approvals: the window offers NO approve button');
 }
 // ---- computer control, emergency stop, run/stop
 await nav('computer');
 await click('#estop');await pg.waitForFunction(()=>document.querySelector('#estop').textContent.includes('Resume'));ok(true,'emergency stop ON (flag)');
 await click('#estop');await pg.waitForFunction(()=>document.querySelector('#estop').textContent.includes('Emergency'));ok(true,'emergency stop cleared');
 if(!old){await pg.type('#goal','Summarise my notes folder');await click('#goal-go');await pg.waitForFunction(()=>document.querySelector('#goalres').textContent.includes('drafted'));ok(true,'computer: goal -> plan draft (approval stays on the website)');}
 await pg.waitForFunction(()=>document.querySelector('#tasklist').textContent.includes('Audit'));ok(true,'computer: audit chain status shown');
 await pg.$eval('#audit-sync',e=>e.click());
 await pg.waitForFunction(()=>{const e=document.querySelector('#audit-sync-state');return e&&e.textContent.length>0&&!e.classList.contains('bad')},{timeout:6000});ok(true,'computer: "Sync with server" button compares the audit chain and shows a good state');
 await pg.waitForFunction(()=>document.querySelector('#live').textContent.includes('Nothing is running'));ok(await pg.$eval('#pause-go',e=>e.disabled),'live card: nothing running, pause disabled');
 await pg.evaluate(()=>fetch('/api/pause',{method:'POST',headers:{'X-Agent-Token':location.hash.slice(1),'Content-Type':'application/json'},body:'{}'}));
 await pg.waitForFunction(()=>document.querySelector('#pause-go').getAttribute('aria-pressed')==='true',{timeout:9000});ok(await pg.$eval('#pause-go',e=>e.textContent.includes('Continue')),'live card: paused state shown, button offers Continue');
 await click('#pause-go');await pg.waitForFunction(()=>document.querySelector('#pause-go').getAttribute('aria-pressed')==='false');ok(true,'Continue clears the pause');
 await nav('home');await click('#go');await pg.waitForFunction(()=>document.querySelector('h1').textContent.includes('running'));ok(true,'agent running');
 await nav('hist');await pg.waitForFunction(()=>document.querySelector('#hist td'),{timeout:12000});
 ok(await pg.$eval('#hist',e=>e.textContent.includes('read_file')&&e.textContent.includes('Done')),'history shows completed read_file');
 await nav('monitor');ok((await txt('#view')).includes('%'),'monitor shows cpu/mem/disk');
 await nav('browser');ok((await txt('#view')).includes('https'),'browser screen: untrusted-pages note + allowed sites');
 await nav('pairing');await click('#conn-test');await pg.waitForFunction(()=>document.querySelector('#conn-res').textContent.length>0);ok(true,'pairing: connection test answered: '+await txt('#conn-res'));
 await click('#rotate-go');await pg.waitForFunction(()=>document.querySelector('#msg').textContent.includes('New token'),{timeout:8000});ok(true,'pairing: token rotated from the window');
 await click('#conn-test');await pg.waitForFunction(()=>document.querySelector('#conn-res').textContent.length>0);ok(!(await txt('#conn-res')).includes('✗'),'pairing: the NEW token works: '+await txt('#conn-res'));
 await nav('home');await click('#stop');await pg.waitForFunction(()=>document.querySelector('h1').textContent.includes('stopped'),{timeout:12000});ok(true,'stopped');
 // ---- walk every screen: heading present, no JS errors
 for(const v of ['home','chat','agents','workflows','computer','browser','files','memory','tools','pairing','approvals','hist','monitor','rules']){await nav(v);await sleep(250);
   ok(await pg.$eval('#view',e=>e.textContent.trim().length>20),'screen "'+v+'" renders content');}
 // ---- the home page must survive many refresh cycles with clicks landing (no vanished buttons)
 await nav('home');for(let i=0;i<12;i++){await click('[data-mode=agent]');await sleep(700);}ok(true,'12 clicks across refresh cycles all landed');
 // ---- unpair
 await nav('pairing');await click('#unpair');await pg.waitForSelector('#pair');ok(true,'unpair -> back to pairing form');
 // ---- accessibility / layout
 const bad=await pg.evaluate(()=>[...document.querySelectorAll('button,input,select,textarea')].filter(e=>e.offsetParent&&!(e.getAttribute('aria-label')||e.textContent.trim()||e.placeholder)).length);
 ok(bad===0,'every visible control has a name');
 await pg.setViewport({width:380,height:700});await pg.screenshot({path:'/tmp/canai-6-narrow.png',fullPage:true});
 ok(!(await pg.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)),'no horizontal overflow at 380px');
 const real=errs.filter(e=>!/\b(422|409|501|502|404)\b/.test(e));ok(real.length===0,'no unexpected console/page errors ('+real.length+') '+JSON.stringify(real.slice(0,2)).slice(0,300));
 await b.close();p.kill();
})();
