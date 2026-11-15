"""Pixel arithmetic between a captured image and the real screen.  Everything is derived from the ACTUAL image size, never from an assumed scale:
if the screenshot is 1.5x bigger (or smaller) than the screen rectangle it covers, positions are still mapped back correctly.  Negative monitor origins are fine."""
from __future__ import annotations

from dataclasses import dataclass

Box = tuple[int, int, int, int]


@dataclass(frozen=True)
class Shot:
    origin: tuple[int, int]            # absolute screen position of the image's top-left pixel
    region: tuple[int, int]            # width, height of the screen area covered (screen pixels)
    image: tuple[int, int]             # width, height of the PNG in pixels

    @property
    def sx(self) -> float:
        return self.region[0] / self.image[0]

    @property
    def sy(self) -> float:
        return self.region[1] / self.image[1]


def to_screen(box: Box, shot: Shot) -> Box:
    return (round(shot.origin[0] + box[0] * shot.sx), round(shot.origin[1] + box[1] * shot.sy),
            round(shot.origin[0] + box[2] * shot.sx), round(shot.origin[1] + box[3] * shot.sy))


def center(box: Box) -> tuple[int, int]:
    return (box[0] + box[2]) // 2, (box[1] + box[3]) // 2


def inside(pt: tuple[int, int], box: Box) -> bool:
    return box[0] <= pt[0] < box[2] and box[1] <= pt[1] < box[3]


def iou(a: Box, b: Box) -> float:
    w = min(a[2], b[2]) - max(a[0], b[0])
    h = min(a[3], b[3]) - max(a[1], b[1])
    if w <= 0 or h <= 0:
        return 0.0
    inter = w * h
    return inter / ((a[2] - a[0]) * (a[3] - a[1]) + (b[2] - b[0]) * (b[3] - b[1]) - inter)


def monitor_of(pt: tuple[int, int], monitors: list[dict]) -> dict | None:
    return next((m for m in monitors if inside(pt, tuple(m["rect"]))), None)
