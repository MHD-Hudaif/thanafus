-- Performance Index Optimizations for Musabaqa Database

-- musabaqa_scores lookup optimization
ALTER TABLE musabaqa_scores
    ADD INDEX idx_event_status_program (event_id, status, program_id);

-- musabaqa_program_entries lookup optimization
ALTER TABLE musabaqa_program_entries
    ADD INDEX idx_event_program_team (event_id, program_id, team_id);

-- musabaqa_entry_members lookup optimization
ALTER TABLE musabaqa_entry_members
    ADD INDEX idx_entry_team_member (entry_id, team_member_id);

-- musabaqa_team_members lookup optimization
ALTER TABLE musabaqa_team_members
    ADD INDEX idx_event_student_team (event_id, student_id, team_id);
