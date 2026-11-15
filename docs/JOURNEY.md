# Journey (v0.114.0)

`/app/journey`: read-only timeline of memories (`memories`), skill versions (`skill_versions`, including proposals waiting for approval) and scheduled jobs,
with kind filter and search, a list of what is sent to the AI in every chat (`MemoryRepo::forChat`), and downloads of memories + active skills as Markdown or JSON.
Logic is pure and tested in `tests/Unit/JourneyBuilderTest.php` (`App\Journey\JourneyBuilder`). No migration.
