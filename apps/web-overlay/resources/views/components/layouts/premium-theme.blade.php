<style>
/* JARVIS ONE Dark Premium — scoped to authenticated application shells */
:root{color-scheme:dark}body.jarvis-premium{background:#080c18!important;color:#e7eaf5!important;font-family:Inter,ui-sans-serif,system-ui,sans-serif} .jarvis-premium .jarvis-shell{background:radial-gradient(ellipse at 75% -20%,#1b1b48 0%,#0a1021 42%,#080c18 78%)}
.jarvis-premium .jarvis-sidebar{background:linear-gradient(180deg,#11152b,#0b1020 55%,#090e1b)!important;border-right:1px solid #272c48;scrollbar-width:thin;scrollbar-color:#41436c transparent}
.jarvis-premium .jarvis-brand{display:flex;align-items:center;gap:11px;padding:15px 12px;margin:10px 8px 17px;border-radius:15px;background:linear-gradient(110deg,#30255f,#1c2246);border:1px solid #504581;letter-spacing:.025em;box-shadow:0 12px 28px #0003}
.jarvis-premium .jarvis-brand:before{content:'✦';display:grid;place-items:center;flex:none;width:35px;height:35px;border-radius:11px;background:linear-gradient(135deg,#a46cff,#5753eb);color:white;font-size:22px}
.jarvis-premium .jarvis-sidebar nav a{display:block!important;margin:3px 7px;padding:10px 13px!important;border-radius:10px!important;color:#aab4d2!important;font-weight:500;transition:background .15s,color .15s,transform .15s}
.jarvis-premium .jarvis-sidebar nav a:hover{background:#252944!important;color:white!important;transform:translateX(2px)}
.jarvis-premium .jarvis-sidebar nav a.bg-indigo-500\/30,.jarvis-premium .jarvis-sidebar nav a[aria-current=page]{background:linear-gradient(95deg,#6544c8,#38366f)!important;color:#fff!important;box-shadow:inset 3px 0 #b69aff}
.jarvis-premium .jarvis-sidebar nav p{color:#777fa3!important;letter-spacing:.13em;padding-top:12px;font-size:10px!important}
.jarvis-premium .jarvis-topbar{background:#10172aee!important;border-bottom:1px solid #29304b!important;box-shadow:none!important;min-height:72px;backdrop-filter:blur(18px)}
.jarvis-premium .jarvis-main{max-width:1500px!important;width:100%;margin:auto;padding:28px!important;min-height:calc(100vh - 130px)}
.jarvis-premium .jarvis-main h1,.jarvis-premium .jarvis-main h2,.jarvis-premium .jarvis-main h3{color:#f5f5ff}
.jarvis-premium .jarvis-main :is(.bg-white,.bg-gray-50,.bg-gray-100){background:#151d33!important;color:#e7eaf5!important;border-color:#303955!important}
.jarvis-premium .jarvis-main :is(.border,.border-gray-200,.border-gray-300){border-color:#303955!important}
.jarvis-premium .jarvis-main :is(.text-gray-900,.text-gray-800,.text-gray-700,.text-gray-600){color:#cbd3e9!important}
.jarvis-premium .jarvis-main :is(input,textarea,select){background:#0e1527!important;border:1px solid #343b57!important;color:#e8eaff!important;border-radius:10px;min-height:37px}
.jarvis-premium .jarvis-main :is(input,textarea)::placeholder{color:#7983a1}
.jarvis-premium .jarvis-main :is(button,.bg-indigo-600,.bg-blue-600){border-radius:10px}
.jarvis-premium .jarvis-main :is(table){width:100%;border-collapse:separate;border-spacing:0}
.jarvis-premium .jarvis-main :is(th,td){border-color:#303955!important}
.jarvis-premium .jarvis-topbar select,.jarvis-premium .jarvis-topbar input{background:#1c2540;color:#f5f5ff;border:1px solid #3c4264;border-radius:10px;padding:8px 12px}
.jarvis-premium .jarvis-footer{color:#838eac!important}
.jarvis-premium .jarvis-mobile-toggle{display:none;background:#282448;border:1px solid #534584;color:white;padding:9px 13px;border-radius:10px}
@media(max-width:1023px){.jarvis-premium .jarvis-mobile-toggle{display:inline-flex}.jarvis-premium .jarvis-sidebar{position:relative!important;width:100%!important;height:auto!important;max-height:none!important}.jarvis-premium .jarvis-sidebar nav{max-height:65vh;overflow:auto}.jarvis-premium .jarvis-main{padding:18px!important}}
@media(min-width:1024px){.jarvis-premium .jarvis-sidebar{width:268px!important}}

/* Phase 2 — consistent application-wide components */
.jarvis-premium{--jp-panel:#151d33;--jp-border:#303955;--jp-muted:#99a6c4;--jp-accent:#a78bfa}
.jarvis-premium .jarvis-main :is(section,.rounded-lg,.rounded-xl,.rounded-2xl){transition:border-color .18s ease,box-shadow .18s ease}
.jarvis-premium .jarvis-main :is(.bg-white,.bg-gray-50,.bg-gray-100,.bg-slate-50){border:1px solid var(--jp-border)!important;border-radius:16px!important;box-shadow:0 12px 35px #00000018!important}
.jarvis-premium .jarvis-main :is(.bg-indigo-50,.bg-blue-50,.bg-purple-50){background:#242045!important;color:#ddd6fe!important;border-color:#514280!important}
.jarvis-premium .jarvis-main :is(.bg-green-50,.bg-emerald-50){background:#10332e!important;color:#b9f5df!important}
.jarvis-premium .jarvis-main :is(.bg-red-50,.bg-rose-50){background:#3d202d!important;color:#fecdd3!important}
.jarvis-premium .jarvis-main :is(.text-gray-500,.text-gray-400,.text-slate-500){color:#9da9c7!important}
.jarvis-premium .jarvis-main :is(.text-indigo-600,.text-blue-600,.text-indigo-700){color:#b4a0ff!important}
.jarvis-premium .jarvis-main :is(.hover\:bg-gray-50:hover,.hover\:bg-gray-100:hover){background:#202b46!important}
.jarvis-premium .jarvis-main :is(input,textarea,select){padding:9px 12px;max-width:100%;outline-offset:3px}
.jarvis-premium .jarvis-main :is(input,textarea,select):focus{outline:2px solid #8b5cf6;border-color:#8b5cf6!important}
.jarvis-premium .jarvis-main :is(button,[type=submit],.bg-indigo-600,.bg-blue-600){transition:transform .15s,filter .15s,box-shadow .15s}
.jarvis-premium .jarvis-main :is(button,[type=submit]):hover{filter:brightness(1.12)}
.jarvis-premium .jarvis-main :is(button,[type=submit]):active{transform:scale(.98)}
.jarvis-premium .jarvis-main :is(th){background:#1b2640!important;color:#b6c2df!important;text-transform:uppercase;letter-spacing:.05em;font-size:11px}
.jarvis-premium .jarvis-main :is(th,td){padding:11px 13px;border-bottom:1px solid #303955}
.jarvis-premium .jarvis-main :is(tr):hover td{background:#1a2540}
.jarvis-premium .jarvis-main :is(a){text-underline-offset:3px}
.jarvis-premium .jarvis-main :is(h1){font-size:clamp(1.6rem,2.6vw,2.15rem);font-weight:750;letter-spacing:-.035em}
.jarvis-premium .jarvis-main :is(h2){letter-spacing:-.025em}
.jarvis-premium .jarvis-page-heading{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap;padding:8px 0 20px}
.jarvis-premium .jarvis-page-heading small{display:block;text-transform:uppercase;color:#a78bfa;font-size:10px;letter-spacing:.17em;font-weight:700;margin-bottom:7px}
.jarvis-premium .jarvis-page-heading h1{margin:0}
.jarvis-premium .jarvis-page-heading p{color:#97a5c4;font-size:13px;margin-top:5px}
.jarvis-premium .jarvis-page-heading .jarvis-home{border:1px solid #3d4666;padding:10px 14px;border-radius:11px;color:#d8dcf0;text-decoration:none;font-size:12px}
.jarvis-premium .jarvis-page-heading .jarvis-home:hover{background:#252f50}
.jarvis-premium .jarvis-sidebar nav a:focus-visible,.jarvis-premium .jarvis-topbar a:focus-visible{outline:2px solid #b99aff;outline-offset:2px}
.jarvis-premium .jarvis-sidebar nav a[aria-current=page]{background:linear-gradient(95deg,#6544c8,#38366f)!important;color:#fff!important}
.jarvis-premium .jarvis-topbar{position:sticky;top:0;z-index:30}
.jarvis-premium .jarvis-sidebar{z-index:40}
.jarvis-premium .jarvis-mobile-toggle{align-items:center;gap:8px;font-weight:650;cursor:pointer}
.jarvis-premium .jarvis-sidebar .jarvis-sidebar-close{display:none}
@media(max-width:1023px){
 .jarvis-premium .jarvis-shell{display:block}
 .jarvis-premium .jarvis-sidebar{position:fixed!important;left:0;top:0;bottom:0;width:min(320px,85vw)!important;height:100dvh!important;overflow:auto;transform:translateX(-110%);transition:transform .22s ease;box-shadow:20px 0 60px #0008}
 .jarvis-premium.jarvis-menu-open .jarvis-sidebar{transform:translateX(0)}
 .jarvis-premium .jarvis-sidebar .jarvis-sidebar-close{display:inline-flex;margin:5px 12px;padding:6px 12px;border-radius:8px;border:1px solid #554777;color:white}
 .jarvis-premium .jarvis-overlay{display:none;position:fixed;inset:0;background:#02040bc4;z-index:35}
 .jarvis-premium.jarvis-menu-open .jarvis-overlay{display:block}
 .jarvis-premium .jarvis-main{padding:17px!important}
 .jarvis-premium .jarvis-topbar{padding:12px 15px!important}
 .jarvis-premium .jarvis-main table{display:block;overflow-x:auto}
}
@media(min-width:1024px){.jarvis-premium .jarvis-sidebar{position:sticky!important;top:0;height:100dvh!important;overflow-y:auto}}
@media(prefers-reduced-motion:reduce){.jarvis-premium *{transition:none!important;scroll-behavior:auto!important}}

/* Phase 3: reusable responsive styling for every authenticated module */
.jarvis-premium{--jp-bg:#090e1c;--jp-surface:#131b30;--jp-surface2:#1b2540;--jp-line:#303c5b;--jp-text:#edf0ff;--jp-soft:#a5b1d0;--jp-violet:#9e7bff}
.jarvis-premium .jarvis-main{animation:jp-enter .28s ease both}
@keyframes jp-enter{from{opacity:.5;transform:translateY(7px)}to{opacity:1;transform:translateY(0)}}
.jarvis-premium .jarvis-main :is(.bg-slate-900,.bg-gray-900){background-color:#111a2e!important}
.jarvis-premium .jarvis-main :is(.bg-slate-800,.bg-gray-800){background-color:#1b2540!important}
.jarvis-premium .jarvis-main :is(.bg-white,.bg-gray-50,.bg-gray-100,.bg-slate-50,.bg-slate-100){background-color:var(--jp-surface)!important}
.jarvis-premium .jarvis-main :is(.text-black,.text-slate-900,.text-slate-800,.text-gray-900,.text-gray-800){color:var(--jp-text)!important}
.jarvis-premium .jarvis-main :is(.text-slate-700,.text-slate-600,.text-slate-500,.text-gray-700,.text-gray-600,.text-gray-500){color:var(--jp-soft)!important}
.jarvis-premium .jarvis-main :is(.border-slate-200,.border-slate-300,.border-gray-200,.border-gray-300,.divide-gray-200){border-color:var(--jp-line)!important}
.jarvis-premium .jarvis-main :is(input,textarea,select){max-width:100%;outline-offset:3px;accent-color:var(--jp-violet)}
.jarvis-premium .jarvis-main :is(input,textarea,select,button,a):focus-visible,.jarvis-premium .jarvis-sidebar a:focus-visible{outline:2px solid #a78bfa!important;outline-offset:2px}
.jarvis-premium .jarvis-main :is(input,textarea,select):focus{border-color:#9475ff!important;box-shadow:0 0 0 3px #7c3aed2b!important}
.jarvis-premium .jarvis-main :is(.bg-indigo-600,.bg-violet-600,.bg-purple-600,.bg-blue-600){background:linear-gradient(125deg,#7950df,#5346bf)!important;color:white!important}
.jarvis-premium .jarvis-main :is(.bg-indigo-600,.bg-violet-600,.bg-purple-600,.bg-blue-600):hover{filter:brightness(1.13)}
.jarvis-premium .jarvis-main :is(.shadow,.shadow-sm,.shadow-md,.shadow-lg){box-shadow:0 10px 30px #0003!important}
.jarvis-premium .jarvis-main table{display:table;min-width:500px}
.jarvis-premium .jarvis-main :is(.overflow-x-auto,.overflow-auto){scrollbar-color:#545078 transparent;scrollbar-width:thin}
.jarvis-premium .jarvis-main thead{background:#202b46;color:#c6cffa}
.jarvis-premium .jarvis-main tbody tr:hover{background:#ffffff08}
.jarvis-premium .jarvis-main :is(th,td){padding-top:.7rem;padding-bottom:.7rem}
.jarvis-premium .jarvis-main :is(pre,code){background:#0c1324;color:#c7b8ff;border-radius:7px}
.jarvis-premium .jarvis-main pre{overflow-x:auto;padding:1rem}
.jarvis-premium .jarvis-main :is(hr){border-color:var(--jp-line)}
.jarvis-premium .jarvis-main :is(.rounded-lg,.rounded-xl,.rounded-2xl){border-radius:14px}
.jarvis-premium .jarvis-page-heading{background:linear-gradient(110deg,#1d2344,#141b32 65%,#241b46);border:1px solid #3e3a70}
.jarvis-premium .jarvis-nav-search{display:block;width:calc(100% - 28px);margin:12px 14px 14px;padding:11px 13px;border:1px solid #343d61;border-radius:12px;background:#111a30;color:#f0edff;font-size:13px}
.jarvis-premium .jarvis-nav-search::placeholder{color:#8e9aba}
.jarvis-premium .jarvis-nav-search:focus{outline:2px solid #8b5cf6;outline-offset:1px}
.jarvis-premium .jarvis-sidebar nav a[hidden],.jarvis-premium .jarvis-sidebar nav p[hidden]{display:none!important}
.jarvis-premium .jarvis-nav-empty{display:none;margin:8px 16px;color:#9da9c7;font-size:13px}
.jarvis-premium .jarvis-nav-empty.is-visible{display:block}
.jarvis-premium .jarvis-main :is(button,a){transition:background-color .16s,border-color .16s,filter .16s}
@media(max-width:767px){.jarvis-premium .jarvis-main{padding:16px!important}.jarvis-premium .jarvis-page-heading{flex-direction:column;align-items:flex-start;gap:14px}.jarvis-premium .jarvis-main :is(.grid){min-width:0}.jarvis-premium .jarvis-main table{font-size:12px}}
@media(prefers-reduced-motion:reduce){.jarvis-premium *{animation:none!important;transition:none!important}}


/* JARVIS ONE — module-aware bespoke layouts: preserves existing server-rendered content */
.jarvis-premium .jarvis-module-hero{position:relative;overflow:hidden;isolation:isolate;display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:24px;padding:27px 30px;border:1px solid color-mix(in srgb,var(--module-accent,#a78bfa) 34%,#303b58);border-radius:22px;background:linear-gradient(115deg,color-mix(in srgb,var(--module-accent,#a78bfa) 15%,#111a2e),#111a2e 70%);box-shadow:0 18px 44px #03071240}
.jarvis-premium .jarvis-module-hero:after{content:'';position:absolute;z-index:-1;right:-60px;top:-115px;width:310px;height:310px;border-radius:50%;background:radial-gradient(circle,color-mix(in srgb,var(--module-accent,#a78bfa) 20%,transparent),transparent 70%);pointer-events:none}
.jarvis-premium .jarvis-module-hero__identity{display:flex;align-items:center;gap:18px;min-width:0}
.jarvis-premium .jarvis-module-hero__glyph{display:grid;place-items:center;flex:none;width:55px;height:55px;border-radius:16px;background:color-mix(in srgb,var(--module-accent,#a78bfa) 20%,#111a2e);border:1px solid color-mix(in srgb,var(--module-accent,#a78bfa) 45%,#293450);color:var(--module-accent,#a78bfa);font-size:26px}
.jarvis-premium .jarvis-module-hero__eyebrow{font-size:10px;font-weight:750;letter-spacing:.16em;color:var(--module-accent,#a78bfa);margin-bottom:7px}
.jarvis-premium .jarvis-module-hero h1{font-size:clamp(1.45rem,2.3vw,2rem);line-height:1.2;margin:0;color:#f4f5ff}
.jarvis-premium .jarvis-module-hero p{color:#aab7d2;margin:9px 0 0;font-size:13px}
.jarvis-premium .jarvis-module-hero__section{font-size:11px;font-weight:700;color:#d6ddf4;border:1px solid #435071;border-radius:99px;padding:8px 13px;white-space:nowrap}
.jarvis-premium [data-jarvis-module=chat]{--module-accent:#b59aff}
.jarvis-premium [data-jarvis-module=agents],.jarvis-premium [data-jarvis-module=jobs],.jarvis-premium [data-jarvis-module=skills]{--module-accent:#65d8eb}
.jarvis-premium [data-jarvis-module=leads],.jarvis-premium [data-jarvis-module=clients],.jarvis-premium [data-jarvis-module=proposals]{--module-accent:#4adea4}
.jarvis-premium [data-jarvis-module=ads],.jarvis-premium [data-jarvis-module=gads],.jarvis-premium [data-jarvis-module=social],.jarvis-premium [data-jarvis-module=seo]{--module-accent:#fbbf71}
.jarvis-premium [data-jarvis-module=accounting],.jarvis-premium [data-jarvis-module=billing],.jarvis-premium [data-jarvis-module=orders]{--module-accent:#75b8ff}
.jarvis-premium [data-jarvis-module=studio],.jarvis-premium [data-jarvis-module=video],.jarvis-premium [data-jarvis-module=photoshop],.jarvis-premium [data-jarvis-module=characters]{--module-accent:#f29be3}
.jarvis-premium [data-jarvis-module=admin]{--module-accent:#f6a8a8}
/* Chat: distinct two-panel conversation experience */
.jarvis-premium #chat{gap:18px;align-items:stretch}
.jarvis-premium #chat>aside{padding:15px;background:#10192c;border:1px solid #303c59;border-radius:18px}
.jarvis-premium #chat>section{background:#11192b!important;border:1px solid #33405e;border-radius:18px;overflow:hidden;box-shadow:0 16px 45px #0004}
.jarvis-premium #chat #chat-thread{background:radial-gradient(circle at 70% 10%,#29204c44,transparent 60%);min-height:330px}
.jarvis-premium #chat #chat-thread .msg[data-role=user]>div{background:linear-gradient(120deg,#6c49c7,#5142a3)!important;color:#fff!important;border-radius:17px 17px 5px 17px}
.jarvis-premium #chat #chat-thread .msg[data-role=assistant]>div{background:#202a43!important;border:1px solid #354463;border-radius:17px 17px 17px 5px}
.jarvis-premium #chat #chat-form{background:#141e34;border-color:#344260}
.jarvis-premium #chat #chat-input{background:#0e172a!important;border:1px solid #435070!important;border-radius:12px}
/* Data-heavy CRM / commerce / accounting pages */
.jarvis-premium .jarvis-main :is(table){border-collapse:separate;border-spacing:0;width:100%;background:#111a2d;border:1px solid #33405d;border-radius:13px;overflow:hidden}
.jarvis-premium .jarvis-main :is(table) th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#bdc9e5;background:#1b2741}
.jarvis-premium .jarvis-main :is(table) td{border-top:1px solid #27344e}
.jarvis-premium .jarvis-main :is(form):not(#chat-form){accent-color:#a78bfa}
.jarvis-premium .jarvis-main :is(input:not([type=checkbox]):not([type=radio]),textarea,select){background:#111b2f;color:#edf0ff;border:1px solid #394763;border-radius:10px}
.jarvis-premium .jarvis-main :is(input,textarea)::placeholder{color:#8794b1}
.jarvis-premium .jarvis-main :is(details){border-color:#354360}
@media(max-width:640px){.jarvis-premium .jarvis-module-hero{padding:20px 17px;align-items:flex-start}.jarvis-premium .jarvis-module-hero__identity{gap:11px}.jarvis-premium .jarvis-module-hero__glyph{width:42px;height:42px;font-size:20px}.jarvis-premium .jarvis-module-hero__section{display:none}.jarvis-premium #chat{display:block}.jarvis-premium #chat>aside{margin-bottom:14px}}


/* Phase 4: individually identified module pages and module-specific layouts */
.jarvis-premium .jarvis-module-hero{--module:#a78bfa;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:18px;min-height:138px;margin-bottom:24px;padding:25px 28px;border:1px solid color-mix(in srgb,var(--module) 36%,#303955);border-radius:22px;background:radial-gradient(ellipse at 92% -35%,color-mix(in srgb,var(--module) 20%,transparent),transparent 65%),linear-gradient(115deg,#1b2440,#10182b);box-shadow:0 20px 50px #0002}
.jarvis-premium .jarvis-accent-violet{--module:#ae8aff}.jarvis-premium .jarvis-accent-cyan{--module:#5bd8ea}.jarvis-premium .jarvis-accent-orange{--module:#ffb56b}.jarvis-premium .jarvis-accent-pink{--module:#ff83c6}.jarvis-premium .jarvis-accent-green{--module:#6de0b1}.jarvis-premium .jarvis-accent-blue{--module:#83aaff}
.jarvis-premium .jarvis-module-hero__identity{display:flex;align-items:center;gap:19px;min-width:0}.jarvis-premium .jarvis-module-hero__glyph{flex:none;display:grid;place-items:center;width:64px;height:64px;border:1px solid color-mix(in srgb,var(--module) 55%,transparent);border-radius:19px;background:color-mix(in srgb,var(--module) 14%,#121a30);color:var(--module);font-size:32px;box-shadow:inset 0 1px #ffffff12}
.jarvis-premium .jarvis-module-hero__eyebrow{font-size:10px;font-weight:750;letter-spacing:.17em;color:var(--module);margin-bottom:7px}.jarvis-premium .jarvis-module-hero__eyebrow span{margin:0 8px;color:#66718c}.jarvis-premium .jarvis-module-hero h1{font-size:clamp(1.2rem,2vw,1.85rem);line-height:1.25;margin:0 0 7px;color:#fff!important}.jarvis-premium .jarvis-module-hero p{color:#aebad4;font-size:13px;line-height:1.5;margin:0;max-width:590px}.jarvis-premium .jarvis-module-hero__section{flex:none;padding:8px 13px;border-radius:99px;border:1px solid #414965;background:#ffffff0a;color:#cbd5ed;font-size:11px;font-weight:650}
.jarvis-premium .jarvis-main:has([data-jarvis-page^=chat-]) #chat{gap:20px}.jarvis-premium .jarvis-main:has([data-jarvis-page^=chat-]) #chat>aside{border:1px solid #303955;border-radius:18px;background:#131b30;padding:15px}.jarvis-premium .jarvis-main:has([data-jarvis-page^=chat-]) #chat>section{border:1px solid #3c3b65;border-radius:20px;overflow:hidden}
.jarvis-premium .jarvis-main:has([data-jarvis-page^=admin-]) table{border-radius:14px;overflow:hidden}.jarvis-premium .jarvis-main:has([data-jarvis-page^=tasks-]) :is(input[type=checkbox]){accent-color:#6de0b1}.jarvis-premium .jarvis-main:has([data-jarvis-page^=studio-]) :is(img){border-radius:12px}.jarvis-premium .jarvis-main:has([data-jarvis-page^=ads-]) :is(table){font-variant-numeric:tabular-nums}
@media(max-width:640px){.jarvis-premium .jarvis-module-hero{padding:19px;min-height:110px}.jarvis-premium .jarvis-module-hero__glyph{width:47px;height:47px;font-size:24px}.jarvis-premium .jarvis-module-hero__identity{gap:12px}.jarvis-premium .jarvis-module-hero__section{display:none}}


/* JARVIS ONE page patterns — non-destructive progressive enhancement */
.jarvis-premium .jarvis-main{--jp-focus:#a78bfa}
.jarvis-premium .jarvis-main :is(a,button,input,select,textarea):focus-visible{outline:2px solid var(--jp-focus)!important;outline-offset:3px}
.jarvis-premium .jarvis-main a:not(.jarvis-module-hero a){transition:color .18s ease,background .18s ease}
.jarvis-premium .jarvis-main :is(button,[type=submit]){transition:filter .15s ease,transform .15s ease}
.jarvis-premium .jarvis-main :is(button,[type=submit]):not(:disabled):active{transform:translateY(1px)}
.jarvis-premium .jarvis-main :is(button,[type=submit]):disabled{opacity:.48;cursor:not-allowed}
.jarvis-premium .jarvis-main .overflow-x-auto{scrollbar-width:thin;scrollbar-color:#526080 #12192b}
.jarvis-premium .jarvis-main :is(table) tbody tr:hover{background:#25325288}
.jarvis-premium .jarvis-main :is(table) th,.jarvis-premium .jarvis-main :is(table) td{padding:12px 14px}
.jarvis-premium .jarvis-main :is(input[type=checkbox],input[type=radio]){accent-color:#a78bfa;min-height:auto}
.jarvis-premium .jarvis-main :is(.text-red-600,.text-red-500){color:#fda4af!important}
.jarvis-premium .jarvis-main :is(.text-green-600,.text-green-500){color:#86efac!important}
.jarvis-premium .jarvis-main :is(.bg-yellow-50,.bg-amber-50){background:#382c18!important;color:#fcdca6!important}
.jarvis-premium .jarvis-main :is(.bg-indigo-600,.bg-blue-600){background:linear-gradient(125deg,#7855dc,#5545b9)!important;color:white!important}
.jarvis-premium .jarvis-main :is(.bg-indigo-600,.bg-blue-600):hover{filter:brightness(1.13)}
.jarvis-premium .jarvis-main [data-jarvis-module=chat] + #chat #chat-list a{border-radius:10px;padding:9px 11px}
.jarvis-premium .jarvis-main :is(pre,code){border-radius:8px}
.jarvis-premium .jarvis-main pre{max-width:100%;overflow-x:auto}
.jarvis-premium .jarvis-main :is(dialog,[role=dialog]){background:#151d33;color:#eef2ff;border:1px solid #414d6d;border-radius:16px}
.jarvis-premium .jarvis-main :is(.grid) > :is(.rounded-xl,.rounded-lg,.bg-white){min-width:0}
.jarvis-premium .jarvis-module-hero__section{white-space:nowrap}
.jarvis-premium .jarvis-module-hero__identity{overflow-wrap:anywhere}
.jarvis-premium .jarvis-main :is(.text-gray-300,.text-gray-200){color:#d6ddf0!important}
@media(max-width:767px){.jarvis-premium .jarvis-main{padding:14px!important}.jarvis-premium .jarvis-main :is(table) th,.jarvis-premium .jarvis-main :is(table) td{padding:9px 10px}.jarvis-premium .jarvis-module-hero{margin-bottom:15px}}
@media(prefers-reduced-motion:reduce){.jarvis-premium *, .jarvis-premium *::before,.jarvis-premium *::after{animation-duration:.01ms!important;transition-duration:.01ms!important;scroll-behavior:auto!important}}

/* a page that has its own module header does not need the generic one */
.jarvis-premium .jarvis-main:has(.jarvis-module-hero) .jarvis-page-heading{display:none}
.jarvis-premium .jarvis-main .jarvis-upload{background:#171e3b!important;border-color:#5b4f9a!important}
.jarvis-premium .jarvis-main .jarvis-upload:hover{border-color:#a78bfa!important;background:#1d2547!important}
.jarvis-premium .jarvis-sidebar details.jarvis-sub>summary{list-style:none;display:flex;justify-content:space-between;align-items:center;margin:3px 7px;padding:10px 13px;border-radius:10px;color:#aab4d2;font-weight:500;cursor:pointer}
.jarvis-premium .jarvis-sidebar details.jarvis-sub>summary::-webkit-details-marker{display:none}
.jarvis-premium .jarvis-sidebar details.jarvis-sub>summary::after{content:'▸';font-size:11px;color:#777fa3;transition:transform .15s}
.jarvis-premium .jarvis-sidebar details.jarvis-sub[open]>summary::after{transform:rotate(90deg)}
.jarvis-premium .jarvis-sidebar details.jarvis-sub>summary:hover{background:#252944;color:#fff}
.jarvis-premium .jarvis-sidebar details.jarvis-sub a{margin:2px 7px;padding:6px 11px!important;font-size:12.5px}
</style>
