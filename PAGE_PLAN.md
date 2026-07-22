# Kauzariyya Musabaqa - Upcoming Page Development Blueprint

This document details the development specifications and security mapping for each of the upcoming coordinator pages in the event space.

---

## 1. ID Cards & Chest Numbers Page
* **File Target**: `admin/event/id-cards.php`
* **Mapped Authority**: `members-info` (Members Info Viewer)
* **Target Role**: `Members Info Manager`

### Implementation Plan
1. **Authentication Block**: Add the `members-info` authority check at the top.
2. **Database Queries**: Fetch student details joining `users`, `classes`, and `maddhabs` tables.
3. **Printable Chest Numbers Layout**:
   - Render student details (Name, Class, chest number, barcode placeholders).
   - Use CSS `@media print` rules to hide headers, sidebars, and wrapper grids, printing only the ID cards and chest numbers on clean card layouts.

---

## 2. Program Entries Assignment Page
* **File Target**: `admin/event/program-entries.php`
* **Mapped Authority**: `assign-entries` (Entries Assigner)
* **Target Role**: `Entries Assigner`

### Implementation Plan
1. **Authentication Block**: Check for the `assign-entries` authority.
2. **Dynamic Mappings**:
   - Manage entries database query.
   - Create pivot lists mapping `student_id` to event `program_id`.
   - Provide a search-and-select autocomplete interface (or dynamic table checkboxes) to assign students to entries.
3. **Validation**: Check that students do not exceed the maximum allowed category entries.

---

## 3. Judges Marks Uploading Page
* **File Target**: `admin/event/upload-scores.php`
* **Mapped Authority**: `upload-scores` (Score Uploader)
* **Target Role**: `Score Uploader`

### Implementation Plan
1. **Authentication Block**: Check for the `upload-scores` authority.
2. **Score Entry Interface**:
   - Select active program/competition from a dropdown.
   - Load the list of assigned student participants for that program.
   - Render numeric input fields to upload marks for each criteria (e.g. Presentation, Quality, Style).
3. **Validation**: Prevent duplicate entries, auto-compute total marks on-the-fly using JavaScript.

---

## 4. TV Scoreboard Control Page
* **File Target**: `admin/event/control-tv.php`
* **Mapped Authority**: `control-tv` (TV Controller)
* **Target Role**: `TV Controller`

### Implementation Plan
1. **Authentication Block**: Check for the `control-tv` authority.
2. **Live Feed Panel**:
   - Panel displaying active programs, live rankings, and scores.
   - Action controls: "Show Scoreboard", "Show Current Standings", "Publish Results", "Highlight Winner".
3. **Real-time Synchronization**: Save current TV feed settings inside the `settings` table, which is polled by the live screen viewer page (`tv-screen.php`) to update display overlays dynamically.
