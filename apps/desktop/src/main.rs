//! JARVIS ONE desktop shell.
//!
//! It owns no logic of its own: it starts `jarvis-agent gui --no-browser` (the agent's local window, served only on 127.0.0.1),
//! reads the one URL that process prints, and shows it in a native window with a tray icon.  Closing the window hides it to the
//! tray; "Quit" in the tray menu stops the agent process and quits.  The page it shows gets no Tauri API (see capabilities).
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use std::io::{BufRead, BufReader};
use std::process::{Child, Command, Stdio};
use std::sync::{Arc, Mutex};

use tauri::menu::{Menu, MenuItem};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager, WebviewWindow, WindowEvent};

/// Accept only what the agent really prints: a plain-http loopback URL with a port and a `#token`.
pub fn parse_agent_url(line: &str) -> Option<String> {
    let start = line.find("http://127.0.0.1:")?;
    let url: String = line[start..].chars().take_while(|c| !c.is_whitespace()).collect();
    let rest = url.strip_prefix("http://127.0.0.1:")?;
    let (port, frag) = rest.split_once("/#")?;
    let port_ok = !port.is_empty() && port.len() <= 5 && port.chars().all(|c| c.is_ascii_digit());
    let tok_ok = frag.len() >= 16 && frag.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_');
    if port_ok && tok_ok { Some(url) } else { None }
}

/// The command that starts the agent window: `$JARVIS_AGENT_BIN` (a path) or `jarvis-agent`, falling back to `python3 -m jarvis_agent`.
pub fn agent_command(bin: Option<String>) -> (String, Vec<String>) {
    let tail = vec!["gui".to_string(), "--no-browser".to_string(), "--exit-with-parent".to_string()];
    match bin {
        Some(b) if !b.trim().is_empty() => (b, tail),
        _ => ("jarvis-agent".to_string(), tail),
    }
}

/// An installed app ships the agent next to itself (a Tauri sidecar). Prefer that over whatever is on PATH, so the installer works
/// on a PC with no Python. `JARVIS_AGENT_BIN` still wins (developers, tests).
pub fn sidecar_bin(exe_dir: Option<&std::path::Path>, windows: bool) -> Option<String> {
    let dir = exe_dir?;
    let p = dir.join(if windows { "jarvis-agent.exe" } else { "jarvis-agent" });
    if p.is_file() { p.to_str().map(|s| s.to_string()) } else { None }
}

fn spawn_agent() -> std::io::Result<Child> {
    let exe_dir = std::env::current_exe().ok().and_then(|p| p.parent().map(|d| d.to_path_buf()));
    let bin = std::env::var("JARVIS_AGENT_BIN").ok().filter(|b| !b.trim().is_empty()).or_else(|| sidecar_bin(exe_dir.as_deref(), cfg!(windows)));
    let (prog, args) = agent_command(bin);
    let first = Command::new(&prog).args(&args).stdin(Stdio::piped()).stdout(Stdio::piped()).stderr(Stdio::null()).spawn();
    match first {
        Ok(c) => Ok(c),
        Err(_) => {
            let py = if cfg!(windows) { "python" } else { "python3" };
            Command::new(py).args(["-m", "jarvis_agent"]).args(&args).stdin(Stdio::piped()).stdout(Stdio::piped()).stderr(Stdio::null()).spawn()
        }
    }
}

/// Where the agent keeps its settings: `$JARVIS_AGENT_HOME`, else `~/.jarvis-agent` (same rule as the agent itself).
pub fn agent_home(env_home: Option<String>, home: Option<String>) -> Option<std::path::PathBuf> {
    match env_home.filter(|h| !h.trim().is_empty()) {
        Some(h) => Some(std::path::PathBuf::from(h)),
        None => home.filter(|h| !h.trim().is_empty()).map(|h| std::path::PathBuf::from(h).join(".jarvis-agent")),
    }
}

/// The person's "always on top" choice from the agent's config.json (`prefs.always_on_top`).  Anything unreadable means "no change".
pub fn always_on_top_pref(config_json: &str) -> Option<bool> {
    let v: serde_json::Value = serde_json::from_str(config_json).ok()?;
    Some(v.get("prefs")?.get("always_on_top")?.as_bool()?)
}

/// The person's "small floating window" choice (`prefs.compact`).  Anything unreadable means "no change" (None).
pub fn compact_pref(config_json: &str) -> Option<bool> {
    let v: serde_json::Value = serde_json::from_str(config_json).ok()?;
    Some(v.get("prefs")?.get("compact")?.as_bool()?)
}

/// Window size for a mode, logical pixels: compact is a narrow floating panel, normal is the full window.
pub fn window_size(compact: bool) -> (f64, f64) {
    if compact { (360.0, 560.0) } else { (1100.0, 760.0) }
}

fn watch_always_on_top(win: WebviewWindow) {
    std::thread::spawn(move || {
        let home = std::env::var("HOME").ok().or_else(|| std::env::var("USERPROFILE").ok());
        let Some(dir) = agent_home(std::env::var("JARVIS_AGENT_HOME").ok(), home) else { return };
        let mut last = false;
        let mut last_compact = false;
        loop {
            // no key stored = the default (off); a stored false turns it off again
            let want = std::fs::read_to_string(dir.join("config.json")).ok().map(|t| always_on_top_pref(&t).unwrap_or(false)).unwrap_or(false);
            if want != last {
                if win.set_always_on_top(want).is_ok() {
                    last = want;
                }
            }
            let cfg = std::fs::read_to_string(dir.join("config.json")).ok();
            let compact = cfg.as_deref().and_then(compact_pref).unwrap_or(false);
            if compact != last_compact {
                let (w, h) = window_size(compact);
                if win.set_size(tauri::LogicalSize::new(w, h)).is_ok() {
                    last_compact = compact;
                }
            }
            std::thread::sleep(std::time::Duration::from_secs(2));
        }
    });
}

fn show(win: &WebviewWindow) {
    let _ = win.show();
    let _ = win.unminimize();
    let _ = win.set_focus();
}

fn quit(app: &AppHandle, child: &Arc<Mutex<Option<Child>>>) {
    if let Ok(mut g) = child.lock() {
        if let Some(mut c) = g.take() {
            let _ = c.kill();
            let _ = c.wait();
        }
    }
    app.exit(0);
}

/// Crash report text for a panic in this shell: only the app version, the OS, the code location and a short message.  No config, no token, no URL (the agent URL carries a secret).
pub fn crash_report_text(version: &str, os: &str, location: &str, message: &str, unix_time: u64) -> String {
    let clean = |s: &str, n: usize| -> String {
        s.chars().filter(|c| !c.is_control()).take(n).collect::<String>().replace('"', "'").replace("http://127.0.0.1", "[local-url]")
    };
    format!(
        "{{\"kind\":\"shell\",\"version\":\"{}\",\"os\":\"{}\",\"time\":{},\"where\":\"{}\",\"message\":\"{}\"}}\n",
        clean(version, 20), clean(os, 20), unix_time, clean(location, 120), clean(message, 200)
    )
}

/// Keep at most `keep` shell reports (oldest removed) in `dir`.
pub fn prune_crash_reports(dir: &std::path::Path, keep: usize) {
    let mut files: Vec<_> = std::fs::read_dir(dir).into_iter().flatten().flatten()
        .filter(|e| e.file_name().to_string_lossy().starts_with("crash-shell-")).collect();
    files.sort_by_key(|e| e.file_name());
    while files.len() > keep {
        let f = files.remove(0);
        let _ = std::fs::remove_file(f.path());
    }
}

fn install_crash_hook() {
    let previous = std::panic::take_hook();
    std::panic::set_hook(Box::new(move |info| {
        let home = std::env::var("HOME").ok().or_else(|| std::env::var("USERPROFILE").ok());
        if let Some(dir) = agent_home(std::env::var("JARVIS_AGENT_HOME").ok(), home).map(|d| d.join("crashes")) {
            let now = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0);
            let loc = info.location().map(|l| format!("{}:{}", l.file(), l.line())).unwrap_or_default();
            let msg = if let Some(m) = info.payload().downcast_ref::<&str>() { m.to_string() } else if let Some(m) = info.payload().downcast_ref::<String>() { m.clone() } else { String::new() };
            if std::fs::create_dir_all(&dir).is_ok() {
                let _ = std::fs::write(dir.join(format!("crash-shell-{now}.json")), crash_report_text(env!("CARGO_PKG_VERSION"), std::env::consts::OS, &loc, &msg, now));
                prune_crash_reports(&dir, 10);
            }
        }
        previous(info);
    }));
}

fn main() {
    install_crash_hook();
    let child: Arc<Mutex<Option<Child>>> = Arc::new(Mutex::new(None));
    let child_for_setup = child.clone();

    tauri::Builder::default()
        .setup(move |app| {
            let win = app.get_webview_window("main").expect("main window");

            let open = MenuItem::with_id(app, "open", "Open", true, None::<&str>)?;
            let stop = MenuItem::with_id(app, "quit", "Quit (and stop the agent)", true, None::<&str>)?;
            let menu = Menu::with_items(app, &[&open, &stop])?;
            let child_menu = child_for_setup.clone();
            let mut tray = TrayIconBuilder::new()
                .tooltip("CAN AI Agent")
                .menu(&menu)
                .show_menu_on_left_click(false)
                .on_menu_event(move |app, ev| match ev.id().as_ref() {
                    "open" => {
                        if let Some(w) = app.get_webview_window("main") {
                            show(&w);
                        }
                    }
                    "quit" => quit(app, &child_menu),
                    _ => {}
                })
                .on_tray_icon_event(|tray, ev| {
                    if let TrayIconEvent::Click { button: MouseButton::Left, button_state: MouseButtonState::Up, .. } = ev {
                        if let Some(w) = tray.app_handle().get_webview_window("main") {
                            show(&w);
                        }
                    }
                });
            if let Some(icon) = app.default_window_icon() {
                tray = tray.icon(icon.clone());
            }
            tray.build(app)?;

            // closing the window only hides it; the agent keeps running in the tray
            let hide = win.clone();
            win.on_window_event(move |ev| {
                if let WindowEvent::CloseRequested { api, .. } = ev {
                    api.prevent_close();
                    let _ = hide.hide();
                }
            });

            watch_always_on_top(win.clone());
            match spawn_agent() {
                Ok(mut c) => {
                    let out = c.stdout.take();
                    *child_for_setup.lock().unwrap() = Some(c);
                    let w = win.clone();
                    std::thread::spawn(move || {
                        let Some(out) = out else { return };
                        for line in BufReader::new(out).lines().map_while(Result::ok) {
                            if let Some(url) = parse_agent_url(&line) {
                                if let Ok(u) = url.parse() {
                                    let _ = w.navigate(u);
                                }
                                break;
                            }
                        }
                    });
                }
                Err(_) => {
                    let _ = win.eval("document.getElementById('m').textContent='Agent not found. Install it first: pip install ./apps/pc-agent'");
                }
            }
            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("tauri build")
        .run(move |app, ev| {
            if let tauri::RunEvent::Exit = ev {
                if let Ok(mut g) = child.lock() {
                    if let Some(mut c) = g.take() {
                        let _ = c.kill();
                    }
                }
                let _ = app;
            }
        });
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn accepts_the_agent_line() {
        let l = "Window running: http://127.0.0.1:43211/#abcDEF0123456789_-xyzABCD";
        assert_eq!(parse_agent_url(l).unwrap(), "http://127.0.0.1:43211/#abcDEF0123456789_-xyzABCD");
    }

    #[test]
    fn refuses_anything_else() {
        for l in [
            "http://evil.example.com:80/#abcDEF0123456789_-xyzABCD",
            "http://127.0.0.1.evil.com:80/#abcDEF0123456789_-xyzABCD",
            "https://127.0.0.1:43211/#abcDEF0123456789_-xyzABCD",
            "http://127.0.0.1:43211/#short",
            "http://127.0.0.1:43211/",
            "http://127.0.0.1:/#abcDEF0123456789_-xyzABCD",
            "http://127.0.0.1:123456/#abcDEF0123456789_-xyzABCD",
            "http://127.0.0.1:43a11/#abcDEF0123456789_-xyzABCD",
            "http://127.0.0.1:43211/#abcDEF0123456789_-xyz\"ABCD",
            "",
            "no url here",
        ] {
            assert!(parse_agent_url(l).is_none(), "should refuse: {l}");
        }
    }

    #[test]
    fn sidecar_is_used_only_when_the_file_exists() {
        let d = std::env::temp_dir().join(format!("jarvis-sidecar-{}", std::process::id()));
        std::fs::create_dir_all(&d).unwrap();
        assert!(sidecar_bin(Some(&d), false).is_none());
        assert!(sidecar_bin(None, false).is_none());
        std::fs::write(d.join("jarvis-agent"), b"x").unwrap();
        assert!(sidecar_bin(Some(&d), false).unwrap().ends_with("jarvis-agent"));
        assert!(sidecar_bin(Some(&d), true).is_none()); // on Windows the file must be .exe
        let _ = std::fs::remove_dir_all(&d);
    }

    #[test]
    fn always_on_top_is_read_only_from_a_real_boolean() {
        assert_eq!(always_on_top_pref(r#"{"prefs":{"always_on_top":true}}"#), Some(true));
        assert_eq!(always_on_top_pref(r#"{"prefs":{"always_on_top":false}}"#), Some(false));
        for bad in [r#"{"prefs":{"always_on_top":"yes"}}"#, r#"{"prefs":{}}"#, r#"{}"#, "not json", "", r#"{"prefs":{"always_on_top":1}}"#] {
            assert_eq!(always_on_top_pref(bad), None, "{bad}");
        }
    }

    #[test]
    fn compact_is_read_only_from_a_real_boolean_and_sizes_differ() {
        assert_eq!(compact_pref(r#"{"prefs":{"compact":true}}"#), Some(true));
        assert_eq!(compact_pref(r#"{"prefs":{"compact":false}}"#), Some(false));
        for bad in [r#"{"prefs":{"compact":1}}"#, r#"{"prefs":{}}"#, "{}", "nope", ""] { assert_eq!(compact_pref(bad), None, "{bad}"); }
        let (cw, ch) = window_size(true); let (nw, nh) = window_size(false);
        assert!(cw < nw && ch < nh && cw >= 320.0);
    }

    #[test]
    fn agent_home_follows_the_agents_own_rule() {
        assert_eq!(agent_home(Some("/x/h".into()), Some("/home/u".into())).unwrap(), std::path::PathBuf::from("/x/h"));
        assert_eq!(agent_home(None, Some("/home/u".into())).unwrap(), std::path::PathBuf::from("/home/u/.jarvis-agent"));
        assert_eq!(agent_home(Some("  ".into()), Some("/home/u".into())).unwrap(), std::path::PathBuf::from("/home/u/.jarvis-agent"));
        assert!(agent_home(None, None).is_none());
    }

    #[test]
    fn command_defaults_and_override() {
        assert_eq!(agent_command(None).0, "jarvis-agent");
        assert_eq!(agent_command(Some("  ".into())).0, "jarvis-agent");
        assert_eq!(agent_command(Some("/opt/x/jarvis-agent".into())).0, "/opt/x/jarvis-agent");
        assert_eq!(agent_command(None).1, vec!["gui", "--no-browser", "--exit-with-parent"]);
    }

    #[test]
    fn crash_report_has_no_secrets_or_control_chars() {
        let t = crash_report_text("1.0.0", "windows", "src/main.rs:10", "boom http://127.0.0.1:43211/#SECRETTOKEN0123456789\nline \"q\"", 5);
        assert!(!t.contains("SECRETTOKEN") || t.contains("[local-url]"));
        assert!(t.contains("[local-url]") && t.contains("\"kind\":\"shell\""));
        assert_eq!(t.matches('\n').count(), 1);
    }

    #[test]
    fn crash_reports_are_pruned_to_the_newest() {
        let d = std::env::temp_dir().join(format!("canai-crash-{}", std::process::id()));
        std::fs::create_dir_all(&d).unwrap();
        for i in 0..14 { std::fs::write(d.join(format!("crash-shell-{:010}.json", i)), "{}").unwrap(); }
        prune_crash_reports(&d, 10);
        let n = std::fs::read_dir(&d).unwrap().count();
        assert_eq!(n, 10);
        assert!(!d.join("crash-shell-0000000000.json").exists() && d.join("crash-shell-0000000013.json").exists());
        let _ = std::fs::remove_dir_all(&d);
    }
}
