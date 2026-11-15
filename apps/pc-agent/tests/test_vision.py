"""Phase 1: AI screen understanding.  Uses the REAL Tesseract program on screens drawn with PIL (a fake desktop), so recognition, matching and coordinate mapping are
genuinely exercised; the mouse is a recording fake.  What this cannot show: real Windows windows, UI Automation, real DPI scaling, other monitors' real behaviour."""
import io

import pytest
from PIL import Image, ImageDraw, ImageFont

from jarvis_agent import actions, coords, devices, guard, inputctl, ocr, privacy, stop, verify, vision
from jarvis_agent.config import Config

needs_ocr = pytest.mark.skipif(not ocr.available(), reason="tesseract is not installed")
def _font_path():
    import os
    for p in ("/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf", r"C:\Windows\Fonts\consola.ttf", r"C:\Windows\Fonts\arial.ttf"):
        if os.path.exists(p):
            return p
    return "DejaVuSansMono.ttf"


FONT = _font_path()


def _png(im):
    b = io.BytesIO()
    im.save(b, "PNG")
    return b.getvalue()


class Screen(devices.Backends):
    """A drawn desktop.  The virtual screen may start at a NEGATIVE x (monitor to the left of the main one); `zoom` makes screenshots bigger than the screen area."""
    def __init__(self, vs=(0, 0, 1920, 1080), win_rect=(100, 100, 900, 600), title="Report - Editor", process="editor.exe", zoom=1.0, buttons=None, extra_lines=(), tree=()):
        self.vs, self.zoom, self.calls, self.tree = vs, zoom, [], list(tree)
        self.win = {"process": process, "title": title, "rect": win_rect}
        self.fg_after_grab = None
        self.buttons = buttons if buttons is not None else {"Save": (150, 400, 330, 460), "Cancel": (400, 400, 600, 460)}      # absolute screen boxes
        im = Image.new("RGB", (vs[2], vs[3]), (255, 255, 255))
        d = ImageDraw.Draw(im)
        f = ImageFont.truetype(FONT, 28)
        l, t = win_rect[0] - vs[0], win_rect[1] - vs[1]
        d.text((l + 40, t + 30), "Quarterly report draft", fill=(0, 0, 0), font=f)
        y = t + 90
        for line in extra_lines:
            d.text((l + 40, y), line, fill=(0, 0, 0), font=f)
            y += 50
        for name, (bl, bt, br, bb) in self.buttons.items():
            d.rectangle((bl - vs[0], bt - vs[1], br - vs[0], bb - vs[1]), outline=(0, 0, 0), width=3)
            d.text((bl - vs[0] + 20, bt - vs[1] + 12), name, fill=(0, 0, 0), font=f)
        self.canvas = im

    def screen_size(self): return (self.vs[2], self.vs[3])
    def virtual_screen(self): return self.vs
    def monitors(self): return [{"id": 1, "rect": (self.vs[0], self.vs[1], self.vs[0] + self.vs[2], self.vs[1] + self.vs[3]), "scale": 1.0, "primary": True}]
    def ui_tree(self, rect): return list(self.tree)

    def grab(self, region):
        x, y, w, h = region
        self.calls.append(("grab", region))
        crop = self.canvas.crop((x, y, x + w, y + h))
        if self.zoom != 1.0:
            crop = crop.resize((round(w * self.zoom), round(h * self.zoom)), Image.LANCZOS)
        if self.fg_after_grab:
            self.win = self.fg_after_grab
        return _png(crop)

    def foreground(self): return self.win
    def move(self, x, y): self.calls.append(("move", x, y))
    def click(self, x, y, b, d): self.calls.append(("click", x, y, b, d))
    def scroll(self, dy): self.calls.append(("scroll", dy))
    def type_text(self, t): self.calls.append(("type", t))
    def press(self, k): self.calls.append(("press", tuple(k)))


@pytest.fixture(autouse=True)
def _clean():
    stop.clear()
    inputctl.reset_rate()
    vision.model_describer = None
    yield
    devices.set_backends(None)
    vision.model_describer = None
    stop.clear()
    inputctl.reset_rate()


def cfg(**kw):
    kw.setdefault("apps", ["editor"])
    kw.setdefault("kinds", ["screen_read", "screen_find", "ui_click", "screen_describe", "input_control"])
    return Config(**kw)


def clicks(be):
    return [c for c in be.calls if c[0] == "click"]


# ---- reading --------------------------------------------------------------------------------------------------------------------------------------------------
@needs_ocr
def test_screen_read_returns_the_text_and_says_which_window():
    devices.set_backends(Screen())
    ok, out = actions.execute(cfg(), {"kind": "screen_read", "params": {}})
    assert ok, out
    assert "Report - Editor" in out and "Quarterly report draft" in out and "Save" in out and "Cancel" in out
    assert "confidence" in out


@needs_ocr
def test_only_the_front_window_is_captured_not_the_whole_desktop():
    be = Screen()
    devices.set_backends(be)
    vision.screen_read(cfg(), {})
    (_, region), = [c for c in be.calls if c[0] == "grab"]
    assert region == (100, 100, 800, 500)


def test_screen_read_takes_no_parameters():
    devices.set_backends(Screen())
    ok, out = actions.execute(cfg(), {"kind": "screen_read", "params": {"x": 1}})
    assert not ok and "no parameters" in out


# ---- finding and clicking (real OCR, real coordinate mapping) ----------------------------------------------------------------------------------------------------
@needs_ocr
def test_find_gives_a_point_inside_the_real_button():
    be = Screen()
    devices.set_backends(be)
    cands = vision.find(vision.capture(cfg()), "Cancel")
    assert len(cands) == 1 and cands[0]["source"] == "ocr"
    assert coords.inside(coords.center(cands[0]["box"]), (400, 400, 600, 460))


@needs_ocr
def test_click_lands_inside_the_button_after_approval_path():
    be = Screen()
    devices.set_backends(be)
    ok, out = actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Cancel"}})
    assert ok, out
    (_, x, y, button, double), = clicks(be)
    assert coords.inside((x, y), (400, 400, 600, 460)) and button == "left" and double is False


@needs_ocr
def test_negative_monitor_origin_and_screen_zoom_still_map_correctly():
    """The window sits on a monitor LEFT of the main one (negative x) and the screenshot is 1.5x bigger than the area it covers (display scaling)."""
    be = Screen(vs=(-1920, 0, 3840, 1080), win_rect=(-1500, 100, -700, 600), zoom=1.5,
                buttons={"Save": (-1450, 400, -1270, 460), "Cancel": (-1200, 400, -1000, 460)})
    devices.set_backends(be)
    ok, out = actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Cancel"}})
    assert ok, out
    (_, x, y, *_), = clicks(be)
    assert x < 0 and coords.inside((x, y), (-1200, 400, -1000, 460))


@needs_ocr
def test_two_identical_buttons_are_never_guessed():
    be = Screen(buttons={"Save": (150, 400, 330, 460), "Save ": (400, 400, 580, 460)})
    devices.set_backends(be)
    ok, out = actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Save"}})
    assert not ok and "2 elements match" in out and clicks(be) == []
    ok, out = actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Save", "index": 2}})
    assert ok, out
    (_, x, y, *_), = clicks(be)
    assert coords.inside((x, y), (400, 400, 580, 460))                      # index counts left to right on the same row
    assert not actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Save", "index": 3}})[0]


@needs_ocr
@needs_ocr
def test_dark_button_with_light_text_and_outlined_button_both_read():
    be = Screen(buttons={"Save": (150, 400, 330, 460)})
    d = ImageDraw.Draw(be.canvas)
    d.rectangle((400, 400, 600, 460), fill=(20, 40, 120))
    d.text((420, 412), "Submit", fill=(255, 255, 255), font=ImageFont.truetype(FONT, 28))
    devices.set_backends(be)
    ob = vision.capture(cfg())
    assert vision.find(ob, "Submit") and vision.find(ob, "Save")
    assert coords.inside(coords.center(vision.find(ob, "Submit")[0]["box"]), (400, 400, 600, 460))


@needs_ocr
def test_missing_text_clicks_nothing():
    be = Screen()
    devices.set_backends(be)
    ok, out = actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Delete everything"}})
    assert not ok and "Nothing on the screen matches" in out and clicks(be) == []


def test_unsure_recognition_clicks_nothing(monkeypatch):
    be = Screen()
    devices.set_backends(be)
    words = [{"text": "Save", "conf": 40.0, "box": (50, 300, 130, 340), "line": ("1", "1", "1")}]
    monkeypatch.setattr(ocr, "read_words", lambda png, lang="eng": words)
    ok, out = actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Save"}})
    assert not ok and "not certain enough" in out and clicks(be) == []
    words[0]["conf"] = 95.0
    ok, out = actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Save"}})
    assert ok, out
    assert [c[1:3] for c in clicks(be)] == [(100 + 90, 100 + 320)]


def test_window_that_changes_before_the_click_is_not_clicked(monkeypatch):
    be = Screen()
    be.fg_after_grab = {"process": "editor.exe", "title": "Report - Editor", "rect": (120, 100, 920, 600)}       # moved while we were reading
    devices.set_backends(be)
    monkeypatch.setattr(ocr, "read_words", lambda png, lang="eng": [{"text": "Save", "conf": 95.0, "box": (50, 300, 130, 340), "line": ("1", "1", "1")}])
    ok, out = actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Save"}})
    assert not ok and "changed or moved" in out and clicks(be) == []


# ---- accessibility tree beats OCR -------------------------------------------------------------------------------------------------------------------------------
def test_accessibility_elements_are_preferred_and_disabled_ones_refused(monkeypatch):
    tree = [{"role": "button", "name": "Save", "rect": (150, 400, 330, 460), "enabled": True},
            {"role": "button", "name": "Print", "rect": (400, 400, 600, 460), "enabled": False}]
    be = Screen(tree=tree)
    devices.set_backends(be)
    monkeypatch.setattr(ocr, "read_words", lambda png, lang="eng": [{"text": "Save", "conf": 90.0, "box": (50, 300, 230, 360), "line": ("1", "1", "1")}])
    cands = vision.find(vision.capture(cfg()), "Save")
    assert len(cands) == 1 and cands[0]["source"] == "accessibility" and cands[0]["role"] == "button"      # the OCR sighting of the same thing is merged
    assert actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Save", "role": "button"}})[0]
    ok, out = actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Print"}})
    assert not ok and "disabled" in out


# ---- guards ---------------------------------------------------------------------------------------------------------------------------------------------------------
@pytest.mark.parametrize("kind,params", [("screen_read", {}), ("screen_find", {"text": "Save"}), ("ui_click", {"text": "Save"}), ("screen_describe", {})])
def test_sensitive_or_unlisted_windows_are_never_captured(kind, params):
    be = Screen(title="Sign in - Bank")
    devices.set_backends(be)
    ok, out = actions.execute(cfg(), {"kind": kind, "params": params})
    assert not ok and not [c for c in be.calls if c[0] == "grab"]
    be2 = Screen(process="chrome.exe")
    devices.set_backends(be2)
    ok, out = actions.execute(cfg(), {"kind": kind, "params": params})
    assert not ok and not [c for c in be2.calls if c[0] == "grab"]


def test_emergency_stop_and_disabled_kind_and_companion_mode():
    be = Screen()
    devices.set_backends(be)
    stop.request('test')
    assert not actions.execute(cfg(), {"kind": "ui_click", "params": {"text": "Save"}})[0]
    stop.clear()
    assert not actions.execute(cfg(kinds=["screen_read"]), {"kind": "ui_click", "params": {"text": "Save"}})[0]
    assert not actions.execute(cfg(mode="companion"), {"kind": "screen_read", "params": {}})[0]
    assert not [c for c in be.calls if c[0] in ("grab", "click")]


def test_missing_tesseract_is_explained(monkeypatch):
    devices.set_backends(Screen())
    monkeypatch.setattr(ocr, "binary", lambda: None)
    ok, out = actions.execute(cfg(), {"kind": "screen_read", "params": {}})
    assert not ok and "Tesseract" in out


def test_bad_parameters():
    devices.set_backends(Screen())
    for k, p in (("screen_find", {}), ("screen_find", {"text": ""}), ("screen_find", {"text": "x" * 121}), ("ui_click", {"text": "a", "button": "middle"}),
                 ("ui_click", {"text": "a", "min_confidence": 0.1}), ("ui_click", {"text": "a", "role": "b0"}), ("screen_describe", {"question": ""})):
        ok, out = actions.execute(cfg(), {"kind": k, "params": p})
        assert not ok, (k, p)


# ---- privacy ---------------------------------------------------------------------------------------------------------------------------------------------------------
@needs_ocr
def test_secret_looking_lines_are_not_returned_and_not_sent_to_a_model():
    be = Screen(extra_lines=["password: hunter2secret", "Meeting at noon"])
    devices.set_backends(be)
    out = vision.screen_read(cfg(), {})
    assert "hunter2secret" not in out and "Meeting at noon" in out
    seen = {}
    vision.model_describer = lambda png, q: seen.update(png=png) or "A report window"
    assert vision.screen_describe(cfg(vision_upload=True), {}) == "A report window"
    ob = vision.capture(cfg())
    boxes = privacy.secret_boxes(ob.lines)
    assert boxes
    img = Image.open(io.BytesIO(seen["png"])).convert("L")
    l, t, r, b = boxes[0]
    assert max(img.crop((l, t, r, b)).tobytes()) == 0                       # that part of the picture is solid black


@needs_ocr
def test_describe_never_calls_a_model_unless_the_owner_allowed_upload():
    devices.set_backends(Screen())
    called = []
    vision.model_describer = lambda png, q: called.append(1) or "x"
    out = vision.screen_describe(cfg(vision_upload=False), {})
    assert not called and "image upload is off" in out
    vision.model_describer = None
    assert "No vision model" in vision.screen_describe(cfg(vision_upload=True), {})


def test_vision_upload_is_off_by_default_and_toggled_only_by_policy():
    from jarvis_agent import policy
    c = Config()
    assert c.vision_upload is False
    policy.apply(c, "vision-upload-on", None)
    assert c.vision_upload is True
    policy.apply(c, "vision-upload-off", None)
    assert c.vision_upload is False
    for k in ("screen_read", "screen_find", "ui_click", "screen_describe"):
        assert k not in Config().kinds                                      # all four are off until the owner switches them on


# ---- verification ---------------------------------------------------------------------------------------------------------------------------------------------
@needs_ocr
def test_post_action_verification_reads_the_screen_again():
    devices.set_backends(Screen())
    assert verify.check(cfg(), {"type": "screen_text", "contains": "Quarterly report"})[0]
    ok, ev = verify.check(cfg(), {"type": "screen_text", "contains": "Upload complete"})
    assert not ok and "not on the screen" in ev
    assert verify.check(cfg(), {"type": "element_present", "text": "Cancel"})[0]
    assert not verify.check(cfg(), {"type": "element_present", "text": "Nonexistent"})[0]
    assert not verify.check(cfg(), {"type": "screen_text"})[0]


# ---- pure arithmetic ---------------------------------------------------------------------------------------------------------------------------------------------
def test_coordinate_mapping_and_overlap():
    shot = coords.Shot(origin=(-1500, 100), region=(800, 500), image=(1200, 750))        # 1.5x zoom
    assert coords.to_screen((150, 150, 300, 300), shot) == (-1400, 200, -1300, 300)
    assert coords.center((0, 0, 10, 10)) == (5, 5)
    assert coords.iou((0, 0, 10, 10), (0, 0, 10, 10)) == 1.0 and coords.iou((0, 0, 5, 5), (6, 6, 9, 9)) == 0.0
    mons = [{"id": 1, "rect": (0, 0, 100, 100)}, {"id": 2, "rect": (-100, 0, 0, 100)}]
    assert coords.monitor_of((-50, 10), mons)["id"] == 2 and coords.monitor_of((500, 500), mons) is None


def test_input_clicks_may_target_a_monitor_left_of_the_main_one_but_not_beyond_all_monitors():
    be = Screen(vs=(-1920, 0, 3840, 1080), win_rect=(-1900, 0, -100, 600))
    devices.set_backends(be)
    assert inputctl.input_control(cfg(), {"action": "click", "x": -500, "y": 100})
    be2 = Screen(vs=(0, 0, 1920, 1080), win_rect=(-50, 0, 400, 600))
    devices.set_backends(be2)
    with pytest.raises(guard.Refused):
        inputctl.input_control(cfg(), {"action": "click", "x": -10, "y": 100})
