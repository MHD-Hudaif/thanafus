# Al-Thanafus Score Approval Reveal

## What is included
- `index.php` — fullscreen cinematic reveal screen
- `api/bootstrap.php` — current snapshot
- `api/state.php` — snapshot + queued batches for fallback polling
- `api/stream.php` — Server-Sent Events approval stream
- `lib/db.php` — PDO and shared helpers
- `lib/reveal.php` — all database queries and calculations
- `assets/style.css` — cinematic emerald glassmorphism styling
- `assets/app.js` — render logic, animations, SSE, fallback polling

## Setup
1. Place the files in your PHP web root.
2. Set database credentials with environment variables if needed:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
3. Open `index.php` in the browser.

## Data model used
- Active event is detected from `musabaqa_events`
- Teams come from `musabaqa_teams`
- Programs come from `musabaqa_programs`
- Approval triggers come from `musabaqa_activity_logs`
- Results are resolved from:
  - `musabaqa_program_entries`
  - `musabaqa_member_scores`
  - `musabaqa_scores`
  - `musabaqa_score_sheets`
- Manual scoreboard fallback uses `musabaqa_manual_scoreboard`

## Notes
- No new database tables were added.
- Live updates use SSE first, then polling fallback.
- Team colors are read from the database and drive glow/particle styling.
