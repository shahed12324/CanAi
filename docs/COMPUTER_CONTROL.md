# Computer Control — সার্ভারের নিয়ম (ফেজ ২৬ক)

সার্ভার কিছুই **চালায় না**; ডেস্কটপ এজেন্ট অনুমোদিত কাজ **টেনে নেয়** (pull)। ডাটাবেস সিদ্ধান্ত নেয় কাজটি blocked / অনুমোদন-অপেক্ষায় / স্বয়ংক্রিয়-অনুমোদিত, আর সব কিছুর অডিট রাখে।

## HTTP API (এজেন্ট) — `/api/pc/v1`
| মেথড/পথ | কাজ |
|---|---|
| `POST pair` `{code}` | এককালীন কোড → `{device_id, token}` (৪২২ `invalid_code`; ১০/মিনিট) |
| `GET actions/next` | পরের অনুমোদিত কাজ `{action: {id, kind, risk, params}|null}` |
| `POST actions/{id}/report` `{ok, result}` | ফল জানায়; `running` ছাড়া ৪০৯ |
| `POST metrics` `{cpu, mem, disk, gpu?, battery?, temp_c?, charging?}` | নমুনা (৫ সেকেন্ডে ১টি, নইলে ৪২৯) |
টোকেন: `Authorization: Bearer <৬৪ hex>`। ওয়েব পাতা: `/app/computer`।

## পেয়ারিং
ওয়েব → `pc_pair_create` (১০ মিনিটের এককালীন কোড, শুধু sha256 সংরক্ষিত; ইউজারপ্রতি সর্বোচ্চ ৫ ডিভাইস/৫ পেন্ডিং) → QR/টেক্সট → এজেন্টে কোড দিলে `pc_pair_redeem` ডিভাইস বানায়, **টোকেন একবারই** দেখায় (শুধু sha256 সংরক্ষিত)। ওয়েব রোল `token_hash`/`code_hash` পড়তে পারে না (কলাম-অনুমতি)। `pc_device_revoke` টোকেন মুছে দেয় ও খোলা কাজ থামায়।

## নিয়ম (policy) — ডিভাইসপ্রতি
- `roots` অনুমোদিত ফোল্ডার (পুরো ড্রাইভ/`/` নিষিদ্ধ; `..` নিষিদ্ধ), `apps`, `commands` (শুধু প্রোগ্রামের নাম, shell নয়), `hosts` (https ও সাবডোমেইন), `modes` (`deny|ask|allow` প্রতি কাজের ধরনে)। **খালি তালিকা = কিছুই অনুমোদিত নয়।**
- `write_file`-এর `params`: `path` (≤৫২০) ও `content` (ওয়ার্কফ্লো থেকে ≤২৫০০ অক্ষর; DB-তে params মোট ≤৪০০০)। ভবিষ্যৎ ডেস্কটপ এজেন্টকে `params.content` সম্মান করতে হবে। ওয়ার্কফ্লো-টুল (`pc.*`) শুধু অনুরোধ জমা দেয়, অনুমোদন মানুষ করে।
- ঝুঁকি: low = `read_file, list_dir`; medium = `write_file, open_app, browser_navigate, system_setting`; high = `delete_file, run_command, input_control`; critical = `power`।
- `high`/`critical` কখনো `allow` হতে পারে না (সংরক্ষণেই বাতিল, এবং চালানোর সময়ও মানুষের অনুমোদন লাগে)। `allow` স্কোপ বাড়ায় না।

## কাজের জীবনচক্র
`pc_action_request` → `blocked`(কারণসহ) | `pending` | `approved` → (`pc_action_decide`: ডিভাইসের মালিক approve/reject) → `pc_action_next` (এজেন্ট টানে, `running`) → `pc_action_report` (`done|failed`)। ১০ মিনিটে মেয়াদ শেষ। অবস্থা শুধু সামনে যায় (ট্রিগার); কাজ মোছা/বদলানো যায় না।
**ইমার্জেন্সি স্টপ** (ফেজ ১১-র `emergency_events`) সবার ওপরে: নতুন অনুরোধ blocked, খোলা কাজ `stopped`, এজেন্ট কিছু পায় না, অনুমোদনও stopped হয়।

## এজেন্টের দায়িত্ব (গভীর সুরক্ষা — `apps/pc-agent`-এ লেখা ☑)
- সার্ভারের সিদ্ধান্ত মানার পরও **নিজের মেশিনে আবার** পথ/কমান্ড যাচাই করবে (symlink সমাধান করে `roots`-এর ভেতরে কি না), shell ছাড়া `exec` করবে, `args` আক্ষরিক পাঠাবে।
- প্রতিবার `pc_action_next` ডাকবে; `running` হওয়ার আগে কিছু করবে না; শেষে `pc_action_report`।
- মেট্রিক্স ≥৫ সেকেন্ড অন্তর (`pc_metrics_add`); ৭ দিন পর `pc:purge-metrics` মোছে।

## উচ্চ-ঝুঁকিতে মোবাইল step-up (০.৯৯.০)
- `high`/`critical` কাজ ওয়েবে approve করলে `mobile_required`; reject চলে। মোবাইলে `GET /api/v1/mobile/pc/actions` ও `POST /api/v1/mobile/pc/actions/{id}` — approve-এ পিন step-up লাগে (`step_up_required` ৪০৩)।
- মোবাইল অ্যাপ: আরও → আমার কম্পিউটার (`apps/mobile/app/computer.tsx`)।
- এজেন্টের জন্য নিয়ম: উচ্চ-ঝুঁকির কাজ `pc_action_next` থেকে তখনই আসে যখন `decided_via = 'mobile'`; এজেন্ট তবু নিজের মেশিনে আবার যাচাই করবে।

## যা এখনো নেই
ব্রাউজার এজেন্ট (পেজ-ক্লিক/ফর্ম), GUI/ট্রে ডেস্কটপ অ্যাপ (Tauri), power/input_control/system_setting। (কমান্ড-লাইন এজেন্ট ☑: `apps/pc-agent`, পিসি/ল্যাপটপ/VPS।) (মোবাইল→কম্পিউটার হ্যান্ডঅফ: `POST /api/v1/mobile/pc/request`, শুধু read_file/list_dir/open_app/browser_navigate।)

## লোকাল টাস্ক ইঞ্জিন (CAN AI মাইলস্টোন C, ২০২৬-১১-১৪) — শুধু এজেন্টে, সার্ভার এখনো জানে না
`apps/pc-agent`: `python -m jarvis_agent task plan.json [--resume]` (মানুষ টার্মিনালে অনুমোদন দেয়; non-TTY হলে প্রত্যাখ্যান)। প্ল্যান ≤২০ ধাপ, `{{sN.out}}` শুধু আগের ধাপ থেকে। প্ল্যান ডাইজেস্ট অনুমোদন → প্রতি high-risk/overwrite/বাইরের-তথ্য-নির্ভর ধাপে আলাদা HMAC-সই, মেয়াদি, একবার-ব্যবহার্য, ডিভাইস-বাঁধা অনুমোদন। প্রতি ধাপের পর `verify`; রিপোর্ট শুধু ধাপের আসল অবস্থা থেকে। `python -m jarvis_agent stop` / `resume` = জরুরি স্টপ (সার্ভার-কাজও থামে, চলমান চাইল্ড প্রসেস কিল)। নতুন kinds: `copy_file, move_file, make_dir, trash_file, create_xlsx, create_docx` (ডিফল্টে বন্ধ)। অডিট: `home/audit.jsonl` (হ্যাশ-চেইন)। Windows-এ যাচাই হয়নি।

## নতুন ৬টি kind — সার্ভারের নিয়ম (ফেজ ২৬গ)
`copy_file`, `make_dir`, `create_xlsx`, `create_docx` = medium; `move_file`, `trash_file` = high (কখনো `allow` নয়, প্রতিবার মানুষের অনুমোদন)। `copy_file`/`move_file`-এ `src` ও `dst` **দুটোই** অনুমোদিত ফোল্ডারের ভেতরে হতে হবে; বাকিগুলোতে `path`। `overwrite` থাকলে boolean। ওয়েব ফর্মে কপি/মুভ: `from -> to`। মাইগ্রেশন: `2026_12_31_100000_pc_file_kinds` (রোলব্যাকে নতুন kind-এর সারি থাকলে অস্বীকার করে — অডিট ট্রেইল)।

## প্ল্যানার (ফেজ ২) — ai-engine `POST /internal/v1/plan`
ইনপুট: `provider, model, goal (≤২০০০), roots, kinds`। আউটপুট: `{status: ok, plan, review[]}` | `invalid` | `refused`। মডেলের উত্তর অবিশ্বাস্য: শুধু অনুমোদিত kind (run_command/ব্রাউজার/ইনপুট/পাওয়ার নেই), প্রতি kind-এর পরামিতি-স্কিমা, পথ absolute ও roots-এর ভেতরে, `..` নিষিদ্ধ, `{{sN.out}}` শুধু আগের ধাপ থেকে, ≤১২ ধাপ। প্ল্যানের id সার্ভার বানায়। একবার কারণসহ পুনর্চেষ্টা; দুবারই ভুল হলে `invalid` (কখনো আধা-প্ল্যান নয়)। এজেন্টে: `python -m jarvis_agent task reply.json --dry-run`। বাকি: Laravel থেকে কল + ওয়েব রিভিউ পাতা (ফেজ ৩)।

## প্ল্যান ও সই-করা অনুমোদন (ফেজ ২৬ঘ)
ওয়েবে `/app/computer` → "Plans": প্ল্যান JSON জমা দিন (প্ল্যানারের পুরো উত্তরও চলে)। ডাটাবেস প্রতিটি ধাপ ডিভাইসের নিয়মে যাচাই করে; একটিও ভুল হলে পুরো প্ল্যান `blocked`। আপনি প্ল্যান অনুমোদন করেন; যেসব ধাপ **high/critical** (move, trash …) সেগুলো শুধু ফোনে PIN দিয়ে (`POST api/mobile/pc/plans/{id}/step`); ওভাররাইট-ধাপ ওয়েবেও চলে। এজেন্ট `GET plans/next` টানলে ডাটাবেস সই দেয়: `HMAC-SHA256(key = sha256(টোকেন), "canai-plan|device|plan|sha256(plan_text)|exp")` ও অনুমোদিত ধাপগুলোর আলাদা সই; মেয়াদ ৫ মিনিট, একবারই দেওয়া হয়। এজেন্ট ঠিক যে লেখা পেয়েছে সেটাই যাচাই করে চালায়; ভুল ডিভাইস/মেয়াদ/রিপ্লে/বদলানো লেখা প্রত্যাখ্যান; লোকাল নিয়ম ও লোকাল জরুরি-স্টপ আগের মতোই কাজ করে। রিপোর্ট: `POST plans/{id}/report {state: done|failed|stopped}`।

## Companion / Agent মোড (ফেজ ২৬ঙ)
`/app/computer`-এর ওপরে সুইচ। **Companion** = শুধু চ্যাট/ভয়েস: নতুন জব ও প্ল্যান `companion_mode` কারণে blocked, অনুমোদিত-কিন্তু-না-নেওয়া প্ল্যান এজেন্টকে দেওয়া হয় না, আর মোডে ঢোকার সময় আপনার অপেক্ষমাণ সব জব/প্ল্যান বাতিল হয়। **Agent** = আগের নিয়ম (ডিভাইস পলিসি, অনুমোদন, উচ্চ-ঝুঁকিতে ফোনে PIN)। সারি না থাকলে Agent (কারো বর্তমান সেটআপ বদলায় না)। চ্যাটের সিস্টেম-প্রম্পটে মোড-অনুযায়ী সততার নিয়ম যোগ হয়; Companion মোডে নতুন চ্যাট শুরু হলে Companion-এর নিজস্ব ক্যারেক্টার ডিফল্ট। সীমা: PC-তে ইতিমধ্যে চলতে থাকা প্ল্যান সার্ভার ফেরাতে পারে না — PC-র `stop` লাগে।

## স্ক্রিন, মাউস-কিবোর্ড, ওয়েবপাতা পড়া (ফেজ ২৬চ)
- `screenshot` (high): `{path: "….png", region?: [x,y,w,h], overwrite?}` — অনুমোদিত ফোল্ডারে PNG সেভ হয়; পাসওয়ার্ড/ব্যাংক/সিকিউরিটি উইন্ডো হলে নেয় না। ছবি আপলোড বা পড়া হয় না।
- `input_control` (high, ফোনে PIN): `type/key/click/move/scroll`। সামনের উইন্ডো অবশ্যই নিয়মে অনুমোদিত অ্যাপ হতে হবে; ক্লিক সেই উইন্ডোর ভেতরে; `alt+f4`, `ctrl+alt+del`, win-কী নিষিদ্ধ; মিনিটে ৩০; জরুরি স্টপে বন্ধ। টাইপ করা লেখা রিপোর্টে আসে না।
- `browser_extract` (medium): শুধু https + অনুমোদিত হোস্ট; অন্য হোস্টে রিডাইরেক্ট হলে বাতিল; ফল "বাইরের তথ্য" — নির্দেশ হিসেবে কাজ করে না।
- এজেন্টের নিয়মে kind চালু না করলে কিছুই চলে না। প্রকৃত ডিভাইস কোড (`pip install mss pyautogui`) Windows-এ পরীক্ষিত নয়।

## জরুরি স্টপ চলমান কাজে ও Companion মোড (০.১২৫)
- ওয়েব/ফোনে জরুরি স্টপ চাপলে এজেন্ট (প্ল্যান বা কাজ চলার সময়) ২ সেকেন্ড পরপর `GET /api/pc/v1/emergency` দেখে; `true` হলে চলমান প্রোগ্রাম বন্ধ, লোকাল STOP চিহ্ন বসে। সার্ভার "ছেড়ে দিলেও" এজেন্ট নিজে চালু হয় না — `jarvis-agent resume` লাগে। সার্ভারে যাওয়া না গেলে কাজ নিজে থেকে থামে না (লোকাল নিয়ম ও অনুমোদন তখনও কাজ করে)।
- `jarvis-agent mode companion|agent`: companion-এ এই কম্পিউটার কোনো কাজ নেয় না/চালায় না।
- প্যাকেজিং: `apps/pc-agent/packaging/`; যাচাই: `jarvis-agent selfcheck` ও `docs/WINDOWS_VERIFICATION.md`।

## অডিট-চেইন সিঙ্ক (0.143.0)
| রুট (ডিভাইস-টোকেন) | কাজ |
|---|---|
| `GET audit/head` | `{status, seq, h}`: সার্ভারে জমানো কপি কোথায় শেষ (seq = সারির সংখ্যা, h = শেষ hash; ফাঁকা হলে ৬৪টি শূন্য) |
| `POST audit/sync` `{start, rows[1..200]}` | `[start, start+n)` সারি যোগ করে। 200 ok · 409 `seq_mismatch`/`chain_broken` (বডিতে আসল head) · 422 `bad_input` · 404 অজানা ডিভাইস। ডাটাবেস `pc_audit_ingest` প্রতিটি hash নিজে হিসাব করে, শৃঙ্খল যাচাই করে, সব-অথবা-কিছুই-না, শুধু যোগ (append-only)। |
